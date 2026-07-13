## Why

OpenRegister enforces object/schema-level RBAC and conditional field-level read stripping (`authorization.read` per property), but it has no way to mark a property as a write-only secret that is NEVER returned on read, and the ADR-063 MCP dialect has no field projection of its own (openregister#380). Because MCP tools read through OpenRegister's object read path, if OR strips sensitive fields on read then every MCP tool — and every REST/GraphQL/export consumer — inherits the redaction automatically. This closes #380 at the platform level instead of per-dialect.

## What Changes

- Add a `writeOnly: true` property mechanism (standard JSON Schema / OpenAPI keyword): a property marked `writeOnly` is stripped from every read response for everyone (including admin) but remains writable. Correct semantic for secrets/tokens.
- Harden the existing property-level `authorization.read` stripping and the new `writeOnly` stripping so both run at the single canonical render choke point AFTER caller-supplied `fields`/`extend`/`unset` selection — a caller can never re-widen a stripped property.
- Bypass both mechanisms for trusted internal reads (`_rbac === false`) and explicitly-scoped system operations (`SystemOperationContext::isActive()`), mirroring `PermissionHandler::hasPermission()`, so the app's own service/repair reads still get the full object.
- Replace the two stale `TODO: property-level RBAC` markers in `PermissionHandler` with pointers to the actual property-level implementation (`PropertyRbacHandler` + `RenderObject`).
- Ship a demonstration in a TEST schema fixture only.

No breaking changes: a property with neither `writeOnly` nor a `read` authorization is returned exactly as today.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `row-field-level-security`: field-level read stripping gains a second opt-in mechanism (`writeOnly: true`) that strips a property for all readers including admin, and formalises the fail-safe post-selection strip ordering plus the `_rbac`/system-context bypass.

## Impact

- Code: `lib/Db/Schema.php` (add `hasWriteOnlyProperties()`/`getWriteOnlyProperties()`), `lib/Service/PropertyRbacHandler.php` (strip `writeOnly` in `filterReadableProperties()`), `lib/Service/Object/RenderObject.php` (bypass on `_rbac === false`/system context; gate strip on writeOnly OR property authorization), docblock cleanup in `lib/Service/Object/PermissionHandler.php`.
- Read paths: single get, list, and nested/related expansion (all flow through `RenderObject::renderEntity`), plus GraphQL render (`GraphQLResolver`).
- Consumers: MCP (ADR-063), REST, GraphQL inherit redaction automatically. Closes openregister#380.
- Internal reads via `ObjectEntity::getObject()` (mapper-level, no render) are unaffected — the strip is render-time only.
