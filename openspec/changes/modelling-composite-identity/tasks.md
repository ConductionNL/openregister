# Tasks: modelling-composite-identity

## 1. Declaration

- [ ] 1.1 `identity: true` on a `refuse` uniqueness constraint in `UniqueConstraintEvaluator`, with the save-time refusals for two identities or identity on `report`. Verify: `UniqueConstraintEvaluatorTest` cases for accept and both refusals.
- [ ] 1.2 Schema save warns with the number of existing objects that break a newly declared identity. Verify: unit test with two duplicate objects reports 1 breach.

## 2. Lookup

- [ ] 2.1 `ObjectKeyResolver` over `ObjectService::findAll()` with `limit: 2`. Verify: unit tests for one match, none, two, and a missing value.
- [ ] 2.2 Four `by-key` routes before `objects#show` in `appinfo/routes.php`, each delegating to the existing controller method with the resolved uuid; 400, 404 and 409 answers. Verify: `tests/Api/ObjectByKeyTest` for GET, PATCH and DELETE, and a user without read access gets 404.
- [ ] 2.3 Composite index over the identity columns in `MagicMapper::createTableIndexes()` and `MagicTableHandler::updateTableIndexes()`. Verify: `MagicMapperIdentityIndexTest` asserts the index on PostgreSQL and MariaDB.

## 3. Rendering and guard

- [ ] 3.1 `@self.key` in `RenderObject` for a schema with an identity. Verify: render test.
- [ ] 3.2 Save-path guard refusing a change to an identity property with 422 naming it. Verify: `PATCH` that changes `zaaknummer` answers 422.

## 4. Description and docs

- [ ] 4.1 `OasService` describes the by-key paths and their parameters for a schema with an identity. Verify: `OasServiceTest`.
- [ ] 4.2 `docs/` page on record identity with a municipality code and case number example.
- [ ] 4.3 `tests/e2e/object-by-key.spec.ts`: declare an identity on a schema, create a record, fetch it by key through the API.

Acceptance:
- A schema without an identity constraint behaves exactly as before.
- The uuid stays the primary key and the id in every relation.
