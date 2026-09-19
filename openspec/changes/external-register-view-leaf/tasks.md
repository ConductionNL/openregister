# Tasks: external-register-view-leaf

## 1. Provider

- [ ] 1.1 `OpenConnectorHttpProvider` implementing `ObjectSourceProvider`
      over the integration router with a declared response mapping. **The
      DEGRADE CONTRACT half is built** and the provider half is not:
      `lib/Service/Integration/ExternalRegisterDegrade.php` names the six ways
      a lookup can fail to show a record and keeps them apart, because five of
      them are about us and only one — the register answered and holds
      nothing — is a fact about the world. A municipality is obliged to
      consult the basisregistraties, so a blank panel is a claim with
      consequences. A refusal is deliberately not an outage, the missing-key
      case is reported before the missing app, and a failure is cached briefly
      where an answer is cached for longer, so a widget does not stay broken
      after the thing it depends on is fixed.
- [ ] 1.2 Seeded `bag-adres`, `brk-perceel`, `woz-waarde` schemas with `x-openregister-object-source`, disabled until a source is configured.

## 2. Leaf

- [ ] 2.1 `ExternalRegisterProvider` (ADR-019) with `widget` and `tab` surfaces; placement schema for `schema`, `key`, `display`.
- [ ] 2.2 Widget: fetch by host key, display fields, fetch time and source, refresh, empty states.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/external-register-leaf.spec.ts`: a stubbed BAG source, a case with an address, the widget renders.
- [ ] 3.2 Unit tests for the provider mapping; vitest for the widget states.
      **The degrade half is tested**:
      `tests/Unit/Service/Integration/ExternalRegisterDegradeTest.php` (9),
      including that exactly one state means the register has nothing, that
      none of the six failures carries a record, and that the states which
      change the moment somebody acts are not cached at all.
