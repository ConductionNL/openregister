## Context

OpenRegister already enforces object/schema-level RBAC (`PermissionHandler`) and conditional per-property read stripping (`PropertyRbacHandler::filterReadableProperties()`, wired at `RenderObject::renderEntity()` ~line 1651, spec `row-field-level-security`). Two stale `TODO: Implement property-level RBAC` markers remained in `PermissionHandler` from before the property-level work moved into its own handler.

What was still missing: a way to mark a property as a **write-only secret** that is never read back by anyone, and an explicit, spec-backed guarantee that read stripping is fail-safe (cannot be re-widened by a caller and bypasses only for trusted internal reads). ADR-063's MCP dialect has no field projection (openregister#380); doing the redaction in OR's read path makes MCP, REST, GraphQL and export inherit it for free.

## Goals / Non-Goals

**Goals:**
- `writeOnly: true` on a property strips it from every read for everyone (incl. admin), while it stays writable.
- Property `authorization.read` stripping (already shipped) and `writeOnly` stripping both apply on single get, list, and nested/related expansion.
- Caller `fields`/`extend`/`unset` cannot re-surface a stripped property (fail-safe ordering).
- Trusted internal reads (`_rbac === false`, `SystemOperationContext::isActive()`) get the full object.
- Backward compatible: properties with neither mechanism are returned unchanged.
- Close openregister#380 at the platform level.

**Non-Goals:**
- No change to schema-level RBAC, MagicRbacHandler SQL filtering, or write-side property validation (`getUnauthorizedProperties`).
- No new MCP-dialect field-projection syntax (deliberately solved one level down, in OR).
- Export (`ExportService`) already gates per-property via `canReadProperty`; its handling is unchanged in this change.

## Decisions

**D1 — `writeOnly` lives in `PropertyRbacHandler::filterReadableProperties()`, stripped before the admin short-circuit.** `writeOnly` means "never returned", so admin is NOT exempt. The strip therefore runs before the existing `if isAdmin() return $object` early return. `authorization.read` stripping keeps its admin bypass (admin sees authorised fields). Alternative considered: a separate handler/step — rejected because `filterReadableProperties` is already the single render + GraphQL choke point, so one method keeps a single source of truth (ADR-011).

**D2 — Schema exposes `hasWriteOnlyProperties()` + `getWriteOnlyProperties()`, mirroring `hasPropertyAuthorization()`/`getPropertiesWithAuthorization()`.** `writeOnly` already round-trips through `SchemaMapper` (recognised property metadata field) and `OasService`, so no schema-save change is needed — only read-side helpers.

**D3 — RenderObject gates the strip on `hasPropertyAuthorization() OR hasWriteOnlyProperties()`.** The prior code only entered the block when property authorization existed, so a schema with only `writeOnly` props would have skipped stripping. The gate is widened so writeOnly-only schemas are covered.

**D4 — Security model.** See below.

## Security model

- **Fail-safe stripping.** Stripping happens server-side inside `renderEntity()` AFTER caller `fields` selection (~line 1474), `filter`, and `unset` (~line 1500) and after computed/inverse/translation enrichment, immediately before the final `setObject()`/serialisation. The stripped `$objectData` is the value serialised — no later step re-reads the pre-strip entity object.
- **Field re-widening defence.** Because the strip runs last, a caller who names a secret in `fields=apiToken` (or `_extend`) still gets it removed: field selection only chooses candidates; stripping is applied on top and cannot be overridden by request input. Enforced by ordering, backed by a unit test that feeds `filterReadableProperties` an object explicitly containing the writeOnly value (simulating a caller-selected field) and asserts it is removed.
- **System-context / `_rbac=false` bypass.** The whole strip block is skipped when `_rbac === false` OR `SystemOperationContext::isActive()`, mirroring `PermissionHandler::hasPermission()` (which returns true early for both). Rationale: those are trusted internal/system renders (repair steps, config boot, app service paths) that must see the full object, including secrets the app itself operates on. This bypass is fail-safe because it only widens explicitly-trusted call paths; a normal authenticated session always renders with `_rbac = true` (the default), so end-user responses are always stripped.
- **Internal secret consumers are unaffected.** Application code that needs a raw secret reads `ObjectEntity::getObject()` from the mapper directly and never enters `renderEntity()`, so render-time stripping cannot break internal usage regardless of the bypass.

## Risks / Trade-offs

- [A schema author sets `writeOnly` on a field the UI needs to display] → Intended semantic (write-only); documented; opt-in only.
- [Behaviour change for existing `authorization.read` schemas rendered with `_rbac=false`] → Previously the block stripped regardless of `_rbac`; now `_rbac=false` bypasses. Impact is a *widening* limited to trusted internal renders; grep confirms no user-facing controller renders with `_rbac=false` (defaults to true everywhere in the object read path). Flagged in the final report.
- [Export/OAS treat `writeOnly` differently] → Out of scope; ExportService already uses `canReadProperty`, OasService already emits `writeOnly` in the OpenAPI doc. No regression.

## Migration Plan

Additive, no data migration. Deploy the code; opt-in per property via `writeOnly: true` or property `authorization.read`. Rollback = revert the commit; stored objects are untouched (secrets were always persisted, only the read projection changes).

## Open Questions

None blocking. A future change could extend `writeOnly` stripping into `ExportService`/OAS example payloads if secret leakage via export is later deemed in-scope.
