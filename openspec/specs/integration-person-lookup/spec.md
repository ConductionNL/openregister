# integration-person-lookup Specification

## Purpose
Defines the object-independent BRP/HaalCentraal person-lookup leaf
(`GET /api/integrations/brp/person`, backed by `BrpPersoonProvider` +
`ExternalIntegrationRouter`) and the Wet-BRP audit metadata it relays — the
upstream `X-Correlation-ID`, round-trip duration, and HTTP status — so a
consuming app can persist a legally-complete `brpLookupVerzoek` record while
the BSN stays out of logs, responses, and metadata.

## Requirements

### Requirement: BRP person lookup relays Wet-BRP audit metadata

The BRP/HaalCentraal person-lookup leaf SHALL surface the upstream response
audit metadata alongside the person payload so the consuming app can persist
the legally-required Wet-BRP `brpLookupVerzoek` fields.

On success, `GET /api/integrations/brp/person?bsn=` (backed by
`BrpPersoonProvider::lookupByBsn()` via
`ExternalIntegrationRouter::callWithMeta()`) SHALL return
`{ results, total, meta }`, where `meta` is
`{ correlationId, durationMs, status }`:

- `correlationId` — the upstream `X-Correlation-ID` response header
  (case-insensitive; `null` when absent). The consumer persists it as
  `haalcentraalCorrelationId`.
- `durationMs` — the OpenConnector-measured round-trip duration in
  milliseconds. The consumer persists it as `responseDuurMs`.
- `status` — the upstream HTTP status code. The consumer persists it as the
  response status (`responseStatus` / `responseCode`).

The BSN SHALL NEVER appear in `meta`, in logs, or anywhere other than the
upstream request body. The degraded contract is unchanged: when the
`brp-haalcentraal` source is missing/unconfigured or HaalCentraal is
unreachable, the endpoint SHALL return `503 { error, details: { cause } }`
(cause one of `openconnector-down`, `openconnector-source-missing`,
`provider-auth`, `upstream-service-down`) and SHALL never raise a fatal; the
provider SHALL return `{ unavailable, cause, results: [], total: 0 }`.

The router SHALL provide `callWithMeta()` as an additive superset of `call()`
that returns `{ body, meta }` with `meta = { status, durationMs, correlationId,
headers }` extracted from the OpenConnector `CallLog` response payload
(`statusCode`, `responseTime`, `headers`) — reading only transport metadata,
never the response body. `call()` SHALL remain unchanged so existing leaves keep
their body-only contract.

#### Scenario: lookup surfaces correlation id, duration, and status

- **GIVEN** a configured, enabled OpenConnector `brp-haalcentraal` source
  pointing at a reachable HaalCentraal that returns an `X-Correlation-ID`
  response header
- **WHEN** an authenticated user calls `GET /api/integrations/brp/person?bsn=…`
- **THEN** the response is `200 { results, total, meta }` where
  `meta.correlationId` equals the upstream `X-Correlation-ID`, `meta.durationMs`
  is the round-trip duration in milliseconds, and `meta.status` is the upstream
  HTTP status
- @e2e exclude Backend API endpoint resolved through OpenConnector; verified by PHPUnit, not a browser flow.

#### Scenario: BSN never appears in the audit metadata

- **GIVEN** a successful lookup
- **WHEN** the `meta` object is serialised
- **THEN** it contains only `correlationId`, `durationMs`, and `status` — and
  never the BSN or any response body field
- @e2e exclude Backend privacy invariant; verified by PHPUnit, not a browser flow.

#### Scenario: correlation header is matched case-insensitively

- **GIVEN** an upstream that returns the header as `x-correlation-id` (lower case)
- **WHEN** the lookup completes
- **THEN** `meta.correlationId` is populated from that header
- @e2e exclude Backend header handling; verified by PHPUnit, not a browser flow.

#### Scenario: degraded path is unchanged and carries no meta

- **GIVEN** no OpenConnector `brp-haalcentraal` source exists (or OpenConnector
  is disabled)
