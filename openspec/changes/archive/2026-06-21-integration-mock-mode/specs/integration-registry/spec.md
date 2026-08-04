# integration-registry

## ADDED Requirements

### Requirement: External-strategy sources MAY be flagged mock to short-circuit the upstream call

`ExternalIntegrationRouter` SHALL support an opt-in, per-source **mock mode** so
an external integration leaf is demonstrably functional end-to-end without real
upstream credentials, while leaving the real call path unchanged for non-mock
sources.

When the resolved OpenConnector source carries `configuration.mock === true`,
`ExternalIntegrationRouter::call()` and `::callWithMeta()` SHALL short-circuit
and return the canned `configuration.mockResponse` body WITHOUT performing a
real CallService/HTTP call. The canned body SHALL be shaped exactly like the
real upstream response so the leaf's existing extractor and the consuming app's
mappers consume it unchanged.

- `call()` SHALL return `configuration.mockResponse` (an empty `{}` body when it
  is absent or non-array — never a fatal/500).
- `callWithMeta()` SHALL return `{ body, meta }` where `body` is the canned body
  and `meta` is `{ status, durationMs, correlationId, headers }` synthesized
  with defaults (`status:200`, a non-zero `durationMs`, a fresh fake
  `correlationId`) and overridable via `configuration.mockMeta`. No request or
  response body SHALL ever appear in `meta`.
- The router SHALL read only the source's transport `configuration` to detect
  the flag and resolve the fixture — never a credential.
- For a source WITHOUT `configuration.mock`, `call()` / `callWithMeta()` SHALL
  behave byte-for-byte as before (real CallService call, the same upstream-status
  assertion, the same degraded `ProviderUnavailableException` classification).

#### Scenario: a mock-flagged source returns the canned body without a real call

- **GIVEN** an OpenConnector source resolved with `configuration.mock === true`
  and a `configuration.mockResponse`
- **WHEN** an external leaf calls `ExternalIntegrationRouter::call()`
- **THEN** the canned `mockResponse` is returned and the OpenConnector
  `CallService` is never invoked
- @e2e exclude Backend router short-circuit; verified by PHPUnit with an exploding CallService stub, not a browser flow.

#### Scenario: callWithMeta returns the canned body plus synthesized audit meta

- **GIVEN** a mock-flagged `brp-haalcentraal` source with a canned `personen`
  `mockResponse`
- **WHEN** the BRP leaf calls `ExternalIntegrationRouter::callWithMeta()`
- **THEN** the result is `{ body, meta }` with `meta.status === 200`, a non-zero
  `meta.durationMs`, and a non-null `meta.correlationId` (overridable via
  `configuration.mockMeta`), and no real call is made
- @e2e exclude Backend router short-circuit + meta synthesis; verified by PHPUnit, not a browser flow.

#### Scenario: a mock flag without a fixture yields an empty body, never a fatal

- **GIVEN** a source flagged `configuration.mock === true` but with no
  `configuration.mockResponse`
- **WHEN** `ExternalIntegrationRouter::call()` runs
- **THEN** an empty `{}` body is returned and the leaf's extractor yields an
  empty result set (no fatal/500)
- @e2e exclude Backend defensive path; verified by PHPUnit, not a browser flow.

#### Scenario: a non-mock source still uses the real call path unchanged

- **GIVEN** a source WITHOUT `configuration.mock`
- **WHEN** `ExternalIntegrationRouter::call()` runs
- **THEN** the OpenConnector `CallService` is invoked and the decoded upstream
  body is returned, with the same upstream-status assertion and degraded-cause
  classification as before mock mode existed
- @e2e exclude Backend real-path regression guard; verified by PHPUnit, not a browser flow.
