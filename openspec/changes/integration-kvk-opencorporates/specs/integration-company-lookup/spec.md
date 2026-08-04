# integration-company-lookup

## ADDED Requirements

### Requirement: KvK Company Lookup

OpenRegister SHALL expose read-only KvK Handelsregister company-lookup
endpoints backed by a `KvkProvider` leaf (`storage='external'`,
`getOpenConnectorSource()='kvk'`). The KvK base URL + API key SHALL be
resolved exclusively from the OpenConnector `kvk` source via
`ExternalIntegrationRouter` — the caller never supplies a URL or key. The
endpoints SHALL be `@NoAdminRequired` (any authenticated user) and read-only.

`GET /api/integrations/kvk/company?kvkNumber=` SHALL look up a single company
by its KvK number and return `{ results, total }` of raw KvK rows.
`GET /api/integrations/kvk/search?q=` SHALL free-text-search the KvK
Handelsregister (optionally scoped by `plaats` / `type` / `sbiHoofdActiviteit`)
and return `{ results, total, limit, page }`. `limit` SHALL be clamped to
1..100. The endpoints SHALL round-trip the raw KvK JSON rows; the
Dutch→prospect field mapping is the consuming app's responsibility.

When the OpenConnector `kvk` source is missing/unconfigured, or the upstream
KvK API is unreachable, the endpoints SHALL fail closed — returning
`503 { error, details: { cause } }` (cause one of `openconnector-down`,
`openconnector-source-missing`, `provider-auth`, `upstream-service-down`) —
and SHALL never raise a fatal. The provider methods SHALL return
`{ unavailable, cause, results: [], total: 0 }` in that case.

#### Scenario: KvK lookup by number returns the company

- GIVEN the OpenConnector `kvk` source is configured and enabled
- WHEN a client calls `GET /api/integrations/kvk/company?kvkNumber=69599084`
- THEN the response is `200 { results, total }` carrying the raw KvK rows for
  that number
- @e2e exclude Backend integration endpoint — verified by PHPUnit (router mocked) + a live OpenConnector source, not a browser flow.

#### Scenario: KvK lookup degrades when the source is missing

- GIVEN no OpenConnector `kvk` source exists (or OpenConnector is disabled)
- WHEN a client calls `GET /api/integrations/kvk/company?kvkNumber=69599084`
- THEN the response is `503 { error, details: { cause } }` with cause
  `openconnector-source-missing` (or `openconnector-down`), and no fatal is raised
- @e2e exclude Backend degraded path — verified by PHPUnit + a live source-missing probe, not a browser flow.

#### Scenario: KvK lookup degrades when KvK is unreachable

- GIVEN the `kvk` source is seeded but dormant/unauthenticated (no API key)
- WHEN a client calls `GET /api/integrations/kvk/search?q=Acme`
- THEN the response is `503 { error, details: { cause } }` with cause
  `upstream-service-down` (or `provider-auth`), and no fatal is raised
- @e2e exclude Backend degraded path — verified against the real-but-unauthed KvK API, not a browser flow.

### Requirement: OpenCorporates Company Search

OpenRegister SHALL expose a read-only OpenCorporates company-search endpoint
backed by an `OpenCorporatesProvider` leaf (`storage='external'`,
`getOpenConnectorSource()='opencorporates'`). The OpenCorporates base URL +
API token SHALL be resolved exclusively from the OpenConnector
`opencorporates` source via `ExternalIntegrationRouter`. The endpoint SHALL be
`@NoAdminRequired` and read-only.

`GET /api/integrations/opencorporates/search?q=` SHALL free-text-search the
OpenCorporates register (optionally scoped by `jurisdiction`) and return
`{ results, total, limit, page }` of raw company rows (the
`results.companies[].company` envelope unwrapped to a flat list). `limit` SHALL
be clamped to 1..100. The company→prospect field mapping is the consuming
app's responsibility.

When the OpenConnector `opencorporates` source is missing/unconfigured, or the
upstream OpenCorporates API is unreachable, the endpoint SHALL fail closed —
returning `503 { error, details: { cause } }` (cause one of `openconnector-down`,
`openconnector-source-missing`, `provider-auth`, `upstream-service-down`) — and
SHALL never raise a fatal.

#### Scenario: OpenCorporates search returns matching companies

- GIVEN the OpenConnector `opencorporates` source is configured and enabled
- WHEN a client calls `GET /api/integrations/opencorporates/search?q=Acme&jurisdiction=nl`
- THEN the response is `200 { results, total, limit, page }` carrying the raw
  OpenCorporates company rows
- @e2e exclude Backend integration endpoint — verified by PHPUnit (router mocked) + a live source, not a browser flow.

#### Scenario: OpenCorporates search degrades on a missing/down source

- GIVEN no OpenConnector `opencorporates` source exists, or it is seeded but
  dormant/unauthenticated
- WHEN a client calls `GET /api/integrations/opencorporates/search?q=Acme`
- THEN the response is `503 { error, details: { cause } }` (cause
  `openconnector-source-missing` when absent, `upstream-service-down` /
  `provider-auth` when dormant), and no fatal is raised
- @e2e exclude Backend degraded path — verified by PHPUnit + a live probe, not a browser flow.
