## ADDED Requirements

### Requirement: Register and schema read endpoints MUST remain reachable when OpenRegister is restricted to a user group

When an administrator limits OpenRegister to specific Nextcloud groups (the *"Limit app to groups"* setting, i.e. `occ app:enable openregister --groups <group>`), Nextcloud blocks every non-`#[PublicPage]` route for users outside those groups (dispatch is gated by `IAppManager::isEnabledForUser()`). Because OpenRegister's register, schema, and object **read** endpoints are consumed by other apps on behalf of every user, those read endpoints MUST be marked `#[PublicPage]` so they survive the app-group restriction. Write/management endpoints MUST remain non-public so that the same restriction continues to limit management to the group.

#### Scenario: A user outside the restriction group can read registers and schemas
- **GIVEN** OpenRegister is enabled only for group `data-engineers`
- **AND** user `alice` is authenticated but is **not** a member of `data-engineers`
- **WHEN** `alice` calls `GET /api/registers` and `GET /api/schemas`
- **THEN** both requests MUST succeed (HTTP 200), returning the registers/schemas she is permitted to see under RBAC
- **AND** `GET /api/objects/{register}/{schema}` MUST also succeed, scoped by `ObjectService` RBAC/multitenancy

#### Scenario: A user outside the restriction group cannot manage registers or schemas
- **GIVEN** OpenRegister is enabled only for group `data-engineers`
- **AND** user `alice` is authenticated but is **not** a member of `data-engineers`
- **WHEN** `alice` calls `POST`, `PUT`, `PATCH`, or `DELETE` on `/api/registers` or `/api/schemas`
- **THEN** the request MUST NOT succeed — it is either blocked by the Nextcloud app-group restriction (non-public route) or rejected by the register/schema write authorization check (HTTP 403)

### Requirement: Public read endpoints MUST require an authenticated user except for published resources

Marking register/schema read endpoints `#[PublicPage]` also exposes them to fully anonymous callers. Register and schema **definitions** MUST NOT be served to anonymous callers unless the specific resource is published. A resource is *published* when its `published` field is set (non-null) and its `depublished` field is null. Authenticated users are unaffected and continue to receive results scoped by the existing RBAC / multitenancy rules.

#### Scenario: Anonymous list returns only published registers/schemas
- **GIVEN** no Nextcloud user is resolved on the request (anonymous)
- **WHEN** the caller requests `GET /api/registers` or `GET /api/schemas`
- **THEN** the response MUST contain only resources whose `published` is non-null and `depublished` is null
- **AND** unpublished resources MUST be absent from the result set

#### Scenario: Anonymous show of an unpublished resource is rejected
- **GIVEN** no Nextcloud user is resolved on the request (anonymous)
- **AND** register/schema `X` is not published
- **WHEN** the caller requests `GET /api/registers/X` or `GET /api/schemas/X`
- **THEN** the response MUST be HTTP 401 (authentication required)

#### Scenario: Anonymous show of a published resource succeeds
- **GIVEN** no Nextcloud user is resolved on the request (anonymous)
- **AND** register/schema `Y` is published (`published` set, `depublished` null)
- **WHEN** the caller requests `GET /api/registers/Y` or `GET /api/schemas/Y`
- **THEN** the response MUST be HTTP 200 with the resource definition

#### Scenario: Authenticated read is unaffected by the published gate
- **GIVEN** user `bob` is authenticated
- **WHEN** `bob` reads registers/schemas
- **THEN** the published/unpublished gate MUST NOT apply — `bob` receives every resource permitted by his RBAC scope, published or not

### Requirement: The published-visibility gate MUST derive from server-side entity state

The decision to expose a register or schema to an anonymous caller MUST be derived from the persisted `published`/`depublished` fields on the entity, never from client-supplied request parameters. This prevents an anonymous caller from bypassing the gate by asserting visibility in the request.

#### Scenario: Client cannot assert published state
- **GIVEN** an anonymous caller requests an unpublished register `X` with a query/body parameter claiming `published=true`
- **WHEN** the read-visibility guard evaluates the request
- **THEN** the guard MUST ignore the client-supplied value and use the entity's persisted `published`/`depublished` fields
- **AND** the request MUST be rejected with HTTP 401
