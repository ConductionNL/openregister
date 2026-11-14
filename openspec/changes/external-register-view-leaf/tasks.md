# Tasks: external-register-view-leaf

## 1. Provider

- [x] 1.1 `OpenConnectorHttpProvider` implementing `ObjectSourceProvider`
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
      **The provider half is built**:
      `lib/Service/ObjectSource/OpenConnectorHttpProvider.php`, registered as
      `openconnector-http`. It takes the open questions' proposed answers:
      the mapping is an OpenRegister `Mapping` by reference (`config.mapping`,
      optional; a declared mapping that cannot run shows nothing, never the
      raw record), and the named source reaches the router through a
      read-only `ConfiguredSourceUpstream` identity built from
      `config.source`, so the router keeps one call path. Config keys:
      `source`, `find` (`{key}` is replaced, URL-encoded), `findAll`,
      `results` (dot path to the rows), `query` (the only filters passed on),
      `id` (the record field that becomes its uuid), `mapping`. Every failure
      reads as no record and is logged; telling the failures apart is the
      leaf endpoint's job (open question 3), with task 2.1.
- [x] 1.2 Seeded `bag-adres`, `brk-perceel`, `woz-waarde` schemas with `x-openregister-object-source`, disabled until a source is configured.
      `lib/Settings/external_register_register.json` (register
      `external-registers`), imported by the repair step
      `ImportExternalRegisterRegister` under the configuration id
      `openregister.external-registers`. Each schema names
      `openconnector-http`, read-only, with an empty `config.source`, so a
      read before an administrator names the source is the explained
      not-configured state and sends no request. The `find` paths follow the
      BAG, BRK and WOZ bevragingen APIs; no mapping is declared, so the fields
      are the source's own until one is.

## 2. Leaf

- [x] 2.1 `ExternalRegisterProvider` (ADR-019) with `widget` and `tab` surfaces; placement schema for `schema`, `key`, `display`.
      `lib/Service/Integration/Providers/ExternalRegisterProvider.php`, id
      `external-register`, group `data`, no required app, registered as a
      built-in provider; `PLACEMENT_SCHEMA` declares `schema`, `key`
      (required), `display` and an optional `register`. The widget and tab
      surfaces are declared on the JS side (AD-6) with task 2.2. Open
      question 3 took the proposed answer: `GET
      /api/external-register/{register}/{schema}/{key}[?refresh=1]`
      (`ExternalRegisterController` over `ExternalRegisterLookup`) answers
      `{state, adminActionable, record, fetchedAt, source, cacheSeconds}`
      from `ExternalRegisterDegrade`, every state a 200, cached for
      `cacheSecondsFor()`; the caller's read right on the sourced schema is
      asked first and a refusal is the same 404 as an unknown schema. The
      degrade flags come from the new `OpenConnectorHttpProvider::lookup()`,
      which tells an absent connector, a missing source, a refusal and an
      outage apart; the object read path (`find()`) is unchanged. Known
      limit: the router reports an upstream 404 as an outage, so "the
      register has nothing" is only seen when the source answers an empty
      body.
- [ ] 2.2 Widget: fetch by host key, display fields, fetch time and source, refresh, empty states.
      Open: the leaf widgets are `@conduction/nextcloud-vue` components
      (a `CnExternalRegisterCard` beside `CnIntegrationCard`), which this
      repository consumes and does not hold. The endpoint it reads is in
      place (task 2.1).

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/external-register-leaf.spec.ts`: a stubbed BAG source, a case with an address, the widget renders.
- [ ] 3.2 Unit tests for the provider mapping; vitest for the widget states.
      **The degrade half is tested**:
      `tests/Unit/Service/Integration/ExternalRegisterDegradeTest.php` (9),
      including that exactly one state means the register has nothing, that
      none of the six failures carries a record, and that the states which
      change the moment somebody acts are not cached at all.
      **The provider half is tested**:
      `tests/Unit/Service/ObjectSource/OpenConnectorHttpProviderTest.php` (9).
      The widget's vitest half waits for task 2.2.
      **The leaf's read is tested**:
      `OpenConnectorHttpProviderTest` (+10 for `lookup()`),
      `ExternalRegisterLookupTest` (9), `ExternalRegisterProviderTest` (6),
      `ExternalRegisterControllerTest` (3), `ImportExternalRegisterRegisterTest` (3).
