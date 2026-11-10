# integration-person-lookup

## ADDED Requirements

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
