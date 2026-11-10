# Design: ai-translation-with-a-glossary

Read at openregister development c53dd0685c.

## D-1: the provider is Nextcloud's Task Processing, not Open Register's LLM plumbing

Open Register has an LLM stack for chat (`lib/Service/Chat/ResponseGenerationHandler.php`, LLPhant), but hydra ADR-034's amendment moved LLM provider selection to Hermiq, and `or-chat-engine-decommission` is retiring Open Register's chat engine. Building translation on it would tie a new feature to code on its way out.

Nextcloud's Task Processing (`OCP\TaskProcessing\IManager`, available from Nextcloud 30; Open Register requires 32, `appinfo/info.xml:129`) is where an administrator already chooses their AI: a local model, a DeepL integration, an OpenAI integration. Two of its task types fit:

- `core:text2text:translate` (`TextToTextTranslate`, inputs `input`, `origin_language`, `target_language`) for plain machine translation;
- `core:text2text` (`TextToText`, input `input`) for a prompt that carries glossary terms and a style guide.

`lib/Service/Translation/TaskProcessingTranslationProvider.php` implements `TranslationProviderInterface` (`translate()` and `getIdentifier()`, identifier `taskprocessing`). It builds a `Task`, runs it with `IManager::runTask()` as the acting user, and returns the `output`. A task type that is not available (`getAvailableTaskTypes()`) makes `translate()` return null, which `BulkTranslationService` already records as `provider-returned-empty` (`lib/Service/BulkTranslationService.php:170-173`).

## D-2: which task type, per field

For each field `GlossaryService` finds the glossary entries that apply (D-3) and the style guide for the target language (D-4).

- No matched entries and no style guide: `core:text2text:translate`. A dedicated translation model is better at plain translation than a general prompt.
- Otherwise: `core:text2text` with this prompt, built only from administered data:

```
Translate the text between <text> tags from {from} to {to}.
Use exactly these translations for these terms: "{term}" -> "{translation}", ...
Leave these terms untranslated: "{term}", ...
Style: {formality}. {instructions}
Return only the translation, without the tags and without comments.
<text>{source}</text>
```

The source text is placed last and fenced, so an instruction inside a record's text is data, not a command. If the text2text type is not available but the translate type is, the translate type is used and the glossary check (D-5) decides the status.

## D-3: the glossary

A glossary term is an Open Register object of schema `translation-term` in a new register `translation-glossary`:

| property | type | notes |
|---|---|---|
| `term` | string, required | the source term as it appears in text |
| `sourceLanguage` | string, required | BCP 47, for example `nl` |
| `targetLanguage` | string, required | BCP 47, for example `en` |
| `translation` | string | required unless `doNotTranslate` |
| `doNotTranslate` | boolean | a product or proper name kept as is |
| `caseSensitive` | boolean | default false |
| `register` | string | optional; limits the term to one register |
| `note` | string | why this translation, for the people maintaining it |

`GlossaryService::entriesFor(text, from, to, register)` loads the terms for the language pair once per bulk call (the pair's terms, the register's plus the global ones), matches them against the source on word boundaries with Unicode case folding unless `caseSensitive`, and returns at most 50 matched entries, longest term first so "omgevingsvergunning beperkte milieutoets" wins over "omgevingsvergunning". A uniqueness constraint on `term`, `sourceLanguage`, `targetLanguage` and `register` (the existing `configuration.uniqueConstraints`, action `refuse`) stops two contradicting entries.

## D-4: the style guide

A style guide is an object of schema `translation-style-guide` in the same register: `language` (required, unique), `formality` (`formal` or `informal`), `instructions` (at most 2,000 characters). One per target language. Being objects, both schemas get Open Register's audit trail, RBAC and the generic editor for free; the register's authorization lets administrators and a `translation-editors` group write, and everyone signed in read.

## D-5: the glossary is checked, not trusted

A language model can ignore an instruction. After each translation `TaskProcessingTranslationProvider` checks every matched entry: the required `translation`, or for `doNotTranslate` the term itself, must occur in the output, compared with Unicode case folding. Any miss is reported back as `glossary-terms-missing: {terms}`.

