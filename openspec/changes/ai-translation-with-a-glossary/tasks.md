# Tasks: ai-translation-with-a-glossary

## 1. Glossary register

- [ ] 1.1 Add `lib/Settings/translation_glossary_register.json` with register `translation-glossary`, schemas `translation-term` and `translation-style-guide` (design D-3, D-4, with the refuse uniqueness constraint), the example seed rows (Seed data), and `lib/Repair/ImportTranslationGlossaryRegister.php` registered in `appinfo/info.xml`. Verify: `tests/Unit/Repair/ImportTranslationGlossaryRegisterTest.php` imports twice and finds no duplicates; `node tests/validate-register.js lib/Settings/translation_glossary_register.json` passes; the seed-data linter passes.
- [ ] 1.2 Add `GlossaryService::entriesFor()` and `styleGuideFor()`: one load per language pair per call, word-boundary matching with Unicode case folding, register-scoped plus global terms, longest term first, at most 50. Verify: `tests/Unit/Service/Translation/GlossaryServiceTest.php` covers case folding, a case-sensitive term, a register-scoped term not applied to another register, and the overlapping-term order.

## 2. Provider

- [ ] 2.1 Add `TaskProcessingTranslationProvider` with `translate()` and `translateDetailed()`: task type choice and the fenced prompt (design D-2), `runTask()` as the acting user, null when no task type is available, and the glossary check (D-5). Verify: `tests/Unit/Service/Translation/TaskProcessingTranslationProviderTest.php` with a mocked `IManager` asserts the translate type without glossary, the text2text type with one, the prompt fencing, and `glossary-terms-missing` on a missed term.
- [ ] 2.2 Add `TranslationProviderResolver` and replace the binding at `lib/AppInfo/Application.php:660-667`; skip `x-openregister-encrypted` properties with `encrypted-at-rest` and store a glossary miss as `draft` in `BulkTranslationService`; report the bound provider in `ConnectionSeamReportJob`. Verify: `tests/Unit/Service/BulkTranslationServiceGlossaryTest.php` and `tests/Unit/BackgroundJob/ConnectionSeamReportJobTest.php`; with both settings at their defaults the identity provider is bound.

## 3. Settings and page

- [ ] 3.1 Add the "Translation" section to the Open Register admin settings: provider choice, the Task Processing providers Nextcloud reports for the two task types with local or remote, and the confirmation that record text is sent. Verify: `src/views/settings/sections/TranslationConfiguration.spec.js`; saving without the confirmation keeps `translation_allow_external` false.
- [ ] 3.2 Add the "Translate" action to `src/views/object/ObjectDetails.vue` for administrators on a schema with a translatable property in a multi-language register, open `BulkTranslateDialog` through `src/dialogs/Dialogs.vue`, persist the returned map and reload; move the dialog's strings to `t()` and list drafted fields with their missing terms. Verify: `src/dialogs/i18n/BulkTranslateDialog.spec.js` extended for the drafted list; `src/views/object/ObjectDetails.spec.js` asserts the action is hidden for a single-language register.

## 4. Docs and end-to-end test

- [ ] 4.1 Document enabling AI translation, the glossary and style guide, the draft-on-miss rule and the encrypted-field rule in `docs/i18n.md`. Verify: `npm run build` in `docs/` succeeds.
- [ ] 4.2 Add `tests/e2e/ci/ai-translation-glossary.spec.ts`: with Nextcloud's `testing` app enabled in the CI instance for its fake `FakeTranslateProvider` and `FakeTextToTextProvider`, an administrator enables AI translation, translates a record from `nl` to `en` from the object page, and sees the fields filled and the glossary term present (the fake text2text provider echoes its prompt, which carries the term). The draft-on-miss rule cannot be produced by the fake provider and is proven by the unit tests of 2.1 and 2.2. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- With the defaults, no record text leaves the instance.
- No slot whose output missed a matched glossary term is stored as `machine_translated`.
