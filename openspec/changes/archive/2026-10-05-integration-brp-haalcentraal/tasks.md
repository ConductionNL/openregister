# Tasks — integration-brp-haalcentraal

## 1. BrpPersoonProvider leaf

- [x] 1.1 Add `lib/Service/Integration/Providers/BrpPersoonProvider.php`
      extending `AbstractIntegrationProvider`: `storage='external'`,
      `SOURCE_ID='brp-haalcentraal'`, `getRequiredApp()='openconnector'`,
      metadata (id/label/icon/group), `authRequirements()` advertising
      `supports: [oauth2_client_credentials, mtls]`.
- [x] 1.2 Implement `lookupByBsn()` — POST `personen` with a
      `RaadpleegMetBurgerservicenummer` body (BSN + explicit `fields`) via
      `ExternalIntegrationRouter::call`; unwrap `personen` /
      `_embedded.personen`; return `{ results, total }`.
- [x] 1.3 Implement `list()` (registry read-path) treating `_search` as the BSN.
- [x] 1.4 Degrade null-safely to `{ unavailable, cause }` on
      `ProviderUnavailableException` + any Throwable (AD-23); never log the BSN.
- [x] 1.5 `health()` defers to `ExternalIntegrationRouter::probe`.

## 2. Endpoint

- [x] 2.1 Add `lib/Controller/PersonLookupController.php` with
      `brpPerson()` (`@NoAdminRequired @NoCSRFRequired`): reads `bsn`,
      400 when empty, relays the provider's degraded descriptor as
      `503 { error, details: { cause } }`, success as `200 { results, total }`.
- [x] 2.2 Register the route
      `GET /api/integrations/brp/person` → `personLookup#brpPerson`.

## 3. Tests

- [x] 3.1 Add `BrpPersoonProviderTest` — metadata, auth shape, POST body + BSN,
      `personen` + `_embedded.personen` unwrap, empty-BSN short-circuit, the
      4-state degraded contract (source-missing / upstream-down / Throwable),
      health delegation. Router mocked; no real BSN.

## 4. Verify

- [x] 4.1 `composer test` (the new BrpPersoonProviderTest) is green.
- [x] 4.2 PHPCS + the enforced gate are clean on the changed files.
- [x] 4.3 Live (service layer, dormant source): the BRP lookup endpoint resolves
      the seeded `brp-haalcentraal` source and degrades to
      `upstream-service-down` (non-fatal) rather than
      `openconnector-source-missing`.
