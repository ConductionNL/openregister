---
kind: code
depends_on: []
---

# Proposal: modelling-query-backed-type

## Summary

An administrator turns a saved view into a read-only record type: its records are the rows the view query returns, it has its own slug, and other features (relations, exports, the API) can read it like any schema. Writes to it are refused.

## The rows this closes

Source matrix: openregister `openspec/parity/capabilities.json` (comparedOn 2026-09-25). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### mod-view-type, define a read-only record type that is computed from a query over other types

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `modelling`, source `competitor-derived`.

Matrix evidence, verbatim:

> Saved views (query + presentation) lib/Db/View.php:150, ViewsController routes.php:1781-1785, used in src/views/search/SearchIndex.vue and src/modals/view/EditView.vue; no read-only schema defined by a query (searched 'virtual schema', 'materialized view', 'x-openregister-view' in lib)

Matrix note, verbatim:

> A saved view is a stored query, not a record type other features can reference.

Competitor cells rated `yes`, verbatim:

- nocodb: source read at 2026.09.0, not driven: nocodb:packages/nocodb/src/controllers/sql-views.controller.ts:21 POST /api/v2/meta/bases/:baseId/sources/:sourceId/sqlView; nocodb:packages/nocodb/src/services/sql-views.service.ts:107-110 viewCreate with a view_definition; database views surface as read-only tables. No UI consumer for creating one, API only
- pocketbase: source read at v0.40.4, not driven: pocketbase:core/collection_model.go:26 CollectionTypeView; pocketbase:core/collection_model_view_options.go:11 ViewQuery validated at :16; pocketbase:apis/collection.go:31 dry-run-view; pocketbase:ui/src/collections/collectionViewQueryTab.js:3

## Why

A saved view is a stored query on one screen. Two competitors let an administrator publish such a query as a type of its own, so a report, an export or another type can point at "active permits in district north" without repeating the filter. OpenRegister has the query and the read path; it lacks the type.

## What is built today

- Saved views persist a query and a presentation (`lib/Db/View.php`, `ViewsController`).
- Views are used on `/tables` (`src/views/search/SearchIndex.vue`, `src/modals/view/EditView.vue`).
- No read-only schema defined by a query.

## What changes

1. A schema can declare `x-openregister-view: {"view": "<view id or slug>"}` and no properties of its own; its properties are those of the view source schema.
2. Reading objects of that schema runs the view query and returns its rows; create, update and delete answer 405.

## Out of scope

- Joins across schemas (a view has one source schema, as today).
- Materialisation or caching of the result.
