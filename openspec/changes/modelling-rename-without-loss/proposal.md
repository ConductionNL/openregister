---
kind: code
---

# Proposal: modelling-rename-without-loss

## Summary

A functional administrator renames a field or a whole record type from the schema
page, sees what the rename will touch before it runs, and can roll it back. The data
moves with the field. Renaming a record type keeps its old API path answering, with
a header that names the new one, so the systems that call it do not break on the day.
Links from other record types follow the rename.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | mod-rename-lossless | Rename a field or a record type later without losing the data or breaking the links to it | partial |

The row is in Open Register's own matrix, in its core area (modelling).
Demand: feature request, https://github.com/directus/directus/discussions/2711.
Competitors rated yes:

- nocodb (source read at 2026.09.0, not driven): "a table rename issues a database
  rename nocodb:packages/nocodb/src/services/tables.service.ts:245 (sqlOpPlus
  tableRename), a column rename updates the column in place and rewrites formulas
  that reference it nocodb:packages/nocodb/src/services/columns.service.ts:615-625;
  links and formulas point at column and model ids, not names, so they survive".
- pocketbase (source read at v0.40.4, not driven): "pocketbase:core/collection_record_table_sync.go:73-74
  a collection rename renames the table in place, :111-125 a field rename renames
  the column via a temporary name, data kept; relation fields point at the target by
  id, not name (pocketbase:core/field_relation.go:80 CollectionId), so links survive.
  Gap: API rules and view queries that name the old field are not rewritten, the
  collection validation rejects the save until they are fixed".

## Why

Half exists. `POST /api/schemas/{id}/migrations` (`appinfo/routes.php:1642`,
`schemaMigration#migrate`) runs a plan through
`lib/Service/Schema/SchemaMigrationPlanner.php`, whose `rename` operation (:116,
:174) moves each object's value in `applyRename()` (:222). There is a preview
(`schemaMigration#previewMigration`, :1641) and a rollback (`schemaMigration#rollback`,
:1643), and `lib/Service/Schema/SchemaDiffService.php:97` classifies a declared
rename as one breaking change. The matrix note says what is missing: "no page calls
the migrations routes, and renaming a record type's slug, which moves its API path,
has no carry-over".

A schema is found by its slug with an exact match
(`SchemaMapper::findBySlug()`, `lib/Db/SchemaMapper.php:968`, `eq('slug', ...)` at
:982), and other schemas point at it by slug in `$ref` (for example `"$ref":
"conceptScheme"` in the shipped register JSON). A slug change today breaks both.

## What changes

- The schema page gains a Migrations tab. It lists earlier runs from
  `schemaMigration#runs`, builds a rename or other operation, shows the preview
  with the number of objects touched, runs it, and offers rollback per run.
- Renaming a schema's slug is a migration operation of its own. It records the old
  slug in `formerSlugs` on the schema and rewrites every `$ref` in other schemas that
  named the old slug, in one run that rollback undoes.
- A request that names a former slug in `/api/objects/{register}/{schema}/...` is
  served as the renamed schema. The response carries a `Deprecation` header and a
  `Link` header with `rel="successor-version"` naming the new path.
- An app re-import that ships the old slug matches the renamed schema through
  `formerSlugs` instead of creating a second one.

## Consumers

- Every app whose administrators rename a field that shipped with the app.
- integriq and other API clients get a working old path and a header that tells them
  where to move.

## ADRs

- hydra ADR-002 (API): an old path keeps answering with a deprecation signal, the
  pattern `api-as-a-versioned-surface` uses for versions.
- openregister ADR-003: the rename run and its rollback are audit facts on the chain.
- openregister ADR-005 (register import via repair steps): a re-import matches by
  former slug.

## Impact

- Extends `schema-migration`.
- Affected code: `SchemaMigrationPlanner` (a `renameSchema` operation), `Schema`
  (`formerSlugs`, migration), `SchemaMapper::findBySlug()` fallback, the object
  routes' schema resolution, the import slug match, `src/views/schema/SchemaDetails.vue`
  (new tab), a migrations store module.
- Backwards compatible: a schema that was never renamed resolves as today.
- Size: M.

## Out of scope

- Renaming a register's slug. Same pattern, later change.
- Rewriting flows, notification rules or saved views that name the old field; the
  preview lists them so the administrator can fix them before running.
