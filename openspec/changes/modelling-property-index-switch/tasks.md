# Tasks: modelling-property-index-switch

## 1. Backend

- [ ] 1.1 Accept `indexed` in `PropertyValidatorHandler` and create the btree index in `MagicMapper::createTableIndexes()`. Verify: `MagicMapperIndexedPropertyTest` asserts the index on PostgreSQL and MariaDB, and one index when a property is both indexed and facetable.
- [ ] 1.2 Drop pass in `MagicTableHandler::updateTableIndexes()` for convention-named indexes no property asks for. Verify: unit test that switching `indexed` off drops the index and leaves a hand-made one.
- [ ] 1.3 `GET /api/schemas/{id}/indexes` listing indexes with the flag that asked for each. Verify: API test.

## 2. Interface

- [ ] 2.1 Two switches in `EditSchemaProperty.vue` beside Facetable, text search disabled with a reason off PostgreSQL. Verify: `tests/e2e/property-index-switch.spec.ts` switches Index for filtering and sorting on and sees it in the index list.
- [ ] 2.2 Index list on the schema detail page. Verify: same e2e.

## 3. Docs

- [ ] 3.1 `docs/` section on when to index a field and what it costs.

Acceptance:
- Facetable and relation indexes are created exactly as before.
