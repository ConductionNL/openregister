# Tasks — integration-kvk-opencorporates

## 1. Providers

- [x] 1.1 Add `KvkProvider` (`storage='external'`, `SOURCE_ID='kvk'`,
      group `external`, requiredApp `openconnector`, auth external/apikey)
      mirroring `XwikiProvider`'s OpenConnector wiring.
- [x] 1.2 `KvkProvider::list()` free-text-searches via the KvK `/zoeken`
      surface (object-independent: register/schema/objectId ignored,
      `_search` carries the query); unwrap the `resultaten` envelope.
- [x] 1.3 `KvkProvider::lookupByKvkNumber()` (GET by KvK number) +
      `searchCompanies()` (free-text + KvK criteria), both degrading
      null-safe to `{ unavailable, cause }`.
- [x] 1.4 Add `OpenCorporatesProvider` (`SOURCE_ID='opencorporates'`) with
      `list()` + `searchCompanies()` (optional jurisdiction) hitting
      `/companies/search`; unwrap `results.companies[].company`.

## 2. Controller + routes

- [x] 2.1 Add `CompanyLookupController` (`@NoAdminRequired`, read-only) with
      `kvkCompany()`, `kvkSearch()`, `openCorporatesSearch()`.
- [x] 2.2 Relay a degraded provider result as `503 { error, details: { cause } }`.
- [x] 2.3 Routes: `GET /api/integrations/kvk/company`,
      `GET /api/integrations/kvk/search`,
      `GET /api/integrations/opencorporates/search`.

## 3. Wiring

- [x] 3.1 Register both providers as DI services next to `XwikiProvider`.
- [x] 3.2 Add both to the builtin integration-provider boot list.

## 4. Tests + verify

- [x] 4.1 Unit tests: metadata, auth, isEnabled, router delegation +
      envelope unwrap, and the 4-state degraded contract for both providers.
- [x] 4.2 Live: with the dormant `kvk`/`opencorporates` sources seeded,
      confirm the endpoints return `503 upstream-service-down` (not
      `source-missing`); without the sources, `source-missing`.
