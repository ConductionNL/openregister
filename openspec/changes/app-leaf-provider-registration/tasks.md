# Tasks: app-leaf-provider-registration

## Ratification (do first — this lifts an ADR-041 moratorium)

- [ ] Draft the companion org-wide ADR in `hydra/openspec/architecture/`
      ratifying the collect/render/read boundary and lifting ADR-041's
      "do not extend the OR registry with cross-app contribution" hold. Human
      review required before any code.
- [ ] Record the IReferenceProvider convergence decision in that ADR.

## Server — registration seam

- [ ] Add `RegisterLeafProvidersEvent` (typed `IEventDispatcher` collect-event),
      mirroring `RegisterMcpToolProvidersEvent`: a register method plus a getter,
      dispatched once when the leaf catalogue is first read.
- [ ] Add the `LeafDescriptor` value type (id, label, icon, requiredApp,
      surfaces, referenceType, requiresPermission, kinds).
- [ ] Wire catalogue collection: dispatch the event, collect announced leaves in
      addition to `bootBuiltinIntegrationProviders()`, log-and-swallow listener
      failures, apply first-wins on duplicate id.
- [ ] Validate descriptors on registration: non-empty kinds; a data-provider
      kind requires an accompanying `IntegrationProvider`; reject and skip an
      invalid descriptor without breaking the catalogue.

## Server — app-local data providers

- [ ] Add `app-local` to the `IntegrationProvider` storage-strategy enum and its
      validation, leaving `magic-column` / `link-table` / `external` /
      `query-time` untouched.
- [ ] Route `list` (and optional `create` / `get` / `update` / `delete`) for
      `app-local` providers to the provider instance; persist nothing in
      OpenRegister; keep the read path usable when writes are not implemented.

## Server — discovery

- [ ] Surface registered leaf descriptors through the OCS capabilities response
      (id, label, requiredApp, surfaces, kinds, usability).

## JS (coordinated @conduction/nextcloud-vue change — reference only)

- [ ] Formalise `registerIntegration()` as the render-surface half of the leaf
      contract; require the JS id to equal the server descriptor id.
- [ ] Extend the `integration-parity` gate to correlate the server descriptor
      with the JS registration cross-app.

## Tests

- [ ] PHPUnit: announced leaf reaches the catalogue; built-ins still present;
      throwing listener swallowed; empty-kinds rejected; data-provider requires a
      provider; first-wins on duplicate id.
- [ ] PHPUnit: app-local `list` returns sibling-store items and persists nothing;
      empty list on no items; `create` persists a note; read-only `create` throws
      cleanly while `list` still works.
- [ ] PHPUnit: OCS capabilities reports leaves and usability; disabled required
      app reports unusable.

## Docs

- [ ] Document the leaf contract (server descriptor + JS registration + app-local
      provider) and a worked "notes leaf" example in OpenRegister docs.
- [ ] Cross-reference ADR-019, ADR-041, ADR-013, and the companion ADR.
