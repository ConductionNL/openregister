## Context

OpenRegister stores objects in per-`(register,schema)` magic tables (chain-B/C: `oc_openregister_table_{registerId}_{schemaId}`). Each table has its own primary key sequence; UUIDs are application-managed and not enforced unique across tables. The `MagicMapper::findAcrossAllMagicTables()` path iterates every magic table looking for a UUID match.

Today three call paths converge on that unscoped lookup for deletes:

1. `ObjectService::deleteObject(string $uuid, bool $_rbac=true, bool $_multitenancy=true)` — single-UUID API. Calls `DeleteObject::deleteObject(register: $this->currentRegister, schema: $this->currentSchema, uuid: $uuid, ...)` but the handler internally calls `objectEntityMapper->findAcrossAllSources($uuid)` regardless of the register/schema arguments, so even when `setRegister()`/`setSchema()` were set on the service instance the lookup still scans every table.
2. `ObjectServiceMapperAdapter::delete(array $criteria)` — array-form API used by ADR-022 wrapped repositories. Internally calls `objectService->deleteObject((string) $id)` and drops `$this->register` / `$this->schema` on the floor. The adapter is built specifically to be a scoped per-`(register,schema)` view, so this is a defect: the array form silently de-scopes itself.
3. `GraphQLResolver`, `ImportService`, `ObjectsTool`, `ObjectsToolProvider`, `ObjectsController` — all call `deleteObject($uuid)` directly. Most of these have a register+schema in scope at the call site (route binding, MCP context, controller-level scope) but cannot express it through the API.

A cross-table UUID collision (two synced sources sharing IDs, two seed runs into different registers using the same UUID generator, a tenant-prefixed UUID stripped by a buggy mapper) results in deletion of an object the caller never intended to touch — and the audit trail records "deleted UUID X" without the register/schema context that would have made the wrong-scope deletion auditable.

The fix has to remain backward-compatible: dozens of call sites pass `(string $uuid)` with no scope, and we don't want a coordinated cross-app migration before the safer API is even available. The unscoped form must keep working, with a deprecation notice that gives callers a path.

## Goals / Non-Goals

**Goals:**
- Add a scoped path through `ObjectService::deleteObject()` that resolves UUIDs against exactly one magic table when both register+schema are supplied.
- Make the pipeline refuse to delete (with `DoesNotExistException`) when the UUID is not present in the requested scope, even if it exists in another scope.
- Make `ObjectServiceMapperAdapter::delete($criteria)` honour the adapter's bound `(register, schema)` instead of dropping it.
- Record `register` and `schema` on the audit-trail row for every scoped delete, so the audit log distinguishes deletes across tables that share a UUID.
- Preserve backward compatibility: every existing call site that passes `(string $uuid)` keeps the same lookup behaviour (cross-table scan).
- Provide a soft-deprecation marker (`@deprecated` in the docblock) on the unscoped form pointing at the scoped signature.

