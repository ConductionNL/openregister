# Tasks: modelling-composite-identity

## 1. Declaration

- [x] 1.1 `identity: true` on a `refuse` uniqueness constraint in `UniqueConstraintEvaluator`, with the save-time refusals for two identities or identity on `report`. Verify: `UniqueConstraintEvaluatorTest` cases for accept and both refusals.
- [ ] 1.2 Schema save warns with the number of existing objects that break a newly declared identity. Verify: unit test with two duplicate objects reports 1 breach.

## 2. Lookup

- [x] 2.1 `ObjectKeyResolver` over `ObjectService::findAll()` with `limit: 2`. Verify: unit tests for one match, none, two, and a missing value.
- [~] 2.2 Four `by-key` routes before `objects#show` in `appinfo/routes.php`, each delegating to the existing controller method with the resolved uuid; 400, 404 and 409 answers. Verify: `tests/Api/ObjectByKeyTest` for GET, PATCH and DELETE, and a user without read access gets 404.
- [ ] 2.3 Composite index over the identity columns in `MagicMapper::createTableIndexes()` and `MagicTableHandler::updateTableIndexes()`. Verify: `MagicMapperIdentityIndexTest` asserts the index on PostgreSQL and MariaDB.

## 3. Rendering and guard

- [ ] 3.1 `@self.key` in `RenderObject` for a schema with an identity. Verify: render test.
- [x] 3.2 Save-path guard refusing a change to an identity property with 422 naming it. Verify: `PATCH` that changes `zaaknummer` answers 422.

## 4. Description and docs

- [ ] 4.1 `OasService` describes the by-key paths and their parameters for a schema with an identity. Verify: `OasServiceTest`.
- [x] 4.2 `docs/` page on record identity with a municipality code and case number example.
- [ ] 4.3 `tests/e2e/object-by-key.spec.ts`: declare an identity on a schema, create a record, fetch it by key through the API.

Acceptance:
- A schema without an identity constraint behaves exactly as before.
- The uuid stays the primary key and the id in every relation.

## Status, 2026-11-13

Built in code with unit tests: 1.1 (`UniqueConstraintEvaluator::identity()` and
`identityRefusals()`, refused at schema save by `IdentityDeclarationCheck`), 2.1
(`ObjectKeyResolver`), 2.2's four routes and controller methods, 3.2
(`IdentityGuardListener`; the schema migration writes inside
`IdentityRewriteScope`, the one way past it), and 4.2
(`docs/features/record-identity.md`).

- 2.2 stays partial: `tests/Api/ObjectByKeyTest` needs a running stack.
- 1.2 (breach count at schema save), 2.3 (composite index on the magic table),
  3.1 (`@self.key` in the renderer; `UniqueConstraintEvaluator::keyOf()` builds
  the key already) and 4.1 (OpenAPI paths) are not built yet.
- 4.3 is an e2e and needs a running stack.
