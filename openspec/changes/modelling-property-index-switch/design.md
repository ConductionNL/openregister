# Design: modelling-property-index-switch

Read at openregister development 0ca409ee04.

## D-1: `indexed` is its own flag

`createTableIndexes()` loops over the schema's properties and checks `facetable`
(`lib/Db/MagicMapper.php:3580`) and `searchable` (:3603). A third check,
`($propertyConfig['indexed'] ?? false) === true`, creates
`CREATE INDEX IF NOT EXISTS {table}_{column}_idx ON {table} ({column})`, the same
statement the facetable branch uses (:3584). If the property is also facetable, the
statement is a no-op because the name matches; one index serves both.

`PropertyValidatorHandler` accepts `indexed` as a boolean, the same way it accepts
`facetable`.

## D-2: sync adds and drops

`MagicTableHandler::updateTableIndexes()` (`lib/Db/MagicMapper/MagicTableHandler.php:473`)
already re-runs index creation on an existing table when the register card's table
sync runs (`appinfo/routes.php:279` `tables#sync`). It gains a drop pass: an index
named by this convention whose property no longer asks for it, through `indexed`,
`facetable` or a relation, is dropped. Only indexes following the naming convention
are touched, so a hand-made index survives.

## D-3: the editor switches

`EditSchemaProperty.vue` shows the Facetable switch at :380. Two switches sit beside
it: Index for filtering and sorting, and Index for text search. The text search
switch is disabled with an explanation when the instance is not on PostgreSQL with
`pg_trgm`, which `MagicMapper::hasPgTrgmExtension()` already detects.

## D-4: showing what exists

`GET /api/schemas/{id}/indexes` returns the indexes on the schema's magic table
(name, column, kind, and the property flag that asked for it), read from the
database catalogue through the platform's schema manager. The schema detail page
shows it as a small table.

## Declarative-vs-imperative decision

Declarative: a flag on the property; the magic table follows it.

## Risks

- Index builds on a large table lock writes on MariaDB. Creation runs in the sync,
  which an administrator starts, and the switch's help text says so. PostgreSQL
  uses `CREATE INDEX IF NOT EXISTS` as today; a concurrent build is a follow-up.
- Too many indexes slow writes. The list in D-4 makes the cost visible.
