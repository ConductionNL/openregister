# Tasks: search-accent-insensitive

## 1. Folding

- [ ] 1.1 Migration creating `unaccent` and `openregister_fold()` on PostgreSQL, no-op elsewhere, degrading on failure. Verify: migration test on PostgreSQL and MariaDB in the CI matrix.
- [ ] 1.2 `SearchFolding` for PostgreSQL, MariaDB and exact mode. Verify: `tests/Unit/Db/MagicMapper/SearchFoldingTest.php` asserting the SQL per platform and mode.
- [ ] 1.3 `columnMatchSql()`, `applyFullTextSearch()`, the fuzzy path and `MagicFacetHandler` on the helper. Verify: integration test on both databases: `cafe` finds `Café`, `reunie` finds `reünie`, and the facet count equals the result count.
- [ ] 1.4 Searchable-property indexes on the folded expression when available. Verify: `EXPLAIN` in the integration test shows the trigram index on PostgreSQL.

## 2. Setting

- [ ] 2.1 `accentInsensitive` on `GET` and `PATCH /api/settings/search-backend`, 400 when unavailable, `_accents=exact` on object search, `SearchConfiguration.vue` with texts in en and nl. Verify: `SettingsControllerTest` and a component test.

## 3. Tests and docs

- [ ] 3.1 Add `tests/e2e/ci/search-accents.spec.ts`: seed objects named "Café de Flore" and "reünie", search the object API and the index page with `cafe` and `reunie`, and with `_accents=exact`.
- [ ] 3.2 Document accent-insensitive search and the setting in `docs/features/`.

Acceptance:

- A folded search on a register of 100,000 objects is no more than 20 percent slower than the case-folded search it replaces, measured on PostgreSQL with the index.
- Facet counts and result totals agree for accented and unaccented terms.
