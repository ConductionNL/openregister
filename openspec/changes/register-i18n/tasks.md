# Tasks: Register Internationalization

> **Status (Phase 1 + 2 + 3):** 13 of 16 tasks tickably complete. Phase 1 shipped the unified translations sidecar + per-property fallback + completeness; Phase 2 added bulk translation via a provider abstraction + CSV codec + Content-Language verification; Phase 3 wired ImportService/ExportService for translatable column round-trip and added GraphQL parity. Phase 4 (frontend editor + RTL UI + admin language management UI) is the remaining work.

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

## Open / Phase 4

- [ ] **The UI MUST provide a language-aware object editor with translation status.** Phase 4 (frontend Vue editor consuming `GET /api/translations/object/{uuid}?schema={ref}`).
- [ ] **RTL language support MUST be handled in the UI.** Phase 4 (frontend CSS).
- [ ] **Admin UI MUST provide register language management.** Phase 4 (frontend; backend `Register::setLanguages` already there).

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

29 integration tests total across all three phases.
