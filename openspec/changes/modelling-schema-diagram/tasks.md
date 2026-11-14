# Tasks: modelling-schema-diagram

## 1. Model endpoint

- [x] 1.1 `RegisterModelService` building nodes and edges from `$ref`, `items.$ref` and `inversedBy`, with external and dangling edges. Verify: `RegisterModelServiceTest` with a three-schema register, one cross-register ref and one broken ref.
- [x] 1.1a Edges from `x-openregister-relations` entries (design D-1a), deduplicated against `$ref` links of the same name and target. Verify: `RegisterModelServiceTest` with one declared relation, one declared relation that duplicates a `$ref`, and one whose target does not resolve.
- [x] 1.2 `GET /api/registers/{id}/model` in `RegistersController` with the same read checks as `registers#schemas`; route in `appinfo/routes.php`. Verify: API test, and a user without access to the other register sees an untitled external node.

## 2. Diagram view

- [x] 2.1 Diagram view on `RegisterDetail.vue` using `CnGraphCanvas`, automatic layout, node click opens the schema, edge click opens the property. Verify: `tests/e2e/schema-diagram.spec.ts` opens a register and clicks through to a schema.
- [x] 2.2 Per-user saved node positions keyed by register. Verify: e2e reload keeps a moved node where it was.
- [x] 2.3 Table rendering of the same nodes and edges, reachable by keyboard. Verify: axe check in the same e2e passes.
- [x] 2.4 SVG download. Verify: e2e asserts the file starts with `<svg`.

- [ ] 2.5 `tests/e2e/schema-diagram.spec.ts`: open a register, click through to a schema, move a node and reload, axe on the table, assert the download starts with `<svg`. The Verify lines of 2.1–2.4. (live pass: needs a running instance)

### What the second pass built (2.1–2.4, 3.1)

`src/views/register/RegisterModelDiagram.vue` on `RegisterDetail.vue` behind a
Cards / Diagram switch; the pure half in `src/views/register/registerModel.js`.

- **Layout** is the library's own `layoutFlowNodes` (left to right, layered from
  the schemas no link points at). A saved position always wins over it.
- **Positions** live in the generic per-user preferences endpoint under
  `register-model-layout-<register>`. The canvas never moves a box: every
  position frame is applied by the host, and the map is saved when a drag ends.
- **Broken links** get a stand-in box named after the reference, so the line
  has somewhere to go instead of disappearing.
- 🔑 **THE SVG IS BUILT FROM THE MODEL, NOT SERIALISED FROM THE PAGE** (deviates
  from D-4). Vue Flow draws its boxes as HTML; only the lines are SVG. A
  serialised canvas would download lines with no boxes.
- Above 50 schemas the boxes show titles only (design: Risks).

Tests: `registerModel.spec.js` (11, against the real layout),
`RegisterModelDiagram.mount.spec.js` (8), one switch test in
`RegisterDetail.mount.spec.js`. Nine new strings, translated in all 36 locales;
`Diagram` recorded as a cognate where it is spelled the same (cs, nl, rm, sk,
sl, sv).

## 3. Docs

- [x] 3.1 `docs/` section on reading a register's model as a diagram.

Acceptance:
- The endpoint reads no objects.
- A register with no links draws its schemas with no lines.
