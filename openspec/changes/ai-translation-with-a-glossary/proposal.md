---
kind: code
---

# Proposal: ai-translation-with-a-glossary

## Summary

An administrator opens a record, chooses "Translate", picks a source and a target language, and gets the record's translatable fields filled by the AI translation provider configured in Nextcloud. The translation follows a shared glossary, so "Omgevingsvergunning" always becomes the agreed English term and a product name is left alone, and a style guide per language, such as formal address. A result that misses a glossary term is saved as a draft for a person to check, not as a finished machine translation. Open Register never sends a field that is encrypted at rest, and sends nothing until an administrator switches AI translation on.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | ai-translate | Have AI translate a record's text fields into other languages, following a shared glossary and style guide. | no |

**ai-translate** (openregister's matrix)

- Demand: changelog, https://github.com/directus/directus/releases/tag/v12.0.0 (the row's origin).
- Competitor yes cells: none in the packet.
- The row was marked `specified` with no change directory; this is that change.

## Why

The translation seam exists and does nothing.

- `POST /api/translations/object/{uuid}/bulk-translate` (`appinfo/routes.php:573`) calls `BulkTranslationService::translateObject()` (`lib/Service/BulkTranslationService.php:94-203`), which asks the bound `TranslationProviderInterface` (`lib/Service/Translation/TranslationProviderInterface.php`) for each empty target slot and stores the result as `machine_translated` (`:181-188`).
- The only binding is `IdentityTranslationProvider`, which returns the source text (`lib/AppInfo/Application.php:660-667`). The comment says "Operators replace this binding", and nothing in the fleet does. `ConnectionSeamReportJob` reports it as `simulated` (`lib/BackgroundJob/ConnectionSeamReportJob.php:107-123`).
- There is no glossary or style guide: `lib/Service/Translation/` holds the interface, the identity provider and a CSV codec, and nothing reads a term list.
- `src/dialogs/i18n/BulkTranslateDialog.vue` exists and is tested, but no component in `src/` opens it: a search for `BulkTranslateDialog` outside the file finds only its own spec.

## What changes

- A `TaskProcessingTranslationProvider` behind the existing interface, using Nextcloud's Task Processing (`core:text2text:translate` when no glossary or style guide applies, `core:text2text` with an instructed prompt when one does), so the administrator's own Nextcloud AI setup does the work, local or remote.
- A glossary and a style guide kept as Open Register objects in a new register, seeded with example entries: terms per language pair with their required translation or "do not translate", and one style guide per target language.
- After each translation the provider checks that every matched glossary term came out as agreed. A miss saves the slot as `draft` and names the missing terms.
- An admin setting switches AI translation on and picks the provider; off, the identity provider stays bound. Fields flagged `x-openregister-encrypted` are never sent.
- A "Translate" action on the object page opens the existing dialog, and the dialog lists what was translated, drafted and skipped.
- The connection registry reports the provider actually in use.

## Consumers

- Every fleet app with translatable schema properties, through Open Register's object page and API. The row is Open Register's own.

## ADRs

- hydra ADR-034 (AI chat companion, amendment 2026-07-05): Open Register does not grow its own LLM provider layer; this change uses Nextcloud's Task Processing, which the administrator configures, instead of Open Register's chat plumbing that `or-chat-engine-decommission` is retiring.
- hydra ADR-070 (OR-backed persistence) and ADR-001 (data layer, seed data): the glossary and style guide are Open Register objects with seed rows.
- openregister ADR-005 (register import via repair steps): the new register ships with a repair step.
- hydra ADR-005 (security): off by default, encrypted fields never leave, and the setting names the provider text goes to.
- hydra ADR-004 (frontend): the dialog stays in `src/dialogs/`, opened through the dialog host.
- hydra ADR-007 and ADR-025 (i18n): the dialog's hard-coded English strings move to `t()` while the file is being edited.

## Impact

- Extends the capability `register-i18n` (its requirement "Machine translation MUST fill empty slots through a pluggable provider").
- Affected code: new `lib/Service/Translation/TaskProcessingTranslationProvider.php`, `GlossaryService.php`, `TranslationProviderResolver.php`; `lib/AppInfo/Application.php` (the binding at `:660-667`); `lib/Service/BulkTranslationService.php` (the status of a glossary miss); `lib/BackgroundJob/ConnectionSeamReportJob.php`; new `lib/Settings/translation_glossary_register.json` and `lib/Repair/ImportTranslationGlossaryRegister.php`; the admin settings; `src/views/object/ObjectDetails.vue`; `src/dialogs/Dialogs.vue`; `src/dialogs/i18n/BulkTranslateDialog.vue`.
- Backwards compatible. With the setting off, the identity provider is bound as today.
- Size: M.

## Out of scope

- Translating many records in one action. That is a bulk action on `bulk-action-jobs`, later.
- Translating the app's own interface strings. Those follow hydra ADR-025 and Nextcloud's l10n.
- Opening bulk translation to non-administrators. `bulkTranslate` stays administrator-only as it is today (`lib/Controller/TranslationController.php:236-252`, no `@NoAdminRequired`).
