# Tasks: search-accent-insensitive

## 1. Folding

- [x] 1.1 Migration creating `unaccent` and `openregister_fold()` on PostgreSQL, no-op elsewhere, degrading on failure. Verify: migration test on PostgreSQL and MariaDB in the CI matrix.
  - `lib/Migration/Version1Date20261113140000.php`; unit-tested in `tests/Unit/Migration/Version1Date20261113140000Test.php` (create, refused-but-present, absent, failed wrapper, non-PostgreSQL). The run on real PostgreSQL and MariaDB in the CI matrix is not done here.
- [x] 1.2 `SearchFolding` for PostgreSQL, MariaDB and exact mode. Verify: `tests/Unit/Db/MagicMapper/SearchFoldingTest.php` asserting the SQL per platform and mode.
  - `lib/Db/MagicMapper/SearchFolding.php` (column, pattern, operator, match, similarity, `_accents=exact` check) and `lib/Db/MagicMapper/MagicUnaccentProbe.php` (availability and reason per platform, the folding for a connection); `SearchFoldingTest`, `MagicUnaccentProbeTest`.
- [x] 1.3 `columnMatchSql()`, `applyFullTextSearch()`, the fuzzy path and `MagicFacetHandler` on the helper. Verify: integration test on both databases: `cafe` finds `Café`, `reunie` finds `reünie`, and the facet count equals the result count.
  - `MagicSearchHandler::searchFolding()` (folded where `MagicUnaccentProbe` says it is available, none for `_accents=exact`) feeds `SearchLeafScope::$folding`; `SearchLeaf::columnMatch()`/`similarity()` (UNION path), the QueryBuilder substring and parsed-term paths, the fuzzy name and property arms, and `MagicFacetHandler::applySearchFilter()` use it, and keep their historical SQL when it is null. Unit-tested in `tests/Unit/Db/MagicMapper/MagicSearchHandlerAccentFoldingTest.php`. The integration run on real PostgreSQL and MariaDB is not done here. The relevance column and the relevance order score folded text too (`SearchFolding::score()`, carried on `SortRequest::$folding`).
- [x] 1.4 Searchable-property indexes on the folded expression when available. Verify: `EXPLAIN` in the integration test shows the trigram index on PostgreSQL.
  - `MagicTableIndexer` creates `{table}_name_foldgin_idx` and `{table}_{column}_foldgin_idx` (GIN, `gin_trgm_ops`) on `openregister_fold(col::text)`, the expression `SearchFolding::column()` compares, on PostgreSQL where pg_trgm and the wrapper exist; a failure is logged and the pass goes on. Like the plain trigram index it is not dropped by a sync, and the index list describes it as a trigram index of a searchable property. Unit-tested in `tests/Unit/Db/MagicMapper/MagicTableIndexerFoldedIndexTest.php`; the create-table golden gains the new statements. The `EXPLAIN` run on real PostgreSQL is not done here.

## 2. Setting

- [x] 2.1 `accentInsensitive` on `GET` and `PATCH /api/settings/search-backend`, 400 when unavailable, `_accents=exact` on object search, `SearchConfiguration.vue` with texts in en and nl. Verify: `SettingsControllerTest` and a component test.
  - `AccentFoldingSetting` holds the choice (app config `search_accent_insensitive`, on unless switched off); `SearchBackendHandler::accentInsensitive()` / `setAccentInsensitive()` report and switch it, refusing to enable it where `MagicUnaccentProbe` gives a reason; `SettingsController::updateSearchBackend()` on `PATCH /api/settings/search-backend` (400 on a refusal or a malformed value); `MagicSearchHandler::searchFolding()` follows the choice. `SearchConfiguration.vue` in the admin settings, with en and every shipped locale for its two new strings. Unit-tested in `SettingsControllerTest`, `SearchBackendHandlerTest`, `AccentFoldingSettingTest`, `MagicSearchHandlerAccentFoldingTest` and `SearchConfiguration.spec.js`.

## 3. Tests and docs

- [ ] 3.1 Add `tests/e2e/ci/search-accents.spec.ts`: seed objects named "Café de Flore" and "reünie", search the object API and the index page with `cafe` and `reunie`, and with `_accents=exact`.
- [x] 3.2 Document accent-insensitive search and the setting in `docs/features/`.
  - The "Accents (`_accents`)" section of `docs/features/search-and-faceting.md`.

Acceptance:

- A folded search on a register of 100,000 objects is no more than 20 percent slower than the case-folded search it replaces, measured on PostgreSQL with the index.
- Facet counts and result totals agree for accented and unaccented terms.
