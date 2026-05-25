## ADDED Requirements

### Requirement: Object display-name cache resolution endpoints
The system SHALL expose ultra-fast, aggressively cached endpoints that resolve object
UUIDs/ids to their display names so frontends can render names instead of raw UUIDs.
`NamesController` provides `index` (all names, or a subset via an `ids` query param that
accepts a comma-separated string, a JSON-array string, or a PHP array), `create` (POST
with a JSON `ids` array for sets too large for the URL), `show` (single id, HTTP 404 when
not found), `stats` (cache statistics and performance metrics), and `warmup` (clears and
re-populates the name cache). All endpoints are `@PublicPage` + `@NoAdminRequired` +
`@NoCSRFRequired` and return execution timing in the response.

#### Scenario: Bulk name lookup by ids query param
- **GIVEN** a caller requests `GET /names?ids=uuid-1,uuid-2`
- **WHEN** `NamesController::index` runs
- **THEN** it MUST return `{names: {uuid-1: ..., uuid-2: ...}, total, cached, execution_time}` from the cache handler

#### Scenario: POST bulk lookup requires an ids array
- **GIVEN** a caller POSTs a body without an `ids` array
- **WHEN** `NamesController::create` runs
- **THEN** it MUST return HTTP 400 with an example payload

#### Scenario: Single name not found
- **GIVEN** a caller requests a name for an unknown id
- **WHEN** `NamesController::show` runs
- **THEN** it MUST return HTTP 404 with `{found: false}`

#### Scenario: Manual cache warmup
- **WHEN** `NamesController::warmup` runs
- **THEN** it MUST clear the name cache, re-populate it, and return old/new cache statistics with a loaded-names count