**Non-Goals:**
- Bulk delete (`deleteObjects(array $uuids)`) — same defect pattern but adds transaction-boundary concerns; a separate change.
- Cascading-delete reachability across scopes — keep that with the existing dependency tracking flow.
- A hard signature break of `deleteObject($uuid)` — out; we keep the single-arg form as a legacy fallback for at least one release cycle.
- Fleet-wide migration of caller sites (decidesk, launchpad, procest, pipelinq, openconnector#843) — that is per-app follow-up work and is tracked separately.
- Refusing the unscoped form when multiple matches exist across magic tables (the "optional" bullet in the issue) — out for this change; documented as a future tightening once all call sites are migrated, otherwise we'd break legitimate cross-table fallback callers today.

## Decisions

### Decision 1: Extend `deleteObject()` rather than adding a sibling

The issue suggested adding a new `deleteObject(string $uuid, Register|... $register, Schema|... $schema, ...)` method alongside the existing single-arg form. But `ObjectService::deleteObject` already exists with signature `(string $uuid, bool $_rbac=true, bool $_multitenancy=true)`. Adding a sibling with a near-identical name (`deleteScoped`, `deleteObjectInScope`) would force every migration to think about which method to use; extending the existing one with optional `Register|string|int|null $register=null, Schema|string|int|null $schema=null` parameters in the same position as `ObjectService::find()` is symmetric with the rest of the public API (`find()`, `findSilent()`, `findAll()`, `createObject()`, `saveObject()` all take `$register`, `$schema` parameters in the same shape).

The new signature:

```php
public function deleteObject(
    string $uuid,
    Register|string|int|null $register=null,
    Schema|string|int|null $schema=null,
    bool $_rbac=true,
    bool $_multitenancy=true
): bool
```

Because `bool $_rbac` and `bool $_multitenancy` are boolean defaults, the only existing call shapes are `deleteObject($uuid)`, `deleteObject($uuid, _rbac: false)`, etc. — all named-arg or positional-bool — none of which conflict with the two new nullable nominal-type parameters slotted in front. Positional callers with `deleteObject('abc', true, false)` keep working because the new params default to `null`. Named callers (most of the codebase) keep working unchanged. Quick scan of `lib/`, `tests/`, `appinfo/` confirms no caller uses positional `$_rbac` / `$_multitenancy`; everyone uses named arguments.

### Decision 2: Scope wiring is "both or neither"

The new behaviour kicks in only when both `$register` and `$schema` are non-null. If either is null, the lookup falls back to the legacy `findAcrossAllSources()` path. Rationale:

- A scoped delete with only register (no schema) is meaningless at the storage layer — there is no "register-only" magic table. Every magic table is keyed on `(register, schema)`.
- A scoped delete with only schema (no register) is also ambiguous: the same schema can be registered into many registers.
- Forcing "both or neither" matches `MagicMapper::find()`'s existing contract (line 6504: `if ($register !== null && $schema !== null)`).

### Decision 3: Delegate the "is it in scope?" check to `MagicMapper::find()`, do not invent a new method

The issue suggested a `findInScope()` helper. `MagicMapper::find($identifier, ?Register $register, ?Schema $schema, bool $includeDeleted, ...)` already does exactly that when both register+schema are passed — it routes to `findInRegisterSchemaTable()` which hits exactly one magic table, and raises `DoesNotExistException` if the row is absent. No new mapper method is needed; we route `DeleteObject` through the existing scoped path.

### Decision 4: Audit trail carries the resolved (register, schema)

Today `DeleteObject` records audit-trail rows via the existing audit handler with whatever register/schema context the resolved entity carries. The scoped path tightens this: when register+schema are explicitly passed at the API boundary, the audit row's `register` and `schema` columns are the API-supplied values (numeric IDs). When the unscoped path is taken, the audit row continues to carry the resolved entity's register/schema as before. This means the audit trail is at least as informative as today, and strictly more informative when callers opt in to scoped deletes.

No new audit columns required — `register` and `schema` are already on the audit row.

### Decision 5: Adapter forwards bound scope

`ObjectServiceMapperAdapter::delete(array $criteria)` is per-`(register,schema)` by construction. Today it calls `objectService->deleteObject((string) $id)` — single-arg, unscoped. Change is mechanical: forward `$this->register` and `$this->schema` to the service. This means every ADR-022 wrapped repo (`openconnector`, `decidesk`, every app using the adapter) gets the scope check for free without changing its own code.

### Decision 6: Soft-deprecate, do not hard-deprecate

Adding `@deprecated` to the single-arg form is the right marker for the next migration sweep, but emitting an `E_USER_DEPRECATED` (`trigger_error`) would flood the test suite and CI logs of every consuming app on day one. Keep it docblock-only for this change; the runtime deprecation can light up in a future release after the fleet migration.

### Alternatives considered

- **A new sibling `deleteScoped()`**: rejected — see Decision 1. Doubles the public surface and forces callers to choose; the optional-parameter extension is the path the rest of the public API already follows.
- **Auto-derive scope from `currentRegister` / `currentSchema`**: the current code already does this for permissions, but it relies on a service-instance side effect set by `setRegister()`/`setSchema()`. Past bugs (openbuild#75 / openregister#1520: `TransitionController` 500 from inherited stale schema) show this is fragile. Make the scope explicit at the call site or accept the unscoped fallback; do not silently inherit.
- **Refuse unscoped + multi-match**: rejected for this change. Pre-existing callers may legitimately rely on the cross-table fallback (notably MCP and import paths). Tightening that would be a behaviour break disguised as a defensive check. Revisit once all known call sites are migrated.

## Risks / Trade-offs

- **Risk**: A caller passes register+schema that don't match the actual object's storage location (e.g. wrong register slug), and the call fails with `DoesNotExistException` instead of silently deleting from another scope.
  - **Mitigation**: This IS the desired behaviour — failure mode is "404 not in scope" instead of "silently corrupt the other app's data". Logged at WARNING level by `DeleteObject::deleteObject()`'s existing exception handler so operators can spot wrong-scope callers in production. Test coverage in the change includes this exact scenario.

- **Risk**: An adapter built for an unbound use case (rare — most adapters are scoped) gets stricter checking via the forwarded scope.
  - **Mitigation**: `ObjectServiceMapperAdapter` always has a `$register` and `$schema`; if either is null on the adapter, the forwarded value is `null` and behaviour is unchanged from today. Verified by reading the adapter constructor.

- **Risk**: Audit-trail consumers reading the new `register` / `schema` fields on rows recorded by scoped deletes see the API-supplied numeric IDs, not the resolved-entity values.
  - **Mitigation**: They are the same value in the happy path (scoped delete resolved the entity from that exact magic table). Mismatch is impossible because the scoped lookup raises `DoesNotExistException` before any audit-trail write.

- **Trade-off**: We do not add a runtime `trigger_error` for the deprecated single-arg form. This means callers won't be nagged on every CI run; the deprecation is discoverable only by reading the docblock or IDE hint. Acceptable for now; the alternative is noisy and would block CI on dozens of apps simultaneously.

- **Trade-off**: We did not tighten the "multi-match unscoped" optional bullet. Some pre-existing callers depend on cross-table fallback; tightening today would be a behaviour break disguised as a safety check. Tracked as a candidate follow-up.

## Migration Plan

1. Land this change on `development` of openregister.
2. Apps that hit the bug (openconnector#843, others) migrate their delete call sites in a follow-up PR: `objectService->deleteObject(uuid: $uuid)` → `objectService->deleteObject(uuid: $uuid, register: $register, schema: $schema)`. No openregister-side coordination needed; the new parameters are optional.
3. The pluggable-integration-registry (hydra#309) becomes actionable: it can now require integration handlers to invoke deletes against their own register+schema, with the storage layer refusing cross-scope housekeeping.
4. Future release cycle: after the fleet has migrated, consider tightening the unscoped path to refuse multi-match (the "optional" bullet from the issue) and promote the docblock `@deprecated` to a runtime `trigger_error`. Both are separate changes.

Rollback: revert the PR — the change is additive (new optional parameters + a branch in `DeleteObject`) so a revert restores the prior behaviour without data migration.
