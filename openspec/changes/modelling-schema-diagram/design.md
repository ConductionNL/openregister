# Design: modelling-schema-diagram

Read at openregister development 0ca409ee04.

## D-1: the model comes from schema definitions, on the server

`RegisterModelService::model(Register)` loads the register's schemas the way
`RegistersController::schemas()` does (`lib/Controller/RegistersController.php:930`),
then walks each schema's `properties`:

- a property with `$ref`, or `items.$ref` for an array, is an edge to the schema
  that ref resolves to, `many` when it is an array;
- `inversedBy` on that property names the inverse edge, so the pair is drawn as one
  line with two labels rather than two lines;
- a ref that resolves to a schema outside this register adds an external node,
  marked as such.

Resolving refs reuses the resolver the relation code already uses, so a slug, a
uuid and a numeric id all resolve the same way they do on save. A ref that does not
resolve is returned as a dangling edge, so the diagram shows the broken link instead
of hiding it.

## D-2: RBAC

The endpoint answers only for a register the caller may read, and lists only the
schemas the caller may list, exactly as `registers#schemas` does. An external node
the caller may not read is drawn as "a schema you cannot open", with no title.

## D-3: drawing

nextcloud-vue development ships `CnGraphCanvas` (built on Vue Flow, with
`CnFlowEdge` owning edge geometry and labels). A node is a box with the schema
title and its properties; an edge carries the property name and a `1` or `n`
marker. Layout: an automatic left-to-right layout on first open. A user who moves
a box has the positions saved as a per-user preference keyed by register, and the
host feeds them back, as `CnGraphCanvas` expects (it never mutates positions itself).

The same nodes and edges render as a table below the canvas, for keyboard and
screen-reader users (hydra ADR-059).

## D-4: SVG download

The canvas is SVG, so the download serialises the rendered SVG with its computed
styles inlined. No server rendering.

## Declarative-vs-imperative decision

Relations are read from their declarations (`$ref`, `inversedBy`); nothing new is
declared. The endpoint is a read over those declarations.

## Risks

- Large registers: a register with 200 schemas draws 200 boxes. The endpoint is
  cheap (definitions only); the view starts collapsed to titles above 50 schemas.
- Leaking schema names across registers through external nodes. D-2 covers it.