`BulkTranslationService` stores such a slot with status `draft` instead of `machine_translated` (the statuses in `lib/Db/Translation.php:57-61`) and adds the reason to the result's `skipped` map under the property, so the dialog shows "drafted, check: Omgevingsvergunning". A human then promotes it through the existing `POST /api/translations/object/{uuid}/{property}/{language}/status` (`appinfo/routes.php:572`). To carry that reason the provider returns a small result object from a new `translateDetailed()` method; `translate()` keeps its contract for every other caller.

## D-6: switching it on, and what never leaves

`lib/Service/Translation/TranslationProviderResolver.php` replaces the fixed binding at `lib/AppInfo/Application.php:660-667` with a factory that reads two `IAppConfig` keys: `translation_provider` (`identity` by default, or `taskprocessing`) and `translation_allow_external` (default false). The "Translation" section of the Open Register admin settings shows the Task Processing providers Nextcloud reports for the two task types and whether each is local, and asks the administrator to confirm that record text will be sent to it. Until both keys say so, the identity provider stays bound.

Whatever the setting, `BulkTranslationService` never passes a property flagged `x-openregister-encrypted` (`lib/Service/FieldEncryptionHandler.php:7`) to a provider; it skips it with reason `encrypted-at-rest`. A field protected at rest is not sent to a model.

`ConnectionSeamReportJob::describeTranslation()` (`lib/BackgroundJob/ConnectionSeamReportJob.php:107-123`) reports `configured` with the Task Processing provider's name when the resolver binds it, and keeps `simulated` for the identity provider.

## D-7: the opener

`src/views/object/ObjectDetails.vue` has an "Actions" menu (`:11-63`). A "Translate" action is added, shown to administrators when the object's schema has at least one `translatable` property and its register lists more than one language (`Register::$languages`, `lib/Db/Register.php:274`). It opens `BulkTranslateDialog` through the dialog host (`src/dialogs/Dialogs.vue`) with the object's uuid and the register's languages. After a successful run the dialog emits `translated`; the object page persists the returned `translated` map onto the object as the controller's contract asks (`lib/Controller/TranslationController.php:236-239`) and reloads. The dialog's hard-coded English strings ("Bulk translate", "From language", and the rest) move to `t('openregister', ...)`, and it lists drafted fields with their missing terms.

## Seed data

`lib/Settings/translation_glossary_register.json` defines the register `translation-glossary` and the schemas `translation-term` and `translation-style-guide`, imported by `lib/Repair/ImportTranslationGlossaryRegister.php` and registered in `appinfo/info.xml` beside the other import steps (for example `ImportSurveyRegister` at `appinfo/info.xml:234`), per openregister ADR-005. Per hydra ADR-001 each schema ships three to five example objects, marked as seed data: slugs start with `example-`, and a `note` or `instructions` field opens with the seed banner the ADR prescribes. Examples: `nl` to `en` terms for "omgevingsvergunning" (environmental permit), "Wet open overheid" (Open Government Act), "gemeente" (municipality), a `doNotTranslate` entry for "DigiD", and style guides for `en` (formal) and `de` (formal, "Sie"). They are examples to replace, not an authoritative glossary.

## Declarative-vs-imperative decision

Declarative for the rules: the glossary and style guide are data an editor maintains, and the translatable flag and the encryption flag are schema declarations already in place. Imperative for the call: choosing a task type, building the prompt and checking the output are one service's steps.

## Risks

- **Data protection.** Off by default; the administrator confirms the provider text is sent to; encrypted fields never leave; the prompt fences record text so it cannot instruct the model.
- **Quality.** The glossary check (D-5) turns a silent terminology miss into a draft a person sees.
- **Performance.** One Task Processing run per field. The bulk call already translates one object at a time; the glossary is loaded once per call and matching is in memory.
- **Availability.** A missing task type answers `provider-returned-empty` per field instead of failing the call, as the service already does.
