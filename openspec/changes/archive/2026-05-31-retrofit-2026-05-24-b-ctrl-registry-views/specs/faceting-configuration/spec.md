# Faceting Configuration

## Purpose
Backend-agnostic faceting for OpenRegister, plus the persisted-views REST surface that stores the
search/facet configurations users build up. This delta documents the `ViewsController` HTTP
contract — the CRUD endpoints that persist saved views (which carry facet filters and enabled
facets) — as observed in the shipped code.

## ADDED Requirements

### Requirement: Persisted views MUST be managed through an owner-scoped CRUD REST surface
`ViewsController` MUST expose CRUD endpoints for saved search views at `/api/views`:
`index` (GET), `show` (GET `/{id}`), `create` (POST), `update` (PUT `/{id}`), `patch`
(PATCH `/{id}`), and `destroy` (DELETE `/{id}`). All endpoints are `@NoAdminRequired` /
`@NoCSRFRequired` and MUST be scoped to the current user: every method MUST resolve the user via
`IUserSession::getUser()` and return HTTP 401 with `{ "error": "User not authenticated" }` when no
user is present. Persistence is delegated to `ViewService` (`findAll`, `find`, `create`,
`update`, `delete`).

#### Scenario: List the current user's views
- **GIVEN** an authenticated user with saved views
- **WHEN** `GET /api/views` is called
- **THEN** the response MUST return HTTP 200 with `{ "results": [...serialized views...], "total": <count> }`
- **AND** when `_limit` (optionally with `_page`) is supplied, results MUST be paginated client-side via `array_slice` while `total` reflects the full unsliced count

#### Scenario: Unauthenticated request rejected
- **GIVEN** no user is present in the session
- **WHEN** any `ViewsController` endpoint is called
- **THEN** the response MUST return HTTP 401 with `{ "error": "User not authenticated" }`

#### Scenario: Fetch a single view
- **GIVEN** an authenticated user owns view `abc-123`
- **WHEN** `GET /api/views/abc-123` is called
- **THEN** the response MUST return HTTP 200 with `{ "view": <serialized view> }`
- **AND** a missing view MUST return HTTP 404 with `{ "error": "View not found" }` (from `DoesNotExistException`)

#### Scenario: Create a view returns 201
- **GIVEN** an authenticated user
- **WHEN** `POST /api/views` is called with a valid body
- **THEN** the response MUST return HTTP 201 with `{ "view": <serialized view> }`
- **AND** a missing or empty `name` MUST return HTTP 400 with `{ "error": "View name is required" }`

#### Scenario: Update a view
- **GIVEN** an authenticated user owns view `abc-123`
- **WHEN** `PUT /api/views/abc-123` is called with `name` and a query/configuration body
- **THEN** the response MUST return HTTP 200 with `{ "view": <updated view> }`
- **AND** a missing view MUST return HTTP 404 with `{ "error": "View not found" }`

#### Scenario: Patch performs a partial update from the existing view
- **GIVEN** an authenticated user owns view `abc-123`
- **WHEN** `PATCH /api/views/abc-123` is called with only some fields
- **THEN** unspecified fields (`name`, `description`, `isPublic`, `isDefault`, `favoredBy`, `query`) MUST fall back to the existing view's current values
- **AND** the response MUST return HTTP 200 with `{ "view": <updated view> }`

#### Scenario: Delete a view
- **GIVEN** an authenticated user owns view `abc-123`
- **WHEN** `DELETE /api/views/abc-123` is called
- **THEN** the view MUST be deleted via `ViewService::delete()` and the response status code MUST be 204
- **AND** a missing view MUST return HTTP 404 with `{ "error": "View not found" }`

#### Scenario: Unexpected failures return 500
- **GIVEN** any `ViewsController` endpoint
- **WHEN** the underlying `ViewService` throws a non-`DoesNotExistException`
- **THEN** the error MUST be logged and the response MUST return HTTP 500 with an `{ "error": ..., "message": ... }` body

### Requirement: View create/update/patch MUST normalize the search query from either a query or configuration body
When persisting a view, `ViewsController` MUST accept the search definition under EITHER a `query`
key (used verbatim) OR a `configuration` key (the legacy frontend shape), and MUST normalize the
`configuration` shape into the canonical query envelope. `create` and `update` MUST reject a body
that provides neither.

#### Scenario: configuration body normalized to canonical query keys
- **GIVEN** a create/update body with a `configuration` object
- **WHEN** the view is persisted
- **THEN** the stored query MUST be built from `configuration` with keys `registers`, `schemas`, `source` (default `"auto"`), `searchTerms`, `facetFilters`, and `enabledFacets`, each defaulting to an empty array when absent

#### Scenario: query body used verbatim
- **GIVEN** a create/update body that supplies a `query` array (and no `configuration`)
- **WHEN** the view is persisted
- **THEN** the supplied `query` array MUST be stored as-is

#### Scenario: Neither query nor configuration provided
- **GIVEN** a create or update body with neither a valid `query` array nor a `configuration` array
- **WHEN** the endpoint is called
- **THEN** the response MUST return HTTP 400 with `{ "error": "View query or configuration is required" }`

#### Scenario: Patch preserves the existing query when none supplied
- **GIVEN** a `PATCH /api/views/{id}` body with neither `query` nor `configuration`
- **WHEN** the patch is applied
- **THEN** the view's existing stored `query` MUST be retained unchanged
