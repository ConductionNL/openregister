---
status: draft
---

# RBAC Scopes

## Purpose

Extend the rbac-scopes capability with the runtime effective-scope discovery API.
The existing spec covers OAS scope generation and the frontend's need to discover
permissions, but has no requirement for the `GET /api/scopes` endpoint that
computes and returns the current user's permitted `(register, schema, actions)`
tuples at runtime. Reverse-specced from the existing `ScopesController`.

## ADDED Requirements

### Requirement: Effective-Scope Discovery API

The system MUST expose an effective-scope discovery endpoint so clients
(frontend feature gates, OAuth2 token exchange, downstream apps) can learn which
`(register, schema, action)` tuples the current user may perform without probing
every endpoint. `ScopesController::index()` (`GET /api/scopes`) MUST return an
envelope `{user, isAdmin, groups, scopes}` where `scopes` is a list of
`{register, schema, actions}` entries keyed by slug. For each in-scope
(register, schema) pair the controller MUST probe `PermissionHandler::hasPermission()`
for the five canonical actions (`read`, `create`, `update`, `delete`, `list`)
and include only the granted ones, omitting pairs with no granted actions. Admin
callers MUST short-circuit to the full action vocabulary for every pair,
mirroring the admin-bypass in `PermissionHandler`. Unauthenticated callers MUST
be supported with `user: null`. Optional `register` and `schema` query
parameters (id|uuid|slug) MUST narrow the response, and resolution MUST keep the
multitenancy filter on so the endpoint cannot enumerate across tenants.

#### Scenario: Authenticated user discovers effective scopes
- **GIVEN** an authenticated non-admin user in groups `users` and `hr`
- **WHEN** a GET request is sent to `/api/scopes`
- **THEN** the response MUST include `user`, `isAdmin: false`, `groups`, and a `scopes` list
- **AND** each scope entry MUST list only the actions granted by `PermissionHandler::hasPermission()` for that (register, schema) pair
- **AND** pairs with no granted action MUST be omitted

#### Scenario: Admin receives the full action vocabulary
- **GIVEN** a caller in the `admin` group
- **WHEN** `index()` builds the response
- **THEN** `isAdmin` MUST be `true`
- **AND** `collectActionsForUser()` MUST short-circuit to `["read", "create", "update", "delete", "list"]` for every (register, schema) pair

#### Scenario: Filter discovery by register and schema
- **GIVEN** a GET request to `/api/scopes?register=decidesk&schema=meeting`
- **WHEN** `resolveRegisters()` and `resolveSchemas()` apply the filters
- **THEN** only the matching register/schema MUST be evaluated
- **AND** the multitenancy filter MUST remain on so cross-tenant enumeration is not possible

#### Scenario: Unauthenticated caller is supported
- **GIVEN** no active user session
- **WHEN** a GET request is sent to `/api/scopes`
- **THEN** the response MUST set `user: null` and `isAdmin: false`
- **AND** only scopes reachable by the `public` pseudo-group MUST be returned
