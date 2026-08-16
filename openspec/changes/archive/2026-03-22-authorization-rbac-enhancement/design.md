## Context

OpenRegister already has a three-level RBAC system implemented:

- **Schema-level RBAC** (`PermissionHandler`): CRUD permission checks against schema `authorization` JSON, with group matching, owner bypass, admin bypass, and conditional matching (match conditions with `$organisation` variable).
- **Property-level RBAC** (`PropertyRbacHandler`): Per-field read/update permissions defined in schema property definitions, with conditional rule evaluation via `ConditionMatcher`.
- **Database-level RBAC** (`MagicRbacHandler`): Row-level SQL filtering via QueryBuilder and raw SQL, supporting `$organisation`, `$userId`, `$now` variables, and operators like `eq`, `ne`, `in`, `contains`.
- **OAS scope generation** (`OasService`): Extracts groups from authorization blocks and maps them to OAuth2 scopes in generated OpenAPI specs.
- **Consumer identity mapping** (`AuthorizationService`): Resolves all auth methods (session, Basic, JWT, API key, OAuth2) to Nextcloud users.

The Register entity already has an `authorization` field (JSON, nullable), but it is **not currently used for permission cascade**. Schema authorization is evaluated independently.

### What is missing

1. **Register-level authorization cascade**: Register `authorization` should serve as defaults for schemas that do not define their own.
2. **Named roles**: Currently only raw group-to-action mappings exist. No reusable role definitions (e.g., "Viewer" = read+list, "Editor" = read+create+update).
3. **Delegation**: No mechanism for register/schema managers to grant sub-administrative access.
4. **Permission matrix admin UI**: No central overview of who can do what across registers/schemas.
5. **Audit logging**: RBAC policy changes (authorization edits) are not explicitly logged.
6. **LDAP/AD group mapping**: Not OpenRegister-specific -- relies entirely on Nextcloud's LDAP app. No custom mapping layer needed.

## Goals / Non-Goals

**Goals:**

- Implement register-level authorization that cascades to schemas without their own authorization config
- Add named role definitions that map to CRUD permission sets, stored as register-level config
- Add a permission matrix admin UI (Vue component) showing all authorization assignments
- Add delegation support allowing register managers to manage authorization within their scope
- Add audit logging for authorization changes
- Maintain backward compatibility with existing schema-level and property-level authorization

**Non-Goals:**

- Custom LDAP/AD mapping layer (Nextcloud's LDAP app handles group sync)
- Multi-tenant isolation (separate change: `saas-multi-tenant`)
- Authentication changes (handled by `auth-system` spec)
- Replacing the existing `authorization` JSON format (extend, not replace)
- Per-object authorization overrides (object-level is already handled by conditional match rules)

## Decisions

### Decision 1: Register authorization as cascade defaults

**Choice**: When a schema has no `authorization` block (null/empty), the system falls back to the parent register's `authorization` block. If neither has authorization, all authenticated users have access (current behavior preserved).

**Rationale**: This matches the proposal's "permission inheritance" requirement and the tender demand for hierarchical authorization. The Register entity already has an `authorization` field that is unused -- activating it requires only a change in `PermissionHandler.hasPermission()` to look up the register when schema authorization is empty.

**Alternative considered**: Merge register + schema authorization (union). Rejected because it creates confusion about which level "wins" and makes it harder to reason about effective permissions.

### Decision 2: Named roles stored in register metadata

**Choice**: Roles are stored as a JSON array in the Register entity's existing `configuration` field under a `roles` key. Each role has a name, description, and array of permitted actions.

Example:
```json
{
  "roles": [
    { "name": "viewer", "description": "Read-only access", "actions": ["read"] },
    { "name": "editor", "description": "Full CRUD access", "actions": ["read", "create", "update"] },
    { "name": "manager", "description": "Full access including delete", "actions": ["read", "create", "update", "delete"] }
  ]
}
```

In the `authorization` block, a role name can be used in place of individual actions:

```json
{
  "authorization": {
    "roles": {
      "viewer": ["public"],
      "editor": ["behandelaars"],
      "manager": ["admin"]
    }
  }
}
```

**Rationale**: Avoids new database entities. Roles are register-scoped, matching the proposal requirement. The authorization resolver expands role references to action-level permissions at evaluation time.

**Alternative considered**: Separate `Role` entity with its own mapper. Rejected because roles are lightweight configuration, not entities requiring their own CRUD lifecycle.

### Decision 3: PermissionHandler is the single authorization evaluation point

**Choice**: Extend `PermissionHandler::hasPermission()` to support:
1. Register fallback when schema authorization is null
2. Role expansion from register configuration
3. Delegation check (register managers can manage schemas within their register)

All other code continues to call `PermissionHandler` -- no new authorization service.

**Rationale**: The existing architecture already centralizes permission checks in `PermissionHandler`. Adding a new service would fragment authorization logic.

### Decision 4: Delegation via "manage" action

**Choice**: Add a `manage` action type to the authorization model. Users with `manage` permission on a register can edit authorization config for schemas within that register. Users with `manage` on a schema can assign groups to existing roles on that schema.

**Rationale**: Extends the existing CRUD action model naturally. The `manage` action is checked by the API endpoints that modify authorization configuration.

### Decision 5: Permission matrix UI as Vue component

**Choice**: Build a `PermissionMatrix.vue` component in the admin section that:
- Lists all registers and their schemas in a tree view
- Shows a matrix of groups vs. actions (read/create/update/delete/manage)
- Allows toggling permissions with immediate save
- Shows effective permissions (with register cascade highlighted)

**Rationale**: Addresses the tender requirement for "centraal beheer autorisaties" (central authorization management). Uses the existing Nextcloud Vue component patterns.

### Decision 6: Audit logging via Nextcloud activity app

**Choice**: Log authorization changes (create/update/delete of authorization config on registers and schemas) via `OCP\Activity\IManager`. Each log entry includes: who changed what, old value, new value, timestamp.

**Rationale**: Nextcloud's activity system is the standard way to log auditable events. No need for a custom audit table.

## Risks / Trade-offs

- **Performance of register fallback lookup**: Each permission check on a schema without authorization now requires loading the parent register. **Mitigation**: Cache register authorization in `PermissionHandler` per-request (similar to `MagicRbacHandler.$cachedActiveOrg`).

- **Role expansion complexity**: Authorization blocks can now contain either direct group lists or role references. **Mitigation**: Role expansion happens once at evaluation time and is cached. Clear documentation distinguishes the two formats.

- **Backward compatibility**: Existing schemas with `authorization` set continue to work unchanged. Schemas without `authorization` that previously allowed all access will now potentially be restricted if their register has authorization set. **Mitigation**: Register authorization defaults to null (no restriction). Only explicitly configured registers will cascade.

- **Migration**: No database migration needed -- Register entity already has `authorization` and `configuration` fields. The change is purely in PHP evaluation logic and Vue UI.

## Open Questions

- Should role definitions be shareable across registers (global roles), or strictly per-register? Current design is per-register for simplicity, but global roles could reduce duplication.
