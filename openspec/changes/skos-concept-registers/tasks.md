# Tasks: skos-concept-registers

- [ ] Freeze delta spec `specs/skos-concept-registers/spec.md` (ADDED SKOS-001..004); `openspec validate skos-concept-registers` green
- [ ] Vocabulary register definition under `lib/Settings` with `conceptScheme` + `concept` schemas: required/unique `uri`, multilingual `prefLabel`/`altLabel` maps, canonical-dialect relations (`inScheme`, `broader`, `narrower`, `related`), English `title`+`description` on every property, public read / admin-gated write
  - Spec ref: SKOS-001
  - Acceptance: register imports clean on a fresh instance; relation-dialect (gate 31) + schema-property-titles (gate 28) green
- [ ] `VocabularyImportService`: URI-keyed idempotent upsert from JSON-LD SKOS + CSV value-list; created/updated/unchanged report; deprecation (never deletion) of concepts missing from a re-imported source; both `broader`/`narrower` directions maintained
  - Spec ref: SKOS-002
  - Acceptance: PHPUnit — double-import no-op, label-change update-in-place, removed-concept deprecation
- [ ] Bundle TOOI fixtures (informatiecategorieën ×17, documentsoorten, organisatie scheme ids) + repair-step seeding through the importer
  - Spec ref: SKOS-003
  - Acceptance: PHPUnit — fresh seed serves all 17 categories with TOOI URIs; repeated repair run is a no-op
- [ ] Resolution endpoints (public read): by exact URI, by (scheme, notation), scheme concept listing with paginated multilingual label search; 404 with standard error shape on unknown URI
  - Spec ref: SKOS-004
  - Acceptance: PHPUnit controller tests incl. 404 path; route-auth gate green
- [ ] `@spec` tags on all new methods; hydra gates green locally; file follow-up leaf issues (opencatalogi Tooi/DcatVocabularyService consume-change, softwarecatalog GEMMA, decidesk ORI)
