# RBAC Scopes

## Why

OpenRegister's authorization model has to satisfy three different consumers at once: human admins who declare rules, the runtime that enforces them on every read/write, and the OAS generator that documents them so SDKs and reverse proxies can authorize requests upfront. The first-pass implementation enforced the rules but didn't expose them — Swagger UI showed every endpoint as authenticated-only, list endpoints called `hasPermission()` once per row at full cost, and there was no way for a client to discover what scopes it actually has. This change makes the existing 3-level RBAC introspectable, cacheable, and OAS-discoverable, then pushes toward role definitions, custom scopes, and OAuth2 token integration.

## What Changes

- Document the four-level scope hierarchy (Register → Schema → Object → Property) and the five-action vocabulary (`read`, `create`, `update`, `delete`, `list`) as the canonical RBAC contract.
- Resolve dynamic variables (`$now`, `$userId`, `$organisation`, `@self.created`, `@self.owner`) at evaluation time via `ConditionMatcher`.
- Bind rules to Nextcloud groups via `IGroupManager::getUserGroupIds`; admin bypass evaluated first in `PermissionHandler`.
- Cascade authorization: `Schema::resolveAuthorization()` falls through to `Register::getAuthorization()` when a schema declares none.
- Compose RBAC + multi-tenancy via `MultiTenancyTrait`: tenant scope first, RBAC rules on top.
- Add `lib/Controller/ScopesController.php` exposing `GET /apps/openregister/api/scopes` (with optional `?register=`/`?schema=`) so clients can discover the scope vocabulary they have access to.
- Add per-request memoisation in `PermissionHandler::hasPermission()` keyed on `(schemaId, action, userId|null, objectOwner|null, objectUuid|null)`; transient/in-memory entities (no UUID) bypass the cache; `_rbac=false` short-circuits before the cache lookup; expose `clearPermissionCache()` for long-running CLI processes.
- Extend `OasService::applyRbacToOperation()` to emit per-operation `security: [{ "oauth2": [groups] }, { "basicAuth": [] }]` blocks alongside the existing 403 response, making the OAS a machine-readable access audit.
- Populate `components.securitySchemes.oauth2.flows.authorizationCode.scopes` from the union of every schema's groups so SDKs can request the right token.
- Add named role definitions on Register (`roles: {roleName: [groupIds]}`) plus role-expansion logic in PermissionHandler so authorization blocks reference roles instead of bare group IDs.
- Add custom scope definitions so apps can extend the vocabulary beyond the fixed action × entity-level matrix.
- Integrate with Nextcloud's OAuth2 layer: translate RBAC rules into OAuth2 token scope requirements so tokens carry the same authorization the session would.

## Problem
Validate and extend OpenRegister's existing three-level RBAC system. The core RBAC is already implemented via PermissionHandler (schema-level), MagicRbacHandler (row-level SQL filtering), and PropertyRbacHandler (field-level).

## Proposed Solution
Extend the existing implementation with 13 additional requirements.
