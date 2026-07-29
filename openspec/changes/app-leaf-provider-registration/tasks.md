# Tasks: app-leaf-provider-registration

## Ratification (do first — this lifts an ADR-041 moratorium)

- [x] Draft the companion org-wide ADR in `hydra/openspec/architecture/`
      ratifying the collect/render/read boundary and lifting ADR-041's
      "do not extend the OR registry with cross-app contribution" hold. Human
      review required before any code. — ADR-066 (ratified/merged in hydra;
      referenced from the event/registry/descriptor docblocks).
- [x] Record the IReferenceProvider convergence decision in that ADR. — ADR-066.

## Server — registration seam

- [x] Add `RegisterLeafProvidersEvent` (typed `IEventDispatcher` collect-event),
      mirroring `RegisterMcpToolProvidersEvent`: a register method plus a getter,
      dispatched once when the leaf catalogue is first read.
- [x] Add the `LeafDescriptor` value type (id, label, icon, requiredApp,
      surfaces, referenceType, requiresPermission, kinds).
- [x] Wire catalogue collection: dispatch the event, collect announced leaves in
      addition to `bootBuiltinIntegrationProviders()`, log-and-swallow listener
      failures, apply first-wins on duplicate id. — `LeafRegistry` (lazy, once)
      + `Application::bootLeafRegistry()` installs the loader on the shared
      `IntegrationRegistry`.
- [x] Validate descriptors on registration: non-empty kinds; a data-provider
      kind requires an accompanying `IntegrationProvider`; reject and skip an
      invalid descriptor without breaking the catalogue. — `LeafRegistry::collectLeaf()`
      (also rejects non-kebab-case id + unknown kinds).

## Server — app-local data providers

- [x] Add `app-local` to the `IntegrationProvider` storage-strategy enum and its
      validation, leaving `magic-column` / `link-table` / `external` /
      `query-time` untouched. — documented on the `IntegrationProvider` contract
      (interface + `getStorageStrategy()`); `IntegrationRegistry::addProvider()`
      accepts it with no OpenConnector-source requirement.
- [x] Route `list` (and optional `create` / `get` / `update` / `delete`) for
      `app-local` providers to the provider instance; persist nothing in
      OpenRegister; keep the read path usable when writes are not implemented.
      — existing `ObjectIntegrationsController` dispatch calls the provider
      methods directly (no strategy branch needed); read-only leaves let the
      `AbstractIntegrationProvider` default throw `NotImplementedException`.

## Server — discovery

- [x] Surface registered leaf descriptors through the OCS capabilities response
      (id, label, requiredApp, surfaces, kinds, usability). — new
      `openregister.integrations.leaves` block via `LeafRegistry::describeForCapabilities()`.

## JS (coordinated @conduction/nextcloud-vue change — reference only)

- [ ] Formalise `registerIntegration()` as the render-surface half of the leaf
      contract; require the JS id to equal the server descriptor id.
      (Coordinated @conduction/nextcloud-vue change — out of scope for this repo.)
- [ ] Extend the `integration-parity` gate to correlate the server descriptor
      with the JS registration cross-app. (The canonical gate-24 lives in
      hydra `scripts/run-hydra-gates.sh` + the JS check in nextcloud-vue; the
      openregister wrapper only delegates to it. Follow-up in those repos —
      not edited here.)

## Tests

- [x] PHPUnit: announced leaf reaches the catalogue; built-ins still present;
      throwing listener swallowed; empty-kinds rejected; data-provider requires a
      provider; first-wins on duplicate id. — `LeafRegistryTest`.
- [x] PHPUnit: app-local `list` returns sibling-store items and persists nothing;
      empty list on no items; `create` persists a note; read-only `create` throws
      cleanly while `list` still works. — `LeafRegistryTest`.
- [x] PHPUnit: OCS capabilities reports leaves and usability; disabled required
      app reports unusable. — `LeafRegistryTest`. (22 tests / 174 assertions
      pass in the nextcloud:34 container; plus a live probe against the running
      instance.)

## Docs

- [x] Document the leaf contract (server descriptor + JS registration + app-local
      provider) and a worked "notes leaf" example in OpenRegister docs. —
      `docs/Integrations/leaf-system.md` → "Cross-app leaf registration".
- [x] Cross-reference ADR-019, ADR-041, ADR-013, and the companion ADR (ADR-066).
