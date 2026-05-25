## ADDED Requirements

### Requirement: Aggregate Settings Administration API
The system SHALL expose an admin-gated REST surface for reading and writing the
aggregate OpenRegister settings document, its publishing options, and the
object-management dials. `SettingsController` provides `GET /api/settings` (read all),
`POST /api/settings` (update all), a `load` alias that re-reads settings, and
`updatePublishingOptions`; `ConfigurationSettingsController` provides
`getObjectSettings`/`updateObjectSettings`/`patchObjectSettings`. All endpoints delegate
to `SettingsService` and return service errors as HTTP 500 with an `error` field.

#### Scenario: Read aggregate settings
- **GIVEN** an admin requests `GET /api/settings`
- **WHEN** `SettingsController::index` runs
- **THEN** it MUST return the full settings document from `SettingsService::getSettings()`

#### Scenario: Update aggregate settings
- **GIVEN** an admin posts a settings payload to `POST /api/settings`
- **WHEN** `SettingsController::update` runs
- **THEN** it MUST pass the request params to `SettingsService::updateSettings()` and return the result

#### Scenario: Object-management settings round-trip
- **GIVEN** an admin reads object settings then writes them back
- **WHEN** `getObjectSettings` then `updateObjectSettings` run
- **THEN** each MUST return `{success: true, data: ...}` on success and `{success: false, error: ...}` with HTTP 500 on failure
- **AND** a `provider` object payload MUST be reduced to its `id` before being persisted

### Requirement: Settings Dashboard Statistics Endpoint
The system SHALL expose a statistics endpoint for the settings dashboard that reports
warning counts for objects/logs needing attention plus totals for objects, audit trails,
and search trails. `SettingsController::stats` delegates to `SettingsService::getStats()`
and `getStatistics` is an alias of `stats`. Service failures MUST surface as HTTP 422.

#### Scenario: Retrieve dashboard statistics
- **GIVEN** an admin requests the settings statistics
- **WHEN** `SettingsController::stats` runs
- **THEN** it MUST return the aggregated statistics from `SettingsService::getStats()`
- **AND** a service exception MUST produce HTTP 422 with an `error` field

#### Scenario: getStatistics aliases stats
- **WHEN** `SettingsController::getStatistics` is invoked
- **THEN** it MUST return exactly what `stats` returns

### Requirement: Database Capability Introspection Endpoint
The system SHALL expose an endpoint that introspects the active database platform,
version, and native vector-search capability, caching the result in app config and
allowing a forced refresh. `SettingsController::getDatabaseInfo` detects MySQL/MariaDB,
PostgreSQL (including installed extensions and `pgvector` presence), and SQLite, and
records a `vectorSupport` flag and a performance note. `refreshDatabaseInfo` clears the
cache and re-queries.

#### Scenario: Cached database info returned without refresh
- **GIVEN** database info was previously cached in app config
- **WHEN** `getDatabaseInfo` is called without `?refresh=true`
- **THEN** it MUST return the cached payload with `fromCache: true`

#### Scenario: PostgreSQL with pgvector reports vector support
- **GIVEN** the active database is PostgreSQL with the `vector` extension installed
- **WHEN** `getDatabaseInfo` runs with refresh
- **THEN** the response MUST report `type: "PostgreSQL"`, `vectorSupport: true`, and list installed extensions

#### Scenario: Forced refresh re-queries the database
- **WHEN** `refreshDatabaseInfo` is called
- **THEN** it MUST delete the `databaseInfo` app-config key and return freshly queried info with `fromCache: false`

### Requirement: Application Version Reporting Endpoint
The system SHALL expose an endpoint returning OpenRegister version information.
`SettingsController::getVersionInfo` delegates to `SettingsService::getVersionInfoOnly()`
and returns HTTP 500 with an `error` field on failure.

#### Scenario: Retrieve version info
- **WHEN** `getVersionInfo` is called
- **THEN** it MUST return the version payload from `SettingsService::getVersionInfoOnly()`

### Requirement: Connection Keep-Alive Heartbeat Endpoint
The system SHALL expose a lightweight heartbeat endpoint that keeps HTTP connections
alive during long-running operations (imports, exports, bulk operations) to prevent
gateway timeouts. `HeartbeatController::heartbeat` performs minimal processing and is
annotated `@NoAdminRequired` + `@NoCSRFRequired`.

#### Scenario: Heartbeat returns alive status
- **GIVEN** a long-running operation periodically calls the heartbeat endpoint
- **WHEN** `HeartbeatController::heartbeat` runs
- **THEN** it MUST return HTTP 200 with `{status: "alive", timestamp: <unix>, message: ...}`
- **AND** it MUST require no admin role and no CSRF token

### Requirement: Subsystem Administration Dials
The system SHALL expose admin-gated operational dials for cache, object validation, and
security rate limiting. `CacheSettingsController` provides cache statistics, granular
cache clearing (`all`/`object`/`schema`/`facet`/`distributed`/`names`), names-cache
warmup, a configurable warmup interval (`0` to disable, otherwise `>= 300s`), and Nextcloud
app-store cache invalidation. `ValidationSettingsController` provides whole-corpus object
validation, mass re-save validation, and a memory-usage prediction. `SecuritySettingsController`
provides clearing of IP, user, and combined rate limits.

#### Scenario: Clear a specific cache type
- **GIVEN** an admin posts `{type: "facet"}` to the cache-clear endpoint
- **WHEN** `CacheSettingsController::clearCache` runs
- **THEN** it MUST delegate to `SettingsService::clearCache('facet')` and return the result

#### Scenario: Warmup interval validation
- **GIVEN** an admin posts `{interval: 120}` to set the warmup interval
- **WHEN** `setWarmupInterval` runs
- **THEN** it MUST reject with HTTP 422 because a non-zero interval below 300 seconds is invalid

#### Scenario: Mass validation re-saves objects
- **WHEN** `ValidationSettingsController::massValidateObjects` runs with `mode`/`batchSize`/`maxObjects`
- **THEN** it MUST delegate to `SettingsService::massValidateObjects()` and return per-batch save statistics
- **AND** invalid parameters MUST produce HTTP 400

#### Scenario: Clear IP rate limit requires an IP
- **WHEN** `SecuritySettingsController::clearIpRateLimits` runs without an `ip` param
- **THEN** it MUST return HTTP 400 with an `error` field
- **AND** a valid request MUST delegate to `SecurityService::clearIpRateLimits()` and log the admin action

### Requirement: Integration Administration Endpoints
The system SHALL expose admin-gated endpoints for configuring external integration
credentials and connections. `N8nSettingsController` provides n8n connection settings
read/write, connection testing, project initialization, and workflow listing.
`ApiTokenSettingsController` provides GitHub/GitLab API token read/write and validation
testing.

#### Scenario: Test n8n connection
- **WHEN** `N8nSettingsController::testN8nConnection` runs
- **THEN** it MUST attempt a connection against the configured n8n endpoint and return the outcome

#### Scenario: Validate a stored GitHub token
- **WHEN** `ApiTokenSettingsController::testGitHubToken` runs
- **THEN** it MUST validate the configured GitHub token and report whether it is accepted
