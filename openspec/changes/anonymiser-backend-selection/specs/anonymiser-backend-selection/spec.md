# Capability: `anonymiser-backend-selection`

## Purpose

Provide a typed state-query API and an admin selector UI for OpenRegister's anonymisation backends, plus the canonical determination rule for the *effective* recognition method given the operator's choice, per-backend configuration, and live availability. Consumer apps (notably DocuDesk's admin warning banner) read this state to render operator-facing UX without reimplementing the precedence rule locally.

## ADDED Requirements

### Requirement: A `BackendState` value object MUST exist that encodes the active method, the effective method, and per-backend availability + configuration

The system SHALL define a `BackendState` value object with the following fields: `entityRecognitionEnabled` (boolean), `activeMethod` (enum of `regex` / `presidio` / `openanonymiser` / `llm` / `hybrid`), `effectiveMethod` (same enum), and `backends` (array of per-method `BackendInfo` records). Each `BackendInfo` record SHALL carry `name` (the method enum), `available` (boolean), `configured` (boolean), `lastProbedAt` (ISO-8601 timestamp or null), and `latencyMs` (integer or null). The two flags `available` and `configured` SHALL be independent — a backend MAY be configured but unreachable (latest probe failed), or available but not configured (e.g. an installed ExApp without a matching operator-selected method).

#### Scenario: A configured-but-unreachable backend is distinguished from an unconfigured one
- **GIVEN** an admin has set `presidioApiEndpoint` to a host that no longer responds
- **WHEN** the state is queried
- **THEN** `backends[presidio].configured` is `true`
- **AND** `backends[presidio].available` is `false`
- **AND** the failure reason is reflected in the cached probe result (recoverable via the probe endpoint)

#### Scenario: An installed ExApp without operator selection is reported as available but the method is not necessarily effective
- **GIVEN** the `openanonymiser_light` ExApp is installed and enabled via AppAPI, but the admin has selected `regex` as `entityRecognitionMethod`
- **WHEN** the state is queried
- **THEN** `backends[openanonymiser].available` is `true`
- **AND** `backends[openanonymiser].configured` is `true`
- **AND** `activeMethod` is `regex`
- **AND** `effectiveMethod` is `regex`

### Requirement: `AnonymisationBackendService::getState()` MUST be the single source of truth for backend state

The system SHALL expose a typed PHP service method that returns a fully-resolved `BackendState`. All other consumers — in-process callers, the OCS endpoint, the admin UI — SHALL obtain state by calling this method, not by reading the underlying `IAppConfig` storage directly. The service MUST be the only code path that applies the `effectiveMethod` precedence rule.

#### Scenario: An in-process consumer reads state via the service
- **WHEN** another OpenRegister service calls `AnonymisationBackendService::getState()`
- **THEN** it receives a `BackendState` populated with the current `activeMethod`, the computed `effectiveMethod`, and per-backend `available` / `configured` flags consistent with the latest probe cache

#### Scenario: The OCS endpoint is a thin wrapper over the service
- **WHEN** a consumer calls `GET /api/admin/anonymisation/backend-state`
- **THEN** the controller calls `AnonymisationBackendService::getState()` and serialises the result
- **AND** there is no parallel implementation of the precedence rule in the controller layer

### Requirement: The `effectiveMethod` MUST resolve to `regex` when entity recognition is disabled OR the active backend is not usable

The system SHALL compute `effectiveMethod` according to these rules, applied in order:

1. If `entityRecognitionEnabled` is `false`, `effectiveMethod` is `regex`.
2. Else if `activeMethod` is `regex`, `effectiveMethod` is `regex`.
3. Else if `backends[activeMethod].available` is `true` AND `backends[activeMethod].configured` is `true`, `effectiveMethod` is `activeMethod`.
4. Otherwise, `effectiveMethod` is `regex`.

For the `hybrid` method specifically, `backends[hybrid].available` SHALL be the logical AND of the availability flags of the methods that `EntityRecognitionHandler::detectEntitiesHybrid` composes (currently `regex`, `presidio`, and `openanonymiser`). If any composed backend is unavailable, `hybrid` itself is unavailable.

#### Scenario: Recognition disabled forces effectiveMethod to regex
- **GIVEN** `entityRecognitionEnabled` is `false` and `activeMethod` is `hybrid`
- **WHEN** the state is queried
- **THEN** `effectiveMethod` is `regex`

#### Scenario: Active method unreachable falls through to regex
- **GIVEN** `activeMethod` is `presidio` and `backends[presidio].available` is `false`
- **WHEN** the state is queried
- **THEN** `effectiveMethod` is `regex`
- **AND** `activeMethod` remains `presidio` in the response (operator intent is preserved)

#### Scenario: Active method available and configured passes through
- **GIVEN** `activeMethod` is `openanonymiser`, the ExApp is installed and enabled, and `entityRecognitionEnabled` is `true`
- **WHEN** the state is queried
- **THEN** `effectiveMethod` is `openanonymiser`

#### Scenario: Hybrid degrades when a composed backend is unavailable
- **GIVEN** `activeMethod` is `hybrid`, `backends[regex].available` is `true`, `backends[openanonymiser].available` is `true`, but `backends[presidio].available` is `false`
- **WHEN** the state is queried
- **THEN** `backends[hybrid].available` is `false`
- **AND** `effectiveMethod` is `regex`

### Requirement: An admin-only OCS endpoint MUST expose the backend state

The system SHALL expose `GET /apps/openregister/api/admin/anonymisation/backend-state` returning the JSON serialisation of `BackendState`. The endpoint SHALL require admin group membership; non-admin callers SHALL receive HTTP 403 with the OpenRegister-standard error body shape. The response SHALL be HTTP 200 with `Content-Type: application/json` on success.

#### Scenario: Admin gets the state
- **GIVEN** a session authenticated as an admin user
- **WHEN** the client calls `GET /apps/openregister/api/admin/anonymisation/backend-state`
- **THEN** the response is HTTP 200
- **AND** the body matches the `BackendState` shape

#### Scenario: Non-admin is rejected
- **GIVEN** a session authenticated as a non-admin user
- **WHEN** the client calls `GET /apps/openregister/api/admin/anonymisation/backend-state`
- **THEN** the response is HTTP 403 with the OpenRegister-standard error body

#### Scenario: Unauthenticated caller is rejected
- **GIVEN** no session
- **WHEN** the client calls `GET /apps/openregister/api/admin/anonymisation/backend-state`
- **THEN** the response is HTTP 401 (Nextcloud's session-required path), not 200 with an anonymous state

### Requirement: A per-backend `test-connection` probe MUST be exposed via an admin OCS endpoint

The system SHALL expose `POST /apps/openregister/api/admin/anonymisation/test-connection` accepting a JSON body `{method: <method-enum>}` and returning a `ProbeResult` JSON object with fields `reachable` (boolean), `latencyMs` (integer or null), `error` (string or null, drawn from the set `timeout` / `dns_error` / `http_4xx` / `http_5xx` / `connect_refused` / `exapp_not_installed` / `exapp_disabled` / `appapi_missing` / `not_configured`), and `probedAt` (ISO-8601 timestamp). The probe SHALL bypass the state-query cache and issue a fresh probe. The endpoint SHALL be admin-only.

#### Scenario: Test connection against a reachable Presidio endpoint
- **GIVEN** `presidioApiEndpoint` points at a reachable Presidio instance
- **WHEN** the admin posts `{method: "presidio"}` to the test-connection endpoint
- **THEN** the response is `{reachable: true, latencyMs: <small int>, error: null, probedAt: ...}`

#### Scenario: Test connection against an unreachable endpoint reports the error code
- **GIVEN** `presidioApiEndpoint` is `http://nope.invalid:8080`
- **WHEN** the admin posts `{method: "presidio"}` to the test-connection endpoint
- **THEN** the response is `{reachable: false, error: "dns_error", latencyMs: null, probedAt: ...}`

#### Scenario: Test connection for `openanonymiser` queries AppAPI rather than HTTP
- **GIVEN** the `openanonymiser_light` ExApp is installed and enabled via AppAPI
- **WHEN** the admin posts `{method: "openanonymiser"}` to the test-connection endpoint
- **THEN** the response is `{reachable: true, error: null, latencyMs: 0, probedAt: ...}`
- **AND** no HTTP request was issued to any external endpoint

#### Scenario: Test connection for `regex` is a trivial success
- **WHEN** the admin posts `{method: "regex"}` to the test-connection endpoint
- **THEN** the response is `{reachable: true, error: null, latencyMs: 0, probedAt: ...}`

### Requirement: Probe results MUST be cached with a 60-second default TTL

The state-query path SHALL consume cached probe results when they are younger than the configured TTL (`anonymisation.probe_cache_ttl`, default 60s, valid range 10–600s). Cache entries older than the TTL SHALL trigger a fresh probe synchronously, and the new result SHALL be written back to the cache. The `test-connection` endpoint SHALL always issue a fresh probe regardless of cache age and write its result to the cache.

#### Scenario: Cached result within TTL is reused
- **GIVEN** the probe for `presidio` was last run 30 seconds ago and is cached as `{reachable: true, latencyMs: 12}`
- **WHEN** `getState()` is called
- **THEN** the cached result is returned and no new probe is issued

#### Scenario: Cached result older than TTL triggers fresh probe
- **GIVEN** the probe for `presidio` was last run 70 seconds ago (TTL is 60s)
- **WHEN** `getState()` is called
- **THEN** a fresh probe is issued
- **AND** the new result is written to the cache
- **AND** the new result is included in the returned `BackendState`

#### Scenario: Test-connection bypasses cache
- **GIVEN** the probe for `presidio` was cached 5 seconds ago
- **WHEN** the admin posts `{method: "presidio"}` to the test-connection endpoint
- **THEN** a fresh probe is issued (the cache is bypassed)
- **AND** the new result is written to the cache, overwriting the previous entry

### Requirement: ExApp availability MUST be derived from AppAPI / `IAppManager`, with a documented fallback when AppAPI is not detectable

For the `openanonymiser` method, `available` SHALL be `true` if and only if an OpenAnonymiser ExApp (`openanonymiser_light` OR `openanonymiser`) is installed and enabled via AppAPI as reported by `IAppManager`. If AppAPI is not present on the instance (the capability lookup fails), the probe SHALL return `{reachable: false, error: "appapi_missing"}` and the admin UI SHALL surface a hint to install AppAPI rather than silently degrading.

#### Scenario: ExApp installed and enabled
- **GIVEN** `openanonymiser_light` is installed and enabled via AppAPI
- **WHEN** the state is queried
- **THEN** `backends[openanonymiser].available` is `true`
- **AND** `backends[openanonymiser].configured` is `true`

#### Scenario: ExApp not installed
- **GIVEN** neither `openanonymiser_light` nor `openanonymiser` is installed
- **WHEN** the state is queried
- **THEN** `backends[openanonymiser].available` is `false`
- **AND** the cached probe error is `exapp_not_installed`

#### Scenario: AppAPI missing from the instance
- **GIVEN** AppAPI is not installed on the Nextcloud instance
- **WHEN** the state is queried
- **THEN** `backends[openanonymiser].available` is `false`
- **AND** the cached probe error is `appapi_missing`
- **AND** the admin UI displays a hint to install AppAPI

### Requirement: An admin settings panel MUST exist where admins pick the active method and configure per-backend endpoints

The system SHALL render an Anonimiseren section within the existing OpenRegisterAdmin settings page. The section SHALL allow an admin to select the active method from `regex` / `presidio` / `openanonymiser` / `llm` / `hybrid`, configure endpoint URLs for HTTP-based methods (`presidio`, `llm`) where applicable, see a live availability indicator per backend, observe AppAPI-derived ExApp availability for `openanonymiser`, and trigger a test-connection probe per applicable backend. All admin-visible strings SHALL be provided in NL and EN per ADR-005. The panel SHALL meet WCAG AA — focus order, contrast, and keyboard operability of the selector, inputs, and buttons.

#### Scenario: Admin picks a method and the choice persists
- **GIVEN** the admin opens the Anonimiseren section
- **WHEN** they select `openanonymiser`, save, and reload
- **THEN** `openanonymiser` remains selected
- **AND** the `entityRecognitionMethod` IAppConfig field is updated

#### Scenario: Admin presses Test connection and the indicator updates
- **GIVEN** `presidio` is selected with a configured endpoint
- **WHEN** the admin presses **Test connection** for `presidio`
- **THEN** the indicator updates with the probe result (reachable / unreachable + latency)
- **AND** no full page reload is required

#### Scenario: ExApp deep link is shown when an OpenAnonymiser ExApp is missing
- **GIVEN** `openanonymiser_light` is not installed
- **WHEN** the admin views the openanonymiser sub-section
- **THEN** the panel renders a deep link to `/settings/apps/discover/openanonymiser_light`
- **AND** the indicator shows "not configured" / "exapp_not_installed"