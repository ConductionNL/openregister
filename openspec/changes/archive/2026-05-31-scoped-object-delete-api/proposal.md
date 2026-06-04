## Why

`ObjectService::deleteObject($uuid)` and its array-form counterpart `ObjectServiceMapperAdapter::delete(['id' => $uuid])` are unscoped: the underlying `DeleteObject` handler calls `findAcrossAllSources($uuid)` which scans every magic table. Post chain-B/C every Conduction app writes objects into per-`(register,schema)` magic tables — `oc_openregister_table_{registerId}_{schemaId}` — and a UUID collision across two magic tables is no longer prevented at the storage layer.

Discovered in openconnector while porting #733 → #843 (`SynchronizationService::updateTargetOpenRegister`): the call site has a UUID in hand but does not pass register/schema. A buggy or compromised payload from a foreign sync can delete an object in a completely unrelated app's magic table. The original #733 workaround was a per-candidate scope-check via `ObjectService::find($uuid, $register, $schema)` before the unscoped delete — works, but every caller has to remember.

Same pattern lives across the fleet (decidesk, launchpad, procest, pipelinq do equivalent housekeeping). A scoped API turns this from "every caller's responsibility" into "the storage layer refuses cross-scope deletes by construction".

## What Changes

- Extend `ObjectService::deleteObject()` with optional `Register|string|int|null $register` and `Schema|string|int|null $schema` parameters. When both are supplied the lookup MUST resolve the UUID inside that exact magic table; a UUID that exists in a different scope MUST raise `DoesNotExistException` and MUST NOT touch any row.
- Extend `DeleteObject::deleteObject()` (the underlying handler) to honour the scope: when register+schema are both non-null the handler MUST call `objectEntityMapper->find(identifier, register, schema, includeDeleted: true)` (the scoped path that hits exactly one magic table) instead of `findAcrossAllSources()`.
- Update `ObjectServiceMapperAdapter::delete()` to forward the adapter's own `(register, schema)` to `deleteObject()`. Today it drops that scope on the floor — the array form silently becomes unscoped even when the adapter is bound to a scope.
- Audit trail: when register and schema are explicitly scoped at the delete call site, the audit-trail row MUST record both, giving "who deleted what from where" instead of just "who deleted UUID X".
- Mark the unscoped form of `deleteObject($uuid)` (single-arg) as soft-deprecated in the docblock with a `@deprecated` notice that points at the scoped signature. Existing call sites keep working; no signature break.
- Out of scope: bulk delete (`deleteObjects`), cascading-delete decisions, and `deleteObjectsBySchema` / `deleteObjectsByRegister` which are already register/schema-scoped by construction.

## Capabilities

### New Capabilities
(none)

### Modified Capabilities
- `object-lifecycle`: add a new requirement that the delete pipeline MUST honour register/schema scope when both are supplied by the caller, and that a UUID found in a different scope MUST raise `DoesNotExistException` without mutating any row.

## Impact

- **lib/Service/ObjectService.php** — `deleteObject()` signature extended with optional `$register` / `$schema`; new scope wiring before the existing `rejectIfTransferred` + permission flow.
- **lib/Service/Object/DeleteObject.php** — `deleteObject()` handler picks scoped lookup vs `findAcrossAllSources()` based on whether both register+schema are provided.
- **lib/Service/ObjectServiceMapperAdapter.php** — `delete($criteria)` now forwards `$this->register` / `$this->schema` to `objectService->deleteObject()`.
- **tests/Unit/Service/Object/DeleteObjectTest.php** — scope-honouring + cross-magic-table-collision test cases.
- **No callers break** — all existing positional/named-arg calls keep the same behaviour because the new parameters default to `null` (which preserves the old `findAcrossAllSources` lookup).
- **Cross-app**: this is the API openconnector#843, decidesk, launchpad, procest, pipelinq will migrate onto; this change does not change their code, only enables the migration.
