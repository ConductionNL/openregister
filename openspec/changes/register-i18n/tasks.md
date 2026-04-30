# Tasks: Register Internationalization

> **Status (Phase 1):** Backend foundation shipped. Unified `oc_openregister_translations` sidecar (Decision 1+3+4 from architecture pass) + per-property fallback chain (Decision 2) + `@self.translationCompleteness` (Decision 4) + `$ref` lang cascade (Decision 5, free via request-scoped LanguageService). **9 of 16 tasks tickably complete.** Frontend, GraphQL parity, RTL UI, content/UI language separation, bulk translation operations are explicit Phase 2/3 follow-ups.

## Implemented (Phase 1)

- [x] **Schema properties MUST support a translatable flag.** `property.translatable: true` detected by `TranslationHandler::getTranslatableProperties`. Existing implementation; verified by the integration test.

- [x] **Objects MUST store translations per translatable property as language-keyed JSON.** Format: `{"title": {"nl": "...", "en": "..."}}`. Stored on the JSONB property; sidecar is a derived projection. Existing storage shape is preserved (no migration of existing data needed).

- [x] **The API MUST support language negotiation via Accept-Language header.** `LanguageService` reads + parses Accept-Language per RFC 9110, sorted by q-factor.

- [x] **Fallback language chain MUST be configurable per register.** `Register::languages` (JSON array) is the chain. **Verified live** by `testFallbackChainWalksRegisterLanguagesInOrder` and `testFallbackChainPicksPreferredWhenAvailable`. Per-property fallback walks the chain in declared order; missing values for one property don't force fallback on others (Decision 2).

- [x] **Nextcloud IL10N integration MUST translate app UI independently from object content.** IL10N handles UI strings; OpenRegister's content translations live separately in `Translation` rows + JSONB. The two never mix.

- [x] **Translation workflow MUST support status tracking per property per language.** `Translation::status` ∈ `{draft, machine_translated, human_reviewed, approved}` with `translator` uid. `TranslationStatusService::setStatus` promotes; the projection preserves status on re-projection (verified by `testStatusPromotionPersistsAcrossRereads`).

- [x] **Search MUST support cross-language and language-specific queries.** Unified sidecar with `language` column + `LOWER(value) LIKE` index (Decision 3 → option A from the new options table after the sidecar choice). **Verified live** by `testCrossLanguageContentSearchSpansAllLanguages` and `testLanguageSpecificContentSearchNarrowsToOneLanguage`. tsvector + per-language Postgres FTS config is a Phase 2 perf optimisation; the v1 LIKE-based path works on Postgres + MariaDB.

- [x] **Translation completeness tracking MUST be available per object and per register.** Compute-on-read via `TranslationStatusService::completenessForObject` (Decision 4). Surfaces in `@self.translationCompleteness` per language as `{translated: int, total: int, ratio: float}`. **Verified live** by `testCompletenessReportsTranslatedTotalRatio` and `testRenderObjectAttachesCompletenessToSelfEnvelope`.

- [x] **Translations MUST interact correctly with $ref properties and relations.** Free via Decision 5 → option B (request-scoped LanguageService). Embedded objects re-enter `RenderObject::renderEntity` which calls `TranslationHandler::resolveTranslationsForRender` against the same LanguageService instance, so the parent's resolved language cascades automatically.

## Open / Phase 2+

- [ ] **The UI MUST provide a language-aware object editor with translation status.** Backend metadata is exposed via `GET /api/translations/object/{uuid}?schema={ref}` returning `{translations, completeness}`; the frontend Vue editor consumes that. Frontend work — Phase 4.

- [ ] **Bulk translation operations MUST be supported.** Not implemented. Needs a translation provider abstraction + concrete provider gated behind config. Phase 3.

- [ ] **Import and export MUST preserve translations.** Not yet wired into ImportService/ExportService. CSV column shape decision: `field_lang` per language (`title_nl`, `title_en`) for round-trip. Phase 3.

- [ ] **RTL language support MUST be handled in the UI.** Frontend CSS — not OR's responsibility. Phase 4.

- [ ] **Content-Language vs UI language MUST be clearly distinguished.** `Content-Language` response header derived from the rendered object's effective language is not yet emitted. Additive — Phase 2.

- [ ] **Admin UI MUST provide register language management.** Backend already supports `Register::setLanguages`; frontend management UI is Phase 4.

- [ ] **GraphQL API MUST support language negotiation.** Not yet wired through `GraphQLResolver`. Phase 3.

## Architecture (decisions taken)

| Decision | Choice |
|---|---|
| 1 — Status tracking | Sidecar table (Translation + TranslationMapper) |
| 2 — Fallback chain | Per-property, walks register's `languages` in declared order, last resort = any available variant |
| 3 — Cross-language search | Unified with the status sidecar (`oc_openregister_translations` carries denormalised value + per-row language); LIKE-based v1 (cross-DB), tsvector v1.1 perf opt |
| 4 — Completeness tracking | Compute-on-read via `TranslationStatusService::completenessForObject`; surfaces in `@self.translationCompleteness` |
| 5 — `$ref` cascading | Inherited via request-scoped `LanguageService` (free) |

## Test coverage

`tests/Service/RegisterI18nIntegrationTest` — 11 integration tests:
- Projection upserts one row per (property, language)
- Projection deletes stale slots when a language is removed
- Projection purge on object delete removes every slot
- Completeness reports translated/total/ratio per language
- RenderObject attaches `@self.translationCompleteness`
- Fallback chain picks preferred when available
- Fallback chain walks register `languages` when preferred missing (sets fallback flag)
- Status promotion persists across re-projections (projection doesn't second-guess status)
- Cross-language content search spans every language
- Language-specific search narrows to one language
- `findObjectsMissingLanguage` returns candidates lacking translation
