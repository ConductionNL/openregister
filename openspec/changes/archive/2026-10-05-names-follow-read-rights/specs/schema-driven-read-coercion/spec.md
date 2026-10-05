## MODIFIED Requirements

### Requirement: Object display-name cache resolution endpoints
The system SHALL expose cached endpoints that resolve object and organisation UUIDs to
their display names so frontends can render names instead of raw UUIDs.
`NamesController` provides `index` (`GET /api/names`: all names, or a subset via an
`ids` query param that accepts a comma-separated string, a JSON-array string, or a PHP
array) and `create` (`POST /api/names` with a JSON `ids` array for sets too large for
the URL). Both require a logged-in user (`#[NoAdminRequired]`, `#[NoCSRFRequired]`,
HTTP 401 without a session) and return execution timing in the response.

A caller MUST get the name of every object they may read, and MUST get nothing for an
object they may not read. Whether the caller may read an object SHALL be decided by the
object read path itself (the RBAC and multitenancy filter of
`GET /api/objects/{register}/{schema}/{id}`), never by a separate rule of the name
cache. An organisation's name SHALL be returned only within the caller's organisation
scope (the active organisation and its parents). The answer is a partial map: a name the
caller may not see is absent, never null and never an error. The same rule SHALL apply
to names served from the in-memory and the distributed cache, and to every internal
caller of the name lookup.

#### Scenario: Bulk name lookup by ids query param
- **GIVEN** a caller who may read the objects `uuid-1` and `uuid-2` requests `GET /api/names?ids=uuid-1,uuid-2`
- **WHEN** `NamesController::index` runs
- **THEN** it MUST return `{names: {uuid-1: ..., uuid-2: ...}, total, cached, execution_time}` from the cache handler

#### Scenario: POST bulk lookup requires an ids array
- **GIVEN** a caller POSTs a body without an `ids` array
- **WHEN** `NamesController::create` runs
- **THEN** it MUST return HTTP 400 with an example payload

#### Scenario: A non-admin gets the name of an object they may read
- **GIVEN** a non-admin whose active organisation does not own object `M`
- **AND** the schema of `M` grants `read` to a group the caller is in
- **WHEN** the caller POSTs `{"ids": ["M"]}` to `/api/names`
- **THEN** the response MUST contain the name of `M`

#### Scenario: A non-admin gets nothing for an object they may not read
- **GIVEN** a non-admin and an object `S` in the caller's own organisation whose schema grants `read` to admins only
- **WHEN** the caller POSTs `{"ids": ["S"]}` to `/api/names`
- **THEN** the response MUST NOT contain `S`

#### Scenario: Admin is unchanged
- **GIVEN** an admin and an object the admin may read
- **WHEN** the admin requests its name
- **THEN** the response MUST contain the name

#### Scenario: A cached name is only served to a caller who may read the object
- **GIVEN** the name of object `M` was cached while serving a caller who may read it
- **WHEN** a caller who may not read `M` requests its name, from the same process or from another one sharing the distributed cache
- **THEN** the response MUST NOT contain `M`

#### Scenario: A cache entry without a source is resolved again
- **GIVEN** a distributed-cache entry for `M` written before names followed read rights
- **WHEN** a caller requests the name of `M`
- **THEN** the entry MUST be treated as a miss and the name resolved from the database under the rule above

#### Scenario: Single name not found
- **GIVEN** a caller requests the name of an id that resolves to nothing, or to something they may not see
- **WHEN** `NamesController::index` or `NamesController::create` runs
- **THEN** the id MUST be absent from `names`, with HTTP 200 (the single-id route `GET /api/names/{id}` no longer exists)

#### Scenario: Manual cache warmup
- **GIVEN** an admin
- **WHEN** the admin calls `POST /api/settings/cache/warmup-names`
- **THEN** the name cache MUST be cleared and re-populated (the public `warmup` route on `NamesController` no longer exists)
