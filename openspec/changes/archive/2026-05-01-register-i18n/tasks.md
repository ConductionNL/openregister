# Tasks: Register Internationalization

> **Status (Phase 1 + 2 + 3 + 4):** All 16 tasks complete. Phase 1 shipped the unified translations sidecar + per-property fallback + completeness; Phase 2 added bulk translation via a provider abstraction + CSV codec + Content-Language verification; Phase 3 wired ImportService/ExportService for translatable column round-trip and added GraphQL parity. Phase 4a delivered the frontend editor (`TranslationFieldEditor`), RTL direction handling, status chip + completeness badge, bulk-translate dialog, and a Pinia store wrapping the REST API. Phase 4b shipped the admin `RegisterLanguagesEditor` (ordered BCP 47 list with reorder/remove/add) wired into the register edit form.

## Implemented

- [x] **Schema properties MUST support a translatable flag.** `property.translatable: true`.
- [x] **Objects MUST store translations per translatable property as language-keyed JSON.** `{"title": {"nl": "...", "en": "..."}}`.
- [x] **The API MUST support language negotiation via Accept-Language header.** `LanguageMiddleware` parses per RFC 9110.
- [x] **Fallback language chain MUST be configurable per register.** Per-property fallback walks `Register::languages` in declared order.
- [x] **Nextcloud IL10N integration MUST translate app UI independently from object content.** IL10N (UI) vs `Translation` rows (content).
- [x] **Translation workflow MUST support status tracking per property per language.** `Translation` rows with `status` ∈ `{draft, machine_translated, human_reviewed, approved}`.
- [x] **Search MUST support cross-language and language-specific queries.** Unified sidecar; LIKE-based v1, tsvector v1.1.
- [x] **Translation completeness tracking MUST be available per object and per register.** Compute-on-read; surfaces in `@self.translationCompleteness`.
- [x] **Translations MUST interact correctly with $ref properties and relations.** Free via the request-scoped LanguageService.
- [x] **Bulk translation operations MUST be supported.** Phase 2: `TranslationProviderInterface` + `BulkTranslationService` + REST endpoint `POST /api/translations/object/{uuid}/bulk-translate`.
- [x] **Content-Language vs UI language MUST be clearly distinguished.** Phase 2: `LanguageMiddleware` adds `Content-Language` header (object content language) + `X-Content-Language-Fallback`.
- [x] **Import and export MUST preserve translations.** Phase 3: `ExportService::exportToCsv` emits `field_lang` columns for each translatable property × register language (fallback `[nl, en]` when register has no `languages` config). `ImportService::importFromCsv::transformCsvRowToObject` calls `TranslationCsvCodec::unflattenFromCsv` before the per-key processing loop, so flat `field_lang` columns become nested `{lang: value}` JSON. **Verified live** by 4 export tests + 2 codec round-trip tests in `RegisterI18nPhase3IntegrationTest`.
- [x] **GraphQL API MUST support language negotiation.** Phase 3: `GraphQLResolver::filterProperties` now applies `TranslationHandler::resolveTranslationsForRender` after the property-level RBAC pass. GraphQL responses honour the same `LanguageService` chain that REST honours, so a query made with `Accept-Language: en` collapses language-keyed values to their EN variant. Verified by `testGraphQLResolverApplyTranslationsToFilterPropertiesOutput`.
- [x] **The UI MUST provide a language-aware object editor with translation status.** Phase 4a: Pinia store `src/store/modules/translations.js` wraps `GET /object/{uuid}`, `POST /{property}/{language}/status`, `POST /bulk-translate`, and `GET /search`. Vue components `TranslationFieldEditor` (per-language inputs/textarea with status chip), `TranslationStatusChip` (NL Design System status badge), `TranslationCompletenessBadge` (per-language ratio pills consuming `@self.translationCompleteness`), and `BulkTranslateDialog` (modal calling the bulk-translate endpoint).
- [x] **RTL language support MUST be handled in the UI.** Phase 4a: `RTL_LANGUAGES` set + `isRtlLanguage()` BCP 47 helper in the translations store; `TranslationFieldEditor.dirFor()` applies `dir="rtl"` per input/textarea for Arabic, Hebrew, Persian, Urdu, Kurdish, etc.
- [x] **Admin UI MUST provide register language management.** Phase 4b: `RegisterLanguagesEditor.vue` — ordered list editor with BCP 47 validation, duplicate rejection, up/down reorder, remove, and a "default" badge on the first language. Wired into the `RegisterSideBar` edit form via `formData.languages`. `TRegister` type + `Register` entity gained an optional `languages?: string[]` field so the form round-trips cleanly through `registerStore.saveRegister`.

## Architecture (decisions taken across all phases)

| Decision | Choice |
|---|---|
| 1 — Status tracking | Sidecar table |
| 2 — Fallback chain | Per-property, walks register's `languages` in declared order |
| 3 — Cross-language search | Unified with status sidecar |
| 4 — Completeness tracking | Compute-on-read, in `@self.translationCompleteness` |
| 5 — `$ref` cascading | Inherited via request-scoped `LanguageService` |
| Phase 2 — Provider model | Strategy interface + identity stub default |
| Phase 2 — CSV column shape | Flat `field_lang` for translatable properties; `field_und` for legacy single-language data |
| Phase 3 — Export language list | Register's `languages` config; falls back to `[nl, en]` org-wide minimum |
| Phase 3 — GraphQL i18n | Same TranslationHandler call as REST, applied alongside property RBAC in `filterProperties` |
| Phase 4a — Frontend status display | NL Design System CSS custom properties (`--utrecht-status-badge-*`) with hard-coded fallbacks; per-language ratio bucketed into complete/partial/low pills |
| Phase 4a — RTL handling | Per-input `dir="rtl"` driven by BCP 47 base-tag lookup against a frozen `RTL_LANGUAGES` set |
| Phase 4b — Admin language editor | Standalone `RegisterLanguagesEditor` (relaxed BCP 47 regex `^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$`, case-insensitive duplicate guard, splice-based reorder); first list element is the register default — no separate primary-language flag |

## Test coverage

- [x] `tests/Service/RegisterI18nIntegrationTest` — 11 tests (Phase 1).
- [x] `tests/Service/RegisterI18nPhase2IntegrationTest` — 12 tests (Phase 2).
- [x] `tests/Service/RegisterI18nPhase3IntegrationTest` — 6 tests (Phase 3):
  - Export emits `field_lang` columns for translatable properties
  - Export pulls language-specific values from JSONB slots
  - Export language list falls back to `[nl, en]` when register has no config
  - Import wire-in unflattens row data before processing (codec contract)
  - Import empty cells are omitted from translation slots
  - GraphQL resolver applies translations to filterProperties output
- [x] `src/store/modules/translations.spec.js` + `src/components/i18n/*.spec.js` — 87 Jest unit tests (Phase 4):
  - Translations store: actions (fetchByObject, setStatus, bulkTranslate, search), getters, RTL helpers, status constants
  - TranslationStatusChip: prop validator, meta map, tooltip composition
  - TranslationCompletenessBadge: language ordering, ratio bucketing, percentage rendering
  - TranslationFieldEditor: hideEmpty filter, RTL direction, empty-slot pruning on input
  - BulkTranslateDialog: canSubmit guard, watch reset on open, error fallback chain
  - RegisterLanguagesEditor (Phase 4b): BCP 47 validation, case-insensitive duplicate guard, addCurrent / remove / move semantics, error message mapping

29 backend integration tests + 87 frontend unit tests = 116 total tests across all four phases.
