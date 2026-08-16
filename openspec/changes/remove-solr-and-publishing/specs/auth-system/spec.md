## MODIFIED Requirements

### Requirement: Public read endpoints MUST require an authenticated user except for RBAC-public resources
Marking register/schema read endpoints `#[PublicPage]` also exposes them to fully anonymous callers. Register and schema **definitions** MUST NOT be served to anonymous callers unless the specific resource is made public through an RBAC authorization rule that grants the `public` group `read` access (optionally time-windowed via a `publicatiedatum`/`$now` match). The legacy `published`/`depublished` entity columns are removed; the anonymous-visibility gate MUST be derived from the RBAC rule evaluation, not from those columns. Authenticated users are unaffected and continue to receive results scoped by the existing RBAC / multitenancy rules.

#### Scenario: Anonymous list returns only RBAC-public registers/schemas
- **GIVEN** no Nextcloud user is resolved on the request (anonymous)
- **WHEN** the caller requests `GET /api/registers` or `GET /api/schemas`
- **THEN** the response MUST contain only resources whose authorization grants the `public` group `read` (and whose `publicatiedatum` match, if present, resolves true against `$now`)
- **AND** resources without such a public RBAC grant MUST be absent from the result set

#### Scenario: Anonymous show of a non-public resource is rejected
- **GIVEN** no Nextcloud user is resolved on the request (anonymous)
- **AND** register/schema `X` has no RBAC rule granting the `public` group `read`
- **WHEN** the caller requests `GET /api/registers/X` or `GET /api/schemas/X`
- **THEN** the response MUST be HTTP 401 (authentication required)

#### Scenario: Anonymous show of an RBAC-public resource succeeds
- **GIVEN** no Nextcloud user is resolved on the request (anonymous)
- **AND** register/schema `Y` has an authorization rule `{"read": [{"group": "public"}]}` (or a `publicatiedatum` match that resolves true against `$now`)
- **WHEN** the caller requests `GET /api/registers/Y` or `GET /api/schemas/Y`
- **THEN** the response MUST be HTTP 200 with the resource definition

#### Scenario: Time-windowed public visibility via publicatiedatum/$now
- **GIVEN** register/schema `Z` has an authorization rule `{"read": [{"group": "public", "match": {"publicatiedatum": {"$lte": "$now"}}}]}`
- **WHEN** an anonymous caller requests `Z` and its `publicatiedatum` is in the future
- **THEN** the response MUST be HTTP 401
- **AND** once `publicatiedatum` is in the past the same anonymous request MUST return HTTP 200

#### Scenario: Authenticated read is unaffected by the public gate
- **GIVEN** user `bob` is authenticated
- **WHEN** `bob` reads registers/schemas
- **THEN** the anonymous public gate MUST NOT apply — `bob` receives every resource permitted by his RBAC scope, public or not

### Requirement: The public-visibility gate MUST derive from server-side RBAC evaluation
The decision to expose a register or schema to an anonymous caller MUST be derived from server-side evaluation of the persisted RBAC authorization rules, never from client-supplied request parameters. This prevents an anonymous caller from bypassing the gate by asserting visibility in the request.

#### Scenario: Client cannot assert public state
- **GIVEN** an anonymous caller requests a non-public register `X` with a query/body parameter claiming `published=true` or `public=true`
- **WHEN** the read-visibility guard evaluates the request
- **THEN** the guard MUST ignore the client-supplied value and evaluate the entity's persisted RBAC authorization rules
- **AND** the request MUST be rejected with HTTP 401
