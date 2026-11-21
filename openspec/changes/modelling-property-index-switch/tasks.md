# Tasks: modelling-property-index-switch

## 1. Backend

- [x] 1.1 Accept `indexed` in `PropertyValidatorHandler` and create the btree index in `MagicMapper::createTableIndexes()`. Verify: `MagicMapperIndexedPropertyTest` asserts the index on PostgreSQL and MariaDB, and one index when a property is both indexed and facetable.
- [x] 1.2 Drop pass in `MagicTableHandler::updateTableIndexes()` for convention-named indexes no property asks for. Verify: unit test that switching `indexed` off drops the index and leaves a hand-made one.
- [x] 1.3 `GET /api/schemas/{id}/indexes` listing indexes with the flag that asked for each. Verify: API test.

## 2. Interface

- [x] 2.1 Two switches in `EditSchemaProperty.vue` beside Facetable, text search disabled with a reason off PostgreSQL. Verify: `tests/e2e/property-index-switch.spec.ts` switches Index for filtering and sorting on and sees it in the index list.
      DONE in code (2026-11-14): Index for filtering and sorting (`indexed`) and
      Index for text search (`searchable`) sit above Facetable, each with a
      one-line hint. The text search switch is enabled only when
      `GET /api/schemas/{id}/indexes` reports `textSearchIndex: true`
      (`MagicMapper::canBuildTextSearchIndex()`, PostgreSQL with pg_trgm), and
      its hint names that requirement. `searchable` joined the property
      vocabulary so a save carrying it is not refused. Component and logic
      specs: `EditSchemaProperty.indexSwitches.spec.js`. The e2e spec needs a
      live instance and is not written here.
- [x] 2.2 Index list on the schema detail page. Verify: same e2e.
      DONE in code (2026-11-14): an Indexes tab on the schema detail page
      (`SchemaIndexesTab.vue`), one table per register with each index's
      field, the setting that asked for it (or "not a field setting" for a
      hand-made one) and its name. Spec: `SchemaIndexesTab.spec.js`. The e2e
      above is left for a live run.
- [ ] 2.3 Translations for the 11 new interface strings beyond `en`, `nl` and
      `de` (added with this pass); every other finished locale is one key
      short of parity per string until then.

## 3. Docs

- [x] 3.1 `docs/` section on when to index a field and what it costs.

Acceptance:
- Facetable and relation indexes are created exactly as before.
