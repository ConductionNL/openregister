---
kind: code
---

# Proposal: modelling-schema-diagram

## Summary

A functional administrator opens a register and sees its record types as a
diagram: each schema a box with its fields, each link between schemas a line with
its name and direction. Clicking a box opens the schema. A link to a schema in
another register shows as a box at the edge. The diagram is read from the schema
definitions, so it is never out of date.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | mod-diagram | See the record types and the links between them as a diagram | no |

The row is in Open Register's own matrix, in its core area (modelling).
Demand: changelog, https://github.com/pocketbase/pocketbase/releases/tag/v0.37.0.
Competitors rated yes:

- nocodb (source read at 2026.09.0, not driven): "entity relationship diagram of a
  base nocodb:packages/nc-gui/components/erd/View.vue with table nodes and relation
  edges (TableNode.vue, RelationEdge.vue), mounted in the base ERD dialog
  nocodb:packages/nc-gui/components/dlg/Base/Erd.vue:60".
- pocketbase (source read at v0.40.4, not driven): "dashboard collections overview
  has a 'Fields and relations' tab rendering an entity relation diagram
  (pocketbase:ui/src/collections/collectionsOverviewModal.js:23, :119-122
  app.components.erd, component pocketbase:ui/src/base/erd.js)".

## Why

The matrix evidence: "grep diagram, mermaid, cytoscape, vis-network and erd in src:
no diagram component; the model is exported as OpenAPI per register
(appinfo/routes.php:1667 oas#generate), not drawn". The data to draw it exists.
`registers#schemas` (`appinfo/routes.php:1668`, `RegistersController::schemas()` at
`lib/Controller/RegistersController.php:930`) lists a register's schemas, and a
property's `$ref`, `items.$ref` and `inversedBy`
(`lib/Service/Schemas/PropertyValidatorHandler.php:499`) say where each link goes.
`schemas#related` (`appinfo/routes.php:1624`) answers the reverse question for one
schema at a time. Nobody puts them on one screen.

## What changes

- `GET /api/registers/{id}/model` returns the register's schemas as nodes (id,
  slug, title, the list of properties with their type) and its links as edges
  (from schema, to schema, property, one or many, the inverse property when
  declared). A link to a schema in another register adds that schema as an
  external node.
- The register detail page gains a Diagram view that draws the nodes and edges
  with nextcloud-vue's `CnGraphCanvas`, lays them out automatically, and remembers
  a moved box per user.
- Clicking a node opens that schema; clicking an edge opens the property.
- The diagram can be downloaded as SVG.

## Consumers

- Every app's administrator who inherits a register from an app descriptor and
  needs to see what links to what before changing it (see also
  `modelling-rename-without-loss` in this pass).
- stackiq, where an architect documents a landscape and wants the model as a picture.
- buildiq, whose merged change `data-model-diagram` (buildiq `development`
  974af86, row `data-model-diagram`, 3 competitors yes) draws its data model from
  this endpoint and names one addition (added 28 Sep 2026 by the owner moves
  pass): "buildiq's relation editor writes relations as `x-openregister-relations`
  entries with `name`, `target`, `cardinality` and `inverseOf`
  (`RelationEditor.vue:235-251`), and the model change reads only `$ref`,
  `items.$ref` and `inversedBy`. OpenRegister keeps the key
  (`openregister/lib/Db/Schema.php:3097`) but reads it only in
  `NotificationRecipientResolver.php:205`. Without the addition every relation a
  maker drew in buildiq is missing from the diagram." The endpoint therefore also
  draws the schema's `x-openregister-relations` entries as edges.

## ADRs

- hydra ADR-004 (frontend) and ADR-017 (component composition): the canvas is
  nextcloud-vue's `CnGraphCanvas`, not a new graph library in Open Register.
- hydra ADR-058 and openregister ADR-009: the model endpoint reads schema
  definitions only, never objects, and is bounded by the number of schemas.
- hydra ADR-059 (keyboard operability): every node is reachable and openable by
  keyboard, and the same information is available as a table.

## Impact

- New capability `schema-diagram`.
- Affected code: `RegistersController` (new `model` action), a `RegisterModelService`,
  one route, `src/views/register/RegisterDetail.vue`, a per-user layout preference.
- Backwards compatible: a new read-only endpoint and a new view.
- Size: M.

## Out of scope

- Editing the model by drawing lines. Links are still made in the property editor.
- A diagram of objects and their links. That is the relation walk in
  `relations-that-travel-and-what-they-expose`.
