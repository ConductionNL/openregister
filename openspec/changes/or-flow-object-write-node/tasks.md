## 1. ObjectService — the PATCH-semantic write path

- [ ] 1.1 Complete `ObjectService::patchObject()`: accept register/schema scope and `?IUser $currentUser`, forward `_rbac` / `_multitenancy` to the save, and replace the `(int) $objectId` cast with a scoped uuid-or-slug-or-id resolve; replace its `@spec exclude` with a `@spec` reference to this capability (REQ-OWN-013)
- [ ] 1.2 Implement the merge rules in `patchObject()` — provided key wins, omitted key preserved, explicit `null` clears, nested objects merged recursively, arrays replaced wholesale — routing the merged result through the same validation, audit trail and events as `saveObject()` (REQ-OWN-013, REQ-OWN-005)
- [ ] 1.3 Widen `ObjectService::deleteObject()` with an optional explicit acting-user parameter, defaulting to today's session resolution, and forward it into `checkPermission()` so a sessionless caller can be attributed (REQ-OWN-003, REQ-OWN-012)

## 2. The node

- [ ] 2.1 Create `lib/Service/Flow/Nodes/ObjectWriteNode.php` implementing `IFlowNode` in full — id `openregister.object-write`, display name, description, icon, both scopes — with SPDX docblock and `@spec` tags, and register it in `FlowNodeRegistrationListener`'s constructor and `handle()` (REQ-OWN-001)
- [ ] 2.2 Implement `validateConfig()`: throw `UnexpectedValueException` on missing register/schema/operation, unknown operation, missing or empty `match` for `update`/`upsert`/`delete`, `fields` empty for a write operation or present for `delete`, `replace` present for `create`/`delete`, `delete` without boolean `confirmDelete: true`, non-positive `maxWrites`, and unknown `onMissing`/`onNoMatch` values (REQ-OWN-009, REQ-OWN-012, REQ-OWN-015)
- [ ] 2.3 Implement owner resolution from `context.triggeredBy` to an `IUser` — throwing a message that names the missing attribution, never falling back to a system user or `runAsSystem()` — and register/schema resolution accepting slug or uuid, throwing on unresolvable (REQ-OWN-004, REQ-OWN-010)
- [ ] 2.4 Implement `{{dotted.path}}` templating with whole-value type preservation and literal pass-through; `onMissing` defaults to omit-the-key for `fields`, while an unresolved placeholder in a `match` value always fails the item (REQ-OWN-006, REQ-OWN-011)
- [ ] 2.5 Implement composite match resolution — one or more property/value pairs ANDed, resolved through the owner's RBAC-applied read path, exactly-one required, more than one failing as ambiguous with the count named (REQ-OWN-014)
- [ ] 2.6 Implement `create` / `update` / `upsert` dispatch: `create` and the insert half of `upsert` through `saveObject()`, update-side writes through `patchObject()`, `replace: true` bypassing to `saveObject()`; an `update` matching nothing is an error (REQ-OWN-002, REQ-OWN-005)
- [ ] 2.7 Implement `delete` with its four guards — mandatory explicit match, exactly-one arity, `confirmDelete: true`, and the scoped `deleteObject()` path carrying the run owner so RBAC, audit, soft-delete, append-only and archival-immutability all apply; honour `onNoMatch` (`error` default, `skip`); expose no hard-delete or `_retentionSweep` key (REQ-OWN-012, REQ-OWN-003)
- [ ] 2.8 Enforce the per-step write cap: count every create/update/upsert/delete in one execution against `maxWrites` or the `IAppConfig` default of 1000, and on exceed throw an error naming the cap and the writes already performed — never truncate (REQ-OWN-015)
- [ ] 2.9 Emit one output item per input item carrying the written object plus identifiers (and `deleted: true`/`false` for `delete`), preserving `pairedItem` and `binary`; let every failure throw with no `catch (Throwable)` that continues, and ignore any `_rbac` / `_multitenancy` / `silent` key found in the config (REQ-OWN-007, REQ-OWN-008, REQ-OWN-016)

## 3. Tests

- [ ] 3.1 Unit-test `patchObject()`'s merge rules and resolution: omitted key preserved, explicit null clears, nested merge, array replacement, uuid resolves without the int cast, `currentUser` enforced, merged result validated (REQ-OWN-013)
- [ ] 3.2 Unit-test `validateConfig()` rejections — each missing key, unknown operation, missing match, `delete` without `confirmDelete`, `confirmDelete: "true"` as a string, bad `maxWrites` (REQ-OWN-009, REQ-OWN-012, REQ-OWN-015)
- [ ] 3.3 Unit-test templating and `onMissing`: dotted path, whole-value type preservation, literal, unresolved path omitted and never `""`, unresolved match value failing the item (REQ-OWN-006, REQ-OWN-011)
- [ ] 3.4 Unit-test composite matching — two pairs ANDed narrow to one, a single pair still works, ambiguity fails naming the count, zero matches insert for `upsert` / error for `update` / honour `onNoMatch` for `delete` (REQ-OWN-014, REQ-OWN-002)
- [ ] 3.5 Unit-test fail-closed with a null `triggeredBy` and cap-exceeded behaviour, asserting no write in the first case and no truncated success list in the second, under `onError: stop` / `continue` / `dead_letter` (REQ-OWN-004, REQ-OWN-015, REQ-OWN-008)
- [ ] 3.6 Integration-test attribution and the delete guards live: the audit trail names the run owner for both save and delete, a permission-denied write fails as the API would, a delete is a soft-delete, and an append-only schema refuses one (REQ-OWN-003, REQ-OWN-012)

## 4. Verification

- [ ] 4.1 Live-verify the three seed flows from design.md end to end through the flow UI, not the API — the composite-match `upsert` into a cache register, the guarded `delete` eviction, and the `update` recording a triage verdict
- [ ] 4.2 Run `composer check:strict`; regression-check existing `patchObject()` / `deleteObject()` callers and confirm opencatalogi and softwarecatalog flows still run unchanged

## Acceptance criteria

- `openregister.object-write` appears in the palette and is usable in both scopes.
- A flow can create, update, upsert and delete objects with no PHP written in the consuming app.
- `ObjectService::patchObject()` is a real PATCH path: omitted keys survive, explicit nulls clear, uuids resolve, and the acting user is enforced.
- Every write carries the run owner as its actor in the audit trail; RBAC and multitenancy are enforced exactly as on the API, for deletes as well as saves.
- A run with no resolvable owner writes nothing and fails with an error naming the missing attribution.
- An update never nulls a property the mapping did not mention, unless `replace: true` is set.
- No configuration shape can express a delete without an explicit match, without `confirmDelete: true`, or against more than one object.
- A step never performs more writes than its cap, and never reports success having truncated.
- A failed write always appears as a failed step in the run log; no configuration produces a green run that wrote nothing.
- No `_rbac` / `_multitenancy` / `silent` / `_retentionSweep` / hard-delete key is reachable from a flow document.
