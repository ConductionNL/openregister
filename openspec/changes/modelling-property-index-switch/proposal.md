---
kind: code
---

# Proposal: modelling-property-index-switch

## Summary

A functional administrator switches on an index for a field in the property editor,
so a large record type stays fast to filter and sort on that field. Two choices: an
index for exact filtering and sorting, and a text index for search inside the value.
Neither depends on making the field a facet. The editor shows which indexes exist.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | mod-index | Add a database index on a field so large record types stay fast to filter and sort | partial |

The row is in Open Register's own matrix, in its core area (modelling).
Demand: feature request, https://github.com/nocodb/nocodb/issues/8949.
Competitors rated yes:

- directus (source read at v12.4.1, not driven): "directus:app/src/modules/settings/routes/data-model/field-detail/field-detail-advanced/field-detail-advanced-schema.vue:459
  checkbox "Field is indexed" (en-US.yaml:1083); directus:api/src/services/fields.ts:1008
  and :1046 create or drop the database index on is_indexed, with an optional
  concurrent build".
- pocketbase (source read at v0.40.4, not driven): "per-collection indexes, unique or
  not, with optional WHERE: pocketbase:core/collection_model.go:372 Indexes, :649
  AddIndex; dashboard modal pocketbase:ui/src/collections/indexUpsertModal.js:69-79
  adds and edits index definitions that are applied to the table on save".

## Why

An index exists today only as a side effect. `MagicMapper::createTableIndexes()`
(`lib/Db/MagicMapper.php:3402`) creates a btree index on a column when the property is
`facetable` (:3580-3584) or a relation (:3552), and a trigram GIN index when it is
`searchable` (:3603-3612, from the open change `searchable-property-index`). The
property editor offers only the Facetable switch (`src/modals/schema/EditSchemaProperty.vue:380`);
`searchable` has no switch at all. An administrator who wants a fast sort on a date
must make it a facet, which also puts it in every facet response.

## What changes

- A property may declare `indexed: true`. The magic table gets a btree index on its
  column at creation and on sync, independent of `facetable`.
- The property editor shows two switches, Index for filtering and sorting
  (`indexed`) and Index for text search (`searchable`, PostgreSQL only), each with a
  one-line explanation.
- The schema detail page lists the indexes that exist on the magic table, and which
  property asked for each.
- Switching an index off drops it on the next sync. An index that the facet or
  relation logic needs stays, and the list says why.

## Consumers

- Every app with a large register: dossiq cases, pipelinq leads, stackiq
  applications. Their administrators get a sort that does not scan.

## ADRs

- openregister ADR-009 (performance invariants) and hydra ADR-058: indexes are how a
  list stays bounded in time as it grows.
- hydra ADR-001: the index is declared on the schema property, not hand-made in the
  database.

## Impact

- Extends `zoeken-filteren`.
- Affected code: `lib/Db/MagicMapper.php` (`createTableIndexes()`),
  `lib/Db/MagicMapper/MagicTableHandler.php` (`updateTableIndexes()`, :473),
  `src/modals/schema/EditSchemaProperty.vue`, the schema detail page,
  `PropertyValidatorHandler` (the new key).
- Backwards compatible: facetable and relation indexes are created as before.
- Size: S.

## Out of scope

- Composite indexes across several fields, which `modelling-composite-identity`
  creates for an identity key.
- Indexes on the legacy blob storage path.
