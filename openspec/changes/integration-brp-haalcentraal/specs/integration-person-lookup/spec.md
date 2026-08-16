# integration-person-lookup

## ADDED Requirements

### Requirement: BRP HaalCentraal Person Lookup

OpenRegister SHALL expose a read-only BRP person-lookup endpoint backed by a
`BrpPersoonProvider` leaf (`storage='external'`,
`getOpenConnectorSource()='brp-haalcentraal'`). The HaalCentraal base URL, the
OAuth2 client_credentials secret, and the PKIoverheid mutual-TLS client
certificate SHALL be resolved exclusively from the OpenConnector
`brp-haalcentraal` source via `ExternalIntegrationRouter` → `CallService` — the
caller never supplies a URL, token, or certificate, and the provider never
holds an HTTP client. The endpoint SHALL be `@NoAdminRequired` (any
authenticated user) and read-only.

`GET /api/integrations/brp/person?bsn=` SHALL look up a single person by
Burgerservicenummer and return `{ results, total }` of raw HaalCentraal person
objects (0 or 1 entries). The lookup SHALL issue a POST to the HaalCentraal
`/personen` endpoint with a `RaadpleegMetBurgerservicenummer` body carrying the
BSN in `burgerservicenummer` and an explicit `fields` list. The BSN SHALL
travel in the request body only (never the URL path) and SHALL never be logged
by OpenRegister. The endpoint SHALL round-trip the raw HAL+JSON person object;
the BSN elfproef validation, BSN masking, and the BRP→domain field mapping are
the consuming app's responsibility.

When the OpenConnector `brp-haalcentraal` source is missing/unconfigured, or
the upstream HaalCentraal API is unreachable, the endpoint SHALL fail closed —
returning `503 { error, details: { cause } }` (cause one of
`openconnector-down`, `openconnector-source-missing`, `provider-auth`,
`upstream-service-down`) — and SHALL never raise a fatal. The provider method
SHALL return `{ unavailable, cause, results: [], total: 0 }` in that case.

The provider's `authRequirements()` SHALL report
`{ type: 'external', configuredVia: 'openconnector', source: 'brp-haalcentraal',
supports: ['oauth2_client_credentials', 'mtls'] }` so the admin UI surfaces
that both transports are configured on the OpenConnector source.

#### Scenario: BRP lookup by BSN returns the person

- GIVEN the OpenConnector `brp-haalcentraal` source is configured (OAuth2 +
  mTLS) and enabled
- WHEN a client calls `GET /api/integrations/brp/person?bsn=999993653`
- THEN the response is `200 { results, total }` carrying the raw HaalCentraal
  person object for that BSN
- @e2e exclude Backend integration endpoint — verified by PHPUnit (router mocked) + a live OpenConnector source, not a browser flow.

#### Scenario: BRP lookup forwards a RaadpleegMetBurgerservicenummer body

- GIVEN the `brp-haalcentraal` source is configured
- WHEN the provider looks up a BSN
- THEN it routes a POST to `personen` whose body has
  `type='RaadpleegMetBurgerservicenummer'`, the BSN in
  `burgerservicenummer[0]`, and a non-empty `fields` list
- @e2e exclude Backend request-shape assertion — verified by PHPUnit (router mocked), not a browser flow.

#### Scenario: BRP lookup degrades when the source is missing

- GIVEN no OpenConnector `brp-haalcentraal` source exists (or OpenConnector is disabled)
- WHEN a client calls `GET /api/integrations/brp/person?bsn=999993653`
- THEN the response is `503 { error, details: { cause } }` with cause
  `openconnector-source-missing` (or `openconnector-down`), and no fatal is raised
- @e2e exclude Backend degraded path — verified by PHPUnit + a live source-missing probe, not a browser flow.

#### Scenario: BRP lookup degrades when HaalCentraal is unreachable

- GIVEN the `brp-haalcentraal` source is seeded but dormant/unauthenticated (no
  OAuth credentials / cert)
- WHEN a client calls `GET /api/integrations/brp/person?bsn=999993653`
- THEN the response is `503 { error, details: { cause } }` with cause
  `upstream-service-down` (or `provider-auth`), and no fatal is raised
- @e2e exclude Backend degraded path — verified by PHPUnit + a live dormant-source probe, not a browser flow.

#### Scenario: BRP lookup never logs the BSN

- GIVEN any BRP lookup (success or degraded)
- WHEN the provider or controller writes a log line
- THEN the log line contains neither the raw BSN nor the request/response body
- @e2e exclude Backend privacy invariant — verified by code review + PHPUnit (logger mocked, asserted not to receive the BSN), not a browser flow.
