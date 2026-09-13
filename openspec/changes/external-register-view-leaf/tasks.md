# Tasks: external-register-view-leaf

## 1. Provider

- [ ] 1.1 `OpenConnectorHttpProvider` implementing `ObjectSourceProvider` over the integration router with a declared response mapping; degrade contract.
- [ ] 1.2 Seeded `bag-adres`, `brk-perceel`, `woz-waarde` schemas with `x-openregister-object-source`, disabled until a source is configured.

## 2. Leaf

- [ ] 2.1 `ExternalRegisterProvider` (ADR-019) with `widget` and `tab` surfaces; placement schema for `schema`, `key`, `display`.
- [ ] 2.2 Widget: fetch by host key, display fields, fetch time and source, refresh, empty states.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/external-register-leaf.spec.ts`: a stubbed BAG source, a case with an address, the widget renders.
- [ ] 3.2 Unit tests for the provider mapping and degrade; vitest for the widget states.
