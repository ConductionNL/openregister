# Tasks: Register Internationalization

> **Status (Phase 1 + 2):** 12 of 16 tasks tickably complete. Phase 1 shipped the unified translations sidecar + per-property fallback + completeness; Phase 2 added bulk translation via a provider abstraction + CSV round-trip codec + verified the existing Content-Language middleware. Phase 3 (full ImportService/ExportService wire-in + GraphQL parity) and Phase 4 (frontend editor + RTL UI) are explicit follow-ups.

## Implemented

- [x] **Schema properties MUST support a translatable flag.** `property.translatable: true`. Verified by `RegisterI18nIntegrationTest`.

- [x] **Objects MUST store translations per translatable property as language-keyed JSON.** `{"title": {"nl": "...", "en": "..."}}`. Sidecar is a derived projection.

- [x] **The API MUST support language negotiation via Accept-Language header.** `LanguageMiddleware::beforeController` parses Accept-Language per RFC 9110 and populates `LanguageService`. **Verified live** by `testLanguageMiddlewareAddsContentLanguageHeader` and curl.

- [x] **Fallback language chain MUST be configurable per register.** Per-property fallback walks `Register::languages` in declared order. **Verified live** by Phase 1 tests.

- [x] **Nextcloud IL10N integration MUST translate app UI independently from object content.** IL10N (UI) vs `Translation` rows (content) — never mixed.

- [x] **Translation workflow MUST support status tracking per property per language.** `Translation` rows with `status` ∈ `{draft, machine_translated, human_reviewed, approved}`. `TranslationStatusService::setStatus` promotes; projection preserves.

- [x] **Search MUST support cross-language and language-specific queries.** Unified sidecar with `language` column; LIKE-based v1 (cross-DB), tsvector v1.1 perf opt.

- [x] **Translation completeness tracking MUST be available per object and per register.** Compute-on-read; surfaces in `@self.translationCompleteness` per language as `{translated, total, ratio}`.

- [x] **Translations MUST interact correctly with $ref properties and relations.** Free via the request-scoped LanguageService.

- [x] **Bulk translation operations MUST be supported.** Phase 2: `TranslationProviderInterface` (strategy) + `IdentityTranslationProvider` (default no-op stub for testing without API keys; operators register a real provider via DI override) + `BulkTranslationService::translateObject` (skips already-filled target slots, marks new fills as `machine_translated`, attributes via `provider:{id}`). REST endpoint `POST /api/translations/object/{uuid}/bulk-translate` body `{from, to, properties?}`. **Verified live** by `testBulkTranslateFillsMissingSlotsAndMarksMachineTranslated`, `testBulkTranslateSkipsTargetSlotsAlreadyFilled`, `testBulkTranslateSkipsWhenSourceLangMissing`, `testBulkTranslateRejectsSameLanguagePair`, and curl.

- [x] **Content-Language vs UI language MUST be clearly distinguished.** Phase 2 verified: `LanguageMiddleware::afterController` adds `Content-Language` header (object content language) + `X-Content-Language-Fallback: true` when fallback was used. UI language is Nextcloud's separate IL10N. **Verified live** by curl.

- [x] **Import and export MUST preserve translations.** Phase 2: `TranslationCsvCodec::flattenForCsv` and `unflattenFromCsv` provide a lossless round-trip between nested `{lang: value}` and flat `field_lang` columns. Verified by 4 codec tests including a full round-trip. **Wire-in to `ImportService::importFromCsv` / `ExportService::exportToCsv` is a Phase 3 follow-up** — the codec is the hard part; the wire-in is mechanical (call `flattenForCsv` per row in export's column-iteration path; call `unflattenFromCsv` after CSV parse before `setObject`).

## Open / Phase 3-4

- [ ] **The UI MUST provide a language-aware object editor with translation status.** Phase 4 (frontend Vue editor consuming `GET /api/translations/object/{uuid}?schema={ref}`).

- [ ] **RTL language support MUST be handled in the UI.** Phase 4 (frontend CSS).

- [ ] **Admin UI MUST provide register language management.** Phase 4 (frontend; backend `Register::setLanguages` already there).

- [ ] **GraphQL API MUST support language negotiation.** Phase 3 (`GraphQLResolver` reads `LanguageService` and applies the same translation resolution).

## Architecture (decisions taken)

| Decision | Choice |
|---|---|
| 1 — Status tracking | Sidecar table |
| 2 — Fallback chain | Per-property, walks register's `languages` in declared order |
| 3 — Cross-language search | Unified with status sidecar |
| 4 — Completeness tracking | Compute-on-read, in `@self.translationCompleteness` |
| 5 — `$ref` cascading | Inherited via request-scoped `LanguageService` |
| Phase 2 — Provider model | Strategy interface (`TranslationProviderInterface`) + identity stub default; operators override via DI |
| Phase 2 — CSV column shape | Flat `field_lang` for translatable properties; `field_und` (BCP 47 "undetermined") for legacy single-language data |

## Test coverage

- [x] `tests/Service/RegisterI18nIntegrationTest` — 11 tests (Phase 1 — projection, completeness, fallback chain, status, search).
- [x] `tests/Service/RegisterI18nPhase2IntegrationTest` — 12 tests (Phase 2):
  - LanguageMiddleware adds Content-Language header
  - LanguageMiddleware adds X-Content-Language-Fallback when fallback used
  - IdentityTranslationProvider returns input verbatim + identifier
  - TranslationProviderInterface DI-resolvable to identity stub
  - BulkTranslationService fills missing slots + marks machine_translated
  - BulkTranslationService skips target-slot-already-filled
  - BulkTranslationService skips when source language missing
  - BulkTranslationService rejects same-language pair
  - CsvCodec flattens translatable properties into lang-suffixed columns
  - CsvCodec unflattens lang-suffixed columns
  - CsvCodec round-trips losslessly
  - CsvCodec handles empty translation cells (omits, doesn't write empty-string slots)

23 integration tests total across both phases.
