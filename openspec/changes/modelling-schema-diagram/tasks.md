# Tasks: modelling-schema-diagram

## 1. Model endpoint

- [ ] 1.1 `RegisterModelService` building nodes and edges from `$ref`, `items.$ref` and `inversedBy`, with external and dangling edges. Verify: `RegisterModelServiceTest` with a three-schema register, one cross-register ref and one broken ref.
- [ ] 1.2 `GET /api/registers/{id}/model` in `RegistersController` with the same read checks as `registers#schemas`; route in `appinfo/routes.php`. Verify: API test, and a user without access to the other register sees an untitled external node.

## 2. Diagram view

- [ ] 2.1 Diagram view on `RegisterDetail.vue` using `CnGraphCanvas`, automatic layout, node click opens the schema, edge click opens the property. Verify: `tests/e2e/schema-diagram.spec.ts` opens a register and clicks through to a schema.
- [ ] 2.2 Per-user saved node positions keyed by register. Verify: e2e reload keeps a moved node where it was.
- [ ] 2.3 Table rendering of the same nodes and edges, reachable by keyboard. Verify: axe check in the same e2e passes.
- [ ] 2.4 SVG download. Verify: e2e asserts the file starts with `<svg`.

## 3. Docs

- [ ] 3.1 `docs/` section on reading a register's model as a diagram.

Acceptance:
- The endpoint reads no objects.
- A register with no links draws its schemas with no lines.
