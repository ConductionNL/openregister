---
kind: code
status: proposed
---

## Why

pipelinq holds two bespoke company-lookup HTTP clients —
`OCA\Pipelinq\Service\KvkApiClient` (KvK Handelsregister Zoeken API) and
`OCA\Pipelinq\Service\OpenCorporatesApiClient` (OpenCorporates v0.4) — each
carrying its own base URL (admin-tunable app-config) and API key. Both are
generic REST/JSON GET integrations (KvK: an `apikey` request header;
OpenCorporates: an `api_token` query parameter) with no mTLS/PKI and no
app-specific transport logic — exactly the shape OR's external-leaf model
already owns for xWiki / OpenProject.

Per ADR-022 (apps consume OR abstractions), the connection + credentials
should live once in OpenConnector, and OR should expose a thin lookup
endpoint pipelinq re-points to — instead of every consuming app
re-implementing the base-URL + key plumbing. Today OR has no KvK /
OpenCorporates leaf, so there is nothing to re-point to.

## What Changes

- **`KvkProvider`** (`storage='external'`, `getOpenConnectorSource()='kvk'`)
  — a stateless, read-only company-lookup leaf mirroring `XwikiProvider`'s
  OpenConnector wiring. `list()` (registry read-path) free-text-searches via
  the KvK `/zoeken` surface; two public methods add `lookupByKvkNumber()`
  (GET a company by KvK number) and `searchCompanies()` (free-text + KvK
  criteria). Every call routes through `ExternalIntegrationRouter` → the
  OpenConnector `kvk` source; failures degrade to `{ unavailable, cause }`.
- **`OpenCorporatesProvider`** (`storage='external'`,
  `getOpenConnectorSource()='opencorporates'`) — the same shape; `list()` +
  `searchCompanies()` hit the OpenCorporates `/companies/search` surface
  (optional `jurisdiction`).
- **`CompanyLookupController`** + routes exposing the read-only endpoints a
  consuming app calls:
  - `GET /api/integrations/kvk/company?kvkNumber=` — KvK lookup by number,
  - `GET /api/integrations/kvk/search?q=` — KvK free-text search,
  - `GET /api/integrations/opencorporates/search?q=` — OpenCorporates search.
  All `@NoAdminRequired`, read-only; a degraded provider result is relayed as
  `503 { error, details: { cause } }` so consumers render the 4-state banner.
- **DI + registry**: both providers are registered as services and added to
  the builtin integration-provider boot list, exactly where `XwikiProvider`
  is registered.
- The leaves round-trip the **raw upstream JSON** rows. The Dutch→prospect
  (KvK) and company→prospect (OpenCorporates) field mapping stays in the
  consuming app (pipelinq `KvkResultMapper` / `OpenCorporatesResultMapper`) —
  this leaf owns the connection, not the domain shape.

The matching OpenConnector change (`seed-kvk-opencorporates-sources`) seeds
the dormant `kvk` + `opencorporates` sources these providers resolve.

## Capabilities

### Added Capabilities
- `integration-company-lookup`: OR exposes read-only KvK + OpenCorporates
  company-lookup endpoints, backed by external OpenConnector-routed leaves,
  that degrade null-safely on a missing/down source.

## Impact

- **Code:** `lib/Service/Integration/Providers/KvkProvider.php`,
  `lib/Service/Integration/Providers/OpenCorporatesProvider.php`,
  `lib/Controller/CompanyLookupController.php`, `appinfo/routes.php`,
  `lib/AppInfo/Application.php` (DI + boot list), unit tests.
- **Behaviour:** with the dormant sources seeded (no key), the endpoints
  return `503 upstream-service-down` rather than `source-missing`; with a key
  + enabled source they return live KvK / OpenCorporates JSON.
- **Consumers:** pipelinq (future) re-points `KvkApiClient` →
  `GET /api/integrations/kvk/{company,search}` and
  `OpenCorporatesApiClient` → `GET /api/integrations/opencorporates/search`,
  dropping `pipelinq.kvk.api_base_url` / `pipelinq.opencorporates.api_base_url`
  and the per-app API keys (now on the OpenConnector sources).
- **Secrets:** none in OR — credentials live on the OpenConnector sources.
