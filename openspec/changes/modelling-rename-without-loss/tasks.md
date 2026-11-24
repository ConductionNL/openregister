# Tasks: modelling-rename-without-loss

## 1. Schema rename operation

- [x] 1.1 `formerSlugs` on `Schema` with a migration; `renameSchema` operation in `SchemaMigrationPlanner` rewriting `$ref` and `items.$ref` in other schemas, with preview output. Verify: `SchemaMigrationPlannerRenameSchemaTest` covers plan, run and rollback.
- [ ] 1.2 Preview warnings for flows, notification rules and saved views that name the old property or slug. Verify: unit test with one saved view naming the property.

## 2. Resolution

- [x] 2.1 `SchemaMapper::findBySlug()` fallback to `formerSlugs` under the organisation filter, current slug first. Verify: unit tests for fallback and for a former slug taken by another schema.
- [x] 2.2 `Deprecation` and `Link` headers on object responses resolved through a former slug. Verify: API test on `GET /api/objects/{register}/{old-slug}`.
  Built: `RegisterScopedSchemaResolver` resolves a slug the register carries no schema under to the register's schema whose `formerSlugs` hold it (`FormerSlugs::among()`; the current slug wins, only the register's own schemas are candidates, ids and uuids never go this way). `ResolvesRegisterAndSchemaTrait` notes when the path named a former slug and `ObjectsController::index()`/`show()` add `Deprecation: true` and `Link: <path at the new slug>; rel="successor-version"` (the request path with only the schema segment replaced). Tests: `FormerSlugsTest` (10), `RegisterScopedSchemaResolverFormerSlugTest` (5), `ResolvesRegisterAndSchemaFormerSlugTest` (5, the `GET .../meldingen-register/melding` case at the trait seam both endpoints use; the HTTP round trip itself is the e2e of 3.2).
- [x] 2.3 Register import matches by former slug. Verify: import test that an app descriptor with the old slug updates the renamed schema and creates nothing.
  Built: `ImportHandler` falls back to `formerSlugs` after the exact match, within the register's schemas and on the app-scoped path; the update keeps the schema's current slug (the rename stands) and the import maps the schema under both slugs so the descriptor's other references to the old slug still find it. Tests: `ImportHandlerFormerSlugMatchTest` (4: update without a second schema, current slug wins, app-scoped match, unknown slug still created).

## 3. Interface

- [ ] 3.1 Migrations tab on `SchemaDetails.vue` with run list, operation builder, preview, run and rollback. Verify: `tests/e2e/schema-rename.spec.ts` renames a property, sees the preview count, runs it and finds the data under the new name.
- [ ] 3.2 Schema rename in the same tab. Verify: same e2e renames a schema and the old API path still answers with the headers.

## 4. Docs

- [ ] 4.1 `docs/` page on renaming fields and record types, including the old path signal.

Acceptance:
- No object loses a value in a rename, and rollback restores the previous state.