- **WHEN** `GET /api/integrations/brp/person?bsn=…` is called
- **THEN** the response is `503 { error, details: { cause } }` with a cause of
  `openconnector-down` or `openconnector-source-missing`, no fatal/500, and the
  provider returns `{ unavailable, cause, results: [], total: 0 }`
- @e2e exclude Backend degradation path; verified by PHPUnit, not a browser flow.

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

### Requirement: BRP count of residents at an address object

`BrpPersonProvider::countResidentsAtAddressObject(string $addressObjectId)` SHALL answer how many people the BRP registers at an address object, as a number only. It SHALL ask Haal Centraal `/personen` with a `ZoekMetAdresseerbaarObjectIdentificatie` body carrying the 16-digit `adresseerbaarObjectIdentificatie` and a `fields` list of one field, and answer `{ total, meta }` where `meta` is the Wet-BRP audit metadata `{ correlationId, durationMs, status }`.

The provider SHALL ask only when the `brp-haalcentraal` source carries `configuration.residentCount` set to the boolean `true`. Otherwise it SHALL answer `{ unavailable: true, cause: 'resident-count-not-enabled' }` without calling the registry.

The call SHALL reach the connector with `withholdBodies`, so the call log keeps no request or response body. A connector that cannot withhold bodies SHALL NOT be called, and the answer is `{ unavailable: true, cause: 'connector-cannot-withhold-bodies' }`.

The answer SHALL never hold a name, a BSN, a person object or the address object id, and the provider SHALL never log the address object id. A malformed id SHALL answer `{ unavailable: true, cause: 'invalid-identifier' }` without a call. A missing or unreachable source SHALL degrade to `{ unavailable: true, cause }` as `lookupByBsn` does.

#### Scenario: the count of residents at an address object

- GIVEN the `brp-haalcentraal` source carries `configuration.residentCount: true`
- AND the BRP registers three people at address object `0518010000123456`
- WHEN portaliq asks `countResidentsAtAddressObject('0518010000123456')`
- THEN the provider posts `type='ZoekMetAdresseerbaarObjectIdentificatie'`, the id in `adresseerbaarObjectIdentificatie` and one field, with `withholdBodies`
- AND it answers `{ total: 3, meta }` and nothing about the three people
- @e2e exclude Backend provider method, verified by PHPUnit (router mocked); portaliq's Mijn gegevens is the live pass.

#### Scenario: the setting is off

- GIVEN the source carries no `configuration.residentCount`, or a value other than `true`
- WHEN the count is asked
- THEN the answer is `{ unavailable: true, cause: 'resident-count-not-enabled' }` and the registry is not called
- @e2e exclude Backend refusal path, verified by PHPUnit.

#### Scenario: no number and no address in the answer or the log

- GIVEN any count, answered or degraded
- WHEN the provider answers and logs
- THEN the answer holds only `total` and `meta`, or `unavailable` and `cause`
- AND no log line holds the address object id or a burgerservicenummer
- @e2e exclude Backend privacy invariant, verified by PHPUnit (logger asserted).

### Requirement: a call may be recorded without its bodies

`ExternalIntegrationRouter` SHALL honour a `withholdBodies: true` call option by passing `withholdBodies: true` to the connector's `CallService::call`, and SHALL never pass that option on as a request option. When the connector's `call` has no `withholdBodies` parameter, the router SHALL NOT call it and SHALL fail with cause `connector-cannot-withhold-bodies`, logged below error level (an older connector is a version gap, not an outage). `sourceConfiguration(provider)` SHALL answer the provider's source `configuration` array and fail like `call()` when the connector or the source is missing.

#### Scenario: the connector records the call without bodies

- GIVEN integriq's CallService, whose `call` takes `withholdBodies`
- WHEN a provider calls with `withholdBodies: true`
- THEN the CallService receives `withholdBodies: true` and the request options do not carry it
- @e2e exclude Backend transport option, verified by PHPUnit (CallService stand-in).

#### Scenario: an older connector is not called

- GIVEN a CallService whose `call` has no `withholdBodies` parameter
- WHEN a provider calls with `withholdBodies: true`
- THEN the CallService is not called and the router fails with `connector-cannot-withhold-bodies`, without an error log line
- @e2e exclude Backend refusal path, verified by PHPUnit.
