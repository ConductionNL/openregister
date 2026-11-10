# Design: modelling-rename-without-loss

Read at openregister development 0ca409ee04.

## D-1: the page drives the routes that exist

The routes are in `appinfo/routes.php:1636-1643`: `changelog`, `revalidate`, `runs`,
`run`, `previewMigration`, `migrate`, `rollback`. The schema page
(`src/views/schema/SchemaDetails.vue`) has the tabs Dashboard, Calendar, Workflows and
Rules. A Migrations tab joins them, backed by a small store module that calls those
routes. No new server route is needed for property renames.

## D-2: a schema rename is an operation, not an edit

`SchemaMigrationPlanner` knows property operations (`rename` at :116 and :174). A new
operation `renameSchema {from, to}`:

1. checks `to` is free in the register (the per-register slug uniqueness rule);
2. finds every schema whose `properties` carry `$ref` or `items.$ref` equal to
   `from`, and plans the rewrite;
3. appends `from` to the schema's new `formerSlugs` list;
4. records the run like any other, so `rollback` restores slug, refs and list.

The preview returns the schemas whose refs change and the count of objects of the
renamed schema. Objects themselves do not change: they point at their schema by id.

## D-3: former slugs resolve, with a signal

`SchemaMapper::findBySlug()` (`lib/Db/SchemaMapper.php:968`) queries `eq('slug', ...)`.
When that finds nothing, it tries schemas whose `formerSlugs` contain the slug, under
the same organisation filter (:985 onward). The object controller learns that the
match came through a former slug and adds `Deprecation: true` and
`Link: </api/objects/{register}/{new-slug}>; rel="successor-version"` to the response.

A former slug that a newer schema has taken as its current slug belongs to the newer
schema: the current slug wins, always.

## D-4: re-import

The register import matches an incoming schema to an existing one by slug. It does
the same `formerSlugs` fallback, so an app update that still ships the old slug updates
the renamed schema. `local-changes-to-app-shipped-configuration` decides what the
update may overwrite; this change only makes sure it finds the right schema.

## D-5: what the preview warns about

Flows, notification rules and saved views can name a property. The preview lists
those that name the renamed property or the old slug, as warnings, without rewriting
them. That keeps this change bounded and makes the risk visible.

## Declarative-vs-imperative decision

The rename is a declared migration operation run by the existing planner; the only
new behaviour is the slug fallback on read.

## Risks

- A former slug reused by another schema: D-3 gives the current slug precedence.
- A large number of refs across registers: the plan reads schema definitions only,
  bounded by the number of schemas.
