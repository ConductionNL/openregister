## ADDED Requirements

### Requirement: The flow palette offers an object-write node (REQ-OWN-001)

OpenRegister SHALL contribute a built-in node type `openregister.object-write`
through `RegisterFlowNodesEvent`, the same registration path every other
built-in and every contributed node uses. It SHALL implement `IFlowNode` in
full — `getId()`, `getDisplayName()`, `getDescription()`, `getIcon()`,
`isAvailableForScope()`, `validateConfig()` and `execute()`.

The node SHALL be offered in both `IManager::SCOPE_ADMIN` and
`IManager::SCOPE_USER`. It grants no privilege of its own: every write it
performs is subject to the run owner's own permissions (REQ-OWN-003), so
restricting it by scope would restrict authoring, not access.

#### Scenario: The node appears in the palette

- **GIVEN** an OpenRegister instance with the flow engine enabled
- **WHEN** the node catalogue is read
- **THEN** an entry with id `openregister.object-write` is present, carrying a
  display name, a description and an icon

#### Scenario: The node is offered in the user scope

- **WHEN** the palette is requested for `IManager::SCOPE_USER`
- **THEN** `openregister.object-write` is offered

### Requirement: The node writes one object per item (REQ-OWN-002)

The node SHALL act once per input item. For each item it SHALL resolve the
configured `register` and `schema` and perform exactly one write through
`ObjectService`, using the method that matches the configured `operation`.

`operation` SHALL be one of:

- `create` — always insert a new object; never look for an existing one.
  Persisted through `ObjectService::saveObject()`.
- `update` — resolve an existing object via the configured match
  (REQ-OWN-014) and patch the mapped fields onto it through
  `ObjectService::patchObject()` (REQ-OWN-005, REQ-OWN-013); when no object
  matches, this is an error (REQ-OWN-008), not a silent insert.
- `upsert` — resolve via the configured match; patch the match when one
  exists, insert through `saveObject()` when none does.
- `delete` — resolve via the configured match and remove the object through
  `ObjectService::deleteObject()`, subject to every guard in REQ-OWN-012.

The node SHALL NOT use `saveObjects()`, `deleteObjects()` or any other bulk
path. Iteration is per item so that one item's failure is attributable to that
item and the engine's per-step `onError` policy applies to a comprehensible
unit of work. The per-step write cap of REQ-OWN-015 bounds how many such
writes one execution may perform.

#### Scenario: Each item produces one write

- **GIVEN** a step configured with `operation: create` receiving three items
- **WHEN** the step executes
- **THEN** three objects are created and three items are returned

#### Scenario: Upsert inserts when nothing matches

- **GIVEN** a step configured with `operation: upsert` and a match on
  `sourceId`
- **AND** no object in the target register/schema has that `sourceId`
- **WHEN** the step executes
- **THEN** a new object is created

#### Scenario: Upsert updates when a match exists

- **GIVEN** the same step
- **AND** exactly one object in the target register/schema has that `sourceId`
- **WHEN** the step executes
- **THEN** that object is patched and no new object is created

#### Scenario: The operation enum is exactly four values

- **GIVEN** the node's declared configuration contract
- **WHEN** the available operations are listed
- **THEN** they are exactly `create`, `update`, `upsert` and `delete`

### Requirement: The write executes as the flow run's owner (REQ-OWN-003)

The node SHALL perform every write — create, update, upsert and delete —
through the ordinary `ObjectService` path as the user identified by the run's
`context.triggeredBy`, passing that user explicitly as the acting user rather
than relying on an ambient `IUserSession`.

`saveObject()` and `patchObject()` SHALL receive that user as `currentUser`.
`deleteObject()` SHALL receive it through the explicit acting-user parameter
added by REQ-OWN-013, because a flow run is a non-HTTP caller with no session
and today's `deleteObject()` resolves the permission subject from the session
by passing `userId: null` into `checkPermission()`.

RBAC, the audit trail and multitenancy SHALL apply unchanged. The node SHALL
NOT pass `_rbac: false`, SHALL NOT pass `_multitenancy: false`, SHALL NOT pass
`_retentionSweep: true`, and SHALL NOT expose any configuration key that would
let a flow author request any of them. A write the owner could not perform
through the API SHALL fail here for the same reason and with the same error.

A flow is authored data, not code. If a flow could write past RBAC, then
authoring a flow would be a privilege escalation, and the flows-first
programme would be handing every app a way around the permission model it
exists to centralise.

#### Scenario: The write is attributed to the run owner

- **GIVEN** a run whose `triggeredBy` is `alice`
- **WHEN** an object-write step creates an object
- **THEN** the resulting audit trail entry records `alice` as the actor

#### Scenario: The delete is attributed to the run owner

- **GIVEN** a run whose `triggeredBy` is `alice` and no user session
- **WHEN** an object-write step deletes an object
- **THEN** the permission check is evaluated against `alice`
- **AND** the audit trail entry records `alice` as the actor

#### Scenario: A write the owner may not perform is refused

- **GIVEN** a run whose `triggeredBy` is a user without create permission on
  the target schema
- **WHEN** the step executes
- **THEN** the step fails with the same permission error the API would return
- **AND** no object is created

#### Scenario: Multitenancy is not bypassed

- **GIVEN** a run whose owner belongs to organisation A
- **AND** a step configured with `operation: update` matching an object owned
  by organisation B
- **WHEN** the step executes
- **THEN** the match does not resolve and the step fails
- **AND** organisation B's object is unchanged

### Requirement: A run without a resolvable owner fails closed (REQ-OWN-004)

The node SHALL throw when `context.triggeredBy` is absent, empty, or does not
resolve to a user account. It SHALL NOT write anonymously, SHALL NOT
substitute a system, admin or background user, SHALL NOT wrap its work in
`ObjectService::runAsSystem()`, and SHALL NOT fall back to a user named in its
own configuration.

This is a live gap, not a hypothetical: `FlowMcpToolProvider::runFlow()` queues
runs without a `user:` argument, so MCP-triggered runs currently carry a null
`triggeredBy` (ConductionNL/openregister#2158). Until that is fixed such runs
cannot write, and that is the correct outcome — an unattributable write into a
register is worse than a failed run, and an unattributable *delete* is worse
again, because there is no row left to explain it.

The error message SHALL name the missing attribution as the cause, so the
author is not sent hunting through RBAC configuration for a defect that is
about the trigger.

#### Scenario: A run with no owner refuses to write

- **GIVEN** a run whose `context.triggeredBy` is null
- **WHEN** an object-write step executes
- **THEN** the step fails with an error naming the missing run owner
- **AND** no object is created, updated or deleted

#### Scenario: A configured owner cannot substitute for the run owner

- **GIVEN** a run whose `context.triggeredBy` is null
- **AND** a step configuration that names a user
- **WHEN** the step executes
- **THEN** the step still fails and the configured user is not used

### Requirement: Update and upsert carry existing fields forward (REQ-OWN-005)

The node SHALL persist `operation: update` and the update half of
`operation: upsert` through `ObjectService::patchObject()` (REQ-OWN-013), whose
merge semantics leave properties the mapping did not name untouched.

This exists because `ObjectService::saveObject()` is PUT-semantic: a property
absent from the payload is written as null, not left alone. A field mapping is
by nature partial — an author maps the two fields their flow computed, not all
forty on the schema.

The node SHALL support an explicit `replace: true` opt-in that bypasses
`patchObject()` and sends only the mapped fields through `saveObject()`, for
the case where full replacement is intended. `replace` SHALL default to
`false`. `replace` SHALL have no meaning for `create` or `delete` and
`validateConfig()` SHALL reject it there.

This is a recurring fleet defect class, not a theoretical risk: partial-payload
saves against a PUT-semantic API have silently nulled live data before. The
default must be the safe one, and it must be safe in the service so that the
next caller inherits it rather than re-deriving it.

#### Scenario: Unmapped fields survive an update

- **GIVEN** an existing object with `title: "Alpha"` and `status: "open"`
- **AND** a step with `operation: update` mapping only `status`
- **WHEN** the step executes
- **THEN** the saved object has the new `status`
- **AND** `title` is still `"Alpha"`

#### Scenario: Replacement is opt-in and honoured

- **GIVEN** the same existing object
- **AND** the same step with `replace: true`
- **WHEN** the step executes
- **THEN** the write goes through `saveObject()` with only the mapped fields
- **AND** `title` is no longer set

#### Scenario: Replace defaults to false

- **GIVEN** a step configuration with no `replace` key
- **WHEN** the step executes an update
- **THEN** the write goes through `patchObject()`

### Requirement: Field values are templated from the item (REQ-OWN-006)

The node's `fields` configuration SHALL map target property names to values.
A string value SHALL support `{{dotted.path}}` placeholders resolved against
the current item's `json`, using the same placeholder syntax already used by
contributed nodes in this fleet.

Resolution rules:

- A path that resolves to a scalar SHALL substitute that scalar.
- A whole-value template (a value that is exactly one placeholder) SHALL
  preserve the resolved value's type — an array stays an array, a number stays
  a number, null stays null — rather than being stringified.
- A path that does not resolve SHALL be reported per REQ-OWN-011, not silently
  replaced with an empty string.
- A non-string value SHALL be passed through unchanged, so literals and nested
  structures can be authored directly.

The same templating SHALL apply to the values in a match pair (REQ-OWN-014),
so a match key can be built from the item exactly as a field can.

#### Scenario: A dotted path is substituted

- **GIVEN** an item whose `json` is `{"contact": {"name": "Alpha"}}`
- **AND** a mapping `{"title": "{{contact.name}}"}`
- **WHEN** the step executes
- **THEN** the payload's `title` is `"Alpha"`

#### Scenario: A whole-value template keeps its type

- **GIVEN** an item whose `json` is `{"tags": ["a", "b"]}`
- **AND** a mapping `{"tags": "{{tags}}"}`
- **WHEN** the step executes
- **THEN** the payload's `tags` is the array `["a", "b"]`, not its string form

#### Scenario: A literal value is passed through

- **GIVEN** a mapping `{"source": "hydra-console"}`
- **WHEN** the step executes
- **THEN** the payload's `source` is `"hydra-console"`

### Requirement: The saved object is merged back into the output item (REQ-OWN-007)

For each input item the node SHALL emit exactly one output item. The output
item's `json` SHALL carry the saved object's data together with its identifiers
(at minimum its `uuid`, plus the resolved register and schema), so a downstream
step can act on what was just written without re-fetching it.

For `operation: delete` the output item's `json` SHALL carry the identifiers of
the object that was removed and a `deleted: true` marker, so a downstream step
can log or notify on what went. For a `delete` that matched nothing under
`onNoMatch: skip` (REQ-OWN-012) it SHALL carry `deleted: false` and the input
item's `json` unchanged, so the skip is visible rather than indistinguishable
from a successful removal.

The output item SHALL preserve `pairedItem` provenance pointing at the input
item that caused it, and SHALL carry the input item's `binary` payload through
unchanged.

#### Scenario: The output item carries the saved object

- **GIVEN** a step that creates an object
- **WHEN** the step executes
- **THEN** the output item's `json` contains the saved object's `uuid` and its
  saved data

#### Scenario: A delete reports what it removed

- **GIVEN** a step with `operation: delete` that removes one object
- **WHEN** the step executes
- **THEN** the output item's `json` carries that object's `uuid` and
  `deleted: true`

#### Scenario: A skipped delete is distinguishable from a performed one

- **GIVEN** a step with `operation: delete` and `onNoMatch: skip` whose match
  resolves to nothing
- **WHEN** the step executes
- **THEN** the output item's `json` carries `deleted: false`

#### Scenario: Provenance is preserved

- **GIVEN** a step receiving three items
- **WHEN** the step executes
- **THEN** each output item's `pairedItem` names the index of the input item it
  came from

### Requirement: A failed write is an explicit error, never a silent empty item (REQ-OWN-008)

The node SHALL throw whenever a write fails — permission denied, schema
validation rejected the payload, an `update` or `delete` matched nothing or
matched ambiguously, the write cap was exceeded, the store errored — so that
the engine reads the step's `onError` policy (`stop`, `continue`,
`dead_letter`) and the run log records the failure with its cause.

The node SHALL NOT catch a write failure and return an empty item list, an item
with an empty `json`, or the unchanged input item. Doing so produces a run that
reports success while having written nothing, which is the most expensive
failure mode available: the flow looks green and the data is not there.

The anti-pattern is concrete and already in the fleet —
`hermiq/lib/Flow/HermiqAgentNode.php` catches `Throwable` and continues with an
empty answer string. This node SHALL NOT copy it.

#### Scenario: A rejected write surfaces as a step failure

- **GIVEN** a step whose payload fails schema validation
- **WHEN** the step executes with `onError: stop`
- **THEN** the run status is not `completed`
- **AND** the run log records the step as failed with the validation error

#### Scenario: The onError policy is honoured

- **GIVEN** the same failing step configured with `onError: continue`
- **WHEN** the run executes
- **THEN** the run continues past the step
- **AND** the failure is still recorded in the run log

#### Scenario: Failure never yields a hollow success

- **GIVEN** a step whose write is refused
- **WHEN** the step executes
- **THEN** it does not return an item list at all
- **AND** the run does not report the step as succeeded

### Requirement: An unusable configuration is refused when the flow is saved (REQ-OWN-009)

`validateConfig()` SHALL throw `UnexpectedValueException` when the
configuration cannot have been meant:

- `register` missing or empty
- `schema` missing or empty
- `operation` missing, or not one of `create`, `update`, `upsert`, `delete`
- `operation` is `update`, `upsert` or `delete` and no `match` block is
  declared, or `match` is declared but contains no property/value pair
- a `match` pair missing either its property name or its value
- `fields` missing or empty for `create`, `update` or `upsert`
- `fields` or `replace` present for `delete`, where neither has meaning
- `operation` is `delete` and `confirmDelete` is absent or not exactly `true`
  (REQ-OWN-012)
- `maxWrites` present and not a positive integer (REQ-OWN-015)
- `onMissing` present and not one of `omit`, `fail`
- `onNoMatch` present and not one of `error`, `skip`

Validation happens when the flow is saved, not when it runs, so a broken step
is caught in the editor rather than at 3am in a scheduled run. The error
message SHALL name which key is at fault.

#### Scenario: A step with no schema is refused

- **GIVEN** a configuration with a register and an operation but no schema
- **WHEN** the flow is saved
- **THEN** saving is refused with an error naming the missing schema

#### Scenario: An upsert with no match key is refused

- **GIVEN** a configuration with `operation: upsert` and no match block
- **WHEN** the flow is saved
- **THEN** saving is refused with an error naming the missing match

#### Scenario: An unknown operation is refused

- **GIVEN** a configuration with `operation: purge`
- **WHEN** the flow is saved
- **THEN** saving is refused with an error naming the unknown operation

### Requirement: An unresolvable register or schema at run time is an error (REQ-OWN-010)

`register` and `schema` SHALL each accept a slug or a uuid. At execution time
the node SHALL resolve both. If either does not resolve — renamed, deleted, or
belonging to an organisation the run owner cannot see — the node SHALL throw.

It SHALL NOT skip the item, SHALL NOT pass the unresolved identifier through to
the store, and SHALL NOT fall back to a default register or schema. A flow
pointed at a register that no longer exists is broken and must say so.

The resolved register and schema SHALL be passed to every `ObjectService` call
the node makes, including `deleteObject()`, whose scoped signature confines the
lookup to exactly one magic table. An unscoped delete could reach a UUID that
lives in a different `(register, schema)` pair, which is the defect class
ConductionNL/openregister#1638 already cost this codebase once.

#### Scenario: A deleted register fails the step

- **GIVEN** a step configured with a register slug that no longer exists
- **WHEN** the step executes
- **THEN** the step fails with an error naming the unresolvable register
- **AND** no item is silently dropped

#### Scenario: A schema outside the owner's organisation fails the step

- **GIVEN** a step whose schema belongs to another organisation
- **WHEN** the step executes as the run owner
- **THEN** the step fails rather than writing into that organisation

#### Scenario: A delete is scoped to the configured register and schema

- **GIVEN** a step with `operation: delete` whose match value is a uuid that
  exists only in a different register/schema pair
- **WHEN** the step executes
- **THEN** the match does not resolve within the configured scope
- **AND** the object in the other scope is untouched

### Requirement: Written payloads follow the normal schema validation rules (REQ-OWN-011)

The node SHALL NOT relax, pre-sanitise or work around `ObjectService`'s schema
validation. A payload that the API would reject SHALL be rejected here, with
the validator's own message surfaced into the run log so the author can act on
it.

Two consequences SHALL be documented for authors and honoured by the node:

- An object property nested inside an array item rejects both `{}` and `null`.
  The node SHALL therefore omit a mapped key whose templated value resolves to
  nothing, rather than sending an empty object or an explicit null in its
  place — omission is the shape the validator accepts.
- An unresolved `{{path}}` SHALL be surfaced. `onMissing` governs the
  behaviour and SHALL default to `omit` (drop the key); the alternative is
  `fail` (fail the item). It SHALL NOT silently become an empty string,
  because a mandatory field quietly set to `""` passes validation and corrupts
  the record.

An unresolved placeholder inside a `match` value SHALL always fail the item
regardless of `onMissing`, because omitting a match key would silently widen
the match rather than narrow it (REQ-OWN-014).

#### Scenario: An empty nested object is omitted rather than sent

- **GIVEN** a mapping for a property nested in an array item whose templated
  value resolves to nothing
- **WHEN** the payload is built
- **THEN** the key is absent from the payload
- **AND** neither `{}` nor `null` is sent for it

#### Scenario: A validation error reaches the run log

- **GIVEN** a payload missing a required property
- **WHEN** the step executes
- **THEN** the run log records the validator's message for that property

#### Scenario: An unresolved placeholder does not become an empty string

- **GIVEN** a mapping `{"title": "{{missing.path}}"}` and default `onMissing`
- **WHEN** the payload is built
- **THEN** `title` is absent from the payload
- **AND** `title` is not set to `""`

#### Scenario: An unresolved placeholder in a match value fails the item

- **GIVEN** a match pair whose value is `{{missing.path}}` and `onMissing` is
  the default `omit`
- **WHEN** the step executes
- **THEN** the item fails rather than matching on the remaining pairs

### Requirement: Deletion is offered and guarded (REQ-OWN-012)

The node SHALL offer `delete` as a fourth `operation`, and every delete SHALL
pass four independent guards before any object is removed.

**Guard 1 — an explicit match is mandatory.** `delete` SHALL require a `match`
block containing at least one property/value pair (REQ-OWN-014). There SHALL be
no way to express "delete everything this step can see": no wildcard, no empty
match, no filter-less form. A `match` block that is absent or empty is refused
at save time (REQ-OWN-009), not at run time, so the flow cannot be persisted in
a shape that could template-to-all.

**Guard 2 — exactly one object.** The resolved match SHALL identify exactly one
object. More than one is ambiguous and SHALL fail the item with an error naming
the count, never "pick the first". Zero matches SHALL be governed by
`onNoMatch`: `error` (the default) fails the item, `skip` emits the item with
`deleted: false` (REQ-OWN-007) and removes nothing. `skip` is opt-in because
"the object I meant to delete is not there" is far more often a broken flow
than an idempotent re-run.

**Guard 3 — an explicit acknowledgement.** The configuration SHALL carry
`confirmDelete: true` exactly. Absent, `false`, or any other value — including
the string `"true"` — SHALL be refused by `validateConfig()`. This exists so
that no author reaches deletion by mistyping `update`: changing one enum value
is not enough, a second deliberate key is required, and the flow will not save
without it.

**Guard 4 — the ordinary delete path.** The removal SHALL go through
`ObjectService::deleteObject()` with the resolved register and schema and the
run owner as the explicit acting user (REQ-OWN-003, REQ-OWN-013). Every
semantic of that path SHALL apply unchanged: the RBAC `delete` permission check
against the run owner, the audit trail entry, soft-delete rather than physical
removal, the append-only schema rejection (`AppendOnlyException`), the
archival-immutability rejection (`ArchivalImmutableException`), and the
transferred-object rejection. The node SHALL NOT pass `_retentionSweep: true`
and SHALL NOT expose a hard-delete option; purging a soft-deleted object stays
an administrative action outside the flow engine.

Deletes SHALL count against the per-step write cap (REQ-OWN-015) exactly as
saves do.

#### Scenario: Delete removes exactly the matched object

- **GIVEN** a step with `operation: delete`, `confirmDelete: true` and a match
  resolving to exactly one object
- **WHEN** the step executes
- **THEN** that object is soft-deleted
- **AND** no other object in the register is affected

#### Scenario: A delete with no match block cannot be saved

- **GIVEN** a configuration with `operation: delete` and no `match` block
- **WHEN** the flow is saved
- **THEN** saving is refused with an error naming the missing match
- **AND** there is no configuration shape that deletes without a match

#### Scenario: An ambiguous delete match fails rather than choosing

- **GIVEN** a step with `operation: delete` whose match resolves to three
  objects
- **WHEN** the step executes
- **THEN** the item fails with an error naming the match count
- **AND** none of the three objects is deleted

#### Scenario: A delete matching nothing errors by default

- **GIVEN** a step with `operation: delete` and no `onNoMatch` key whose match
  resolves to no object
- **WHEN** the step executes
- **THEN** the item fails with an error naming the unmatched delete

#### Scenario: A delete matching nothing can be configured as a no-op

- **GIVEN** the same step with `onNoMatch: skip`
- **WHEN** the step executes
- **THEN** the step succeeds, removes nothing, and emits `deleted: false`

#### Scenario: Delete without the acknowledgement is refused at save time

- **GIVEN** a configuration with `operation: delete`, a valid match, and no
  `confirmDelete` key
- **WHEN** the flow is saved
- **THEN** saving is refused with an error naming `confirmDelete`

#### Scenario: A non-boolean acknowledgement does not count

- **GIVEN** a configuration with `operation: delete` and `confirmDelete: "true"`
- **WHEN** the flow is saved
- **THEN** saving is refused, because the acknowledgement must be boolean
  `true`

#### Scenario: Delete honours RBAC against the run owner

- **GIVEN** a run whose owner has no `delete` permission on the target schema
- **AND** a fully guarded delete step matching one object
- **WHEN** the step executes
- **THEN** the step fails with the same permission error the API would return
- **AND** the object still exists

#### Scenario: Delete is soft-delete and is audited

- **GIVEN** a fully guarded delete step that succeeds
- **WHEN** the object is inspected after the run
- **THEN** it is soft-deleted rather than physically removed
- **AND** an audit trail entry records the run owner as the actor

#### Scenario: An append-only schema refuses the delete

- **GIVEN** a fully guarded delete step targeting an append-only schema
- **WHEN** the step executes
- **THEN** the step fails with the append-only rejection
- **AND** the object is unchanged

#### Scenario: No hard-delete or retention-sweep key is reachable

- **GIVEN** a configuration containing `hardDelete: true` and
  `_retentionSweep: true`
- **WHEN** the step executes
- **THEN** neither key has any effect and the delete remains a soft-delete

### Requirement: ObjectService gains a real PATCH-semantic write method (REQ-OWN-013)

`ObjectService::patchObject()` SHALL become the fleet's supported
PATCH-semantic write path: a partial payload merged onto the stored object,
attributable to an explicit user, and scoped to a register and schema.

The method name is not new — `patchObject()` already exists on `ObjectService`
— but its current body is a thin facade that cannot serve this purpose. It
SHALL be completed to meet the following contract, and its `@spec exclude`
annotation SHALL be replaced with a reference to this capability.

**Signature.** It SHALL accept the target object identifier, the partial data,
an optional `Register` scope, an optional `Schema` scope, `?IUser $currentUser`
for explicit attribution, and the `_rbac` / `_multitenancy` flags — mirroring
`saveObject()`'s parameter vocabulary so a caller moving between the two does
not have to relearn it.

**Identifier resolution.** It SHALL resolve the identifier as a uuid, a slug or
a numeric id, using the scoped mapper lookup when register and schema are
supplied. It SHALL NOT cast the identifier to `int`; the current implementation
does, so a uuid silently degrades to a leading-digit integer and either misses
or, worse, resolves to an unrelated row.

**Merge semantics.** For every key in the partial payload:

- A key present with a non-null value SHALL overwrite the stored value.
- A key absent from the payload SHALL leave the stored value untouched.
- A key present with an explicit `null` SHALL clear the stored value, so
  "unset this property" is expressible and distinguishable from "do not
  mention it".
- A key whose stored and provided values are both objects SHALL merge
  recursively on the same three rules. Arrays SHALL be replaced wholesale, not
  element-merged, because there is no stable identity to merge array elements
  on and a positional merge would corrupt reordered lists.

**Attribution and enforcement.** It SHALL forward `currentUser`, `_rbac` and
`_multitenancy` to the underlying save. The current implementation accepts
`_rbac` and `_multitenancy` and forwards neither, so a caller's intent is
silently discarded — a parameter that is read by nobody is worse than no
parameter, because the call site reads as if it did something.

**Validation and side effects.** The merged result SHALL go through the same
schema validation, audit trail and event dispatch as `saveObject()`. A patch is
a save of a different shape, not a privileged shortcut around one.

Widening a core service is a deliberate scope increase for this change. The
motivating defect is fleet-wide, not node-local: `saveObject()` is PUT-semantic
and partial payloads have silently nulled live data more than once. Keeping the
merge inside the node would have fixed one caller and left the trap armed for
every other. Existing `patchObject()` callers SHALL keep working — the contract
above is a superset of today's behaviour for the numeric-id, session-attributed
case that they use.

#### Scenario: Omitted keys are preserved

- **GIVEN** a stored object with `title: "Alpha"` and `status: "open"`
- **WHEN** `patchObject()` is called with `{"status": "closed"}`
- **THEN** the stored object has `status: "closed"` and `title: "Alpha"`

#### Scenario: An explicit null clears a property

- **GIVEN** the same stored object
- **WHEN** `patchObject()` is called with `{"title": null}`
- **THEN** the stored object's `title` is cleared
- **AND** `status` is untouched

#### Scenario: Nested objects merge rather than replace

- **GIVEN** a stored object with `contact: {"name": "Alpha", "email": "a@example.org"}`
- **WHEN** `patchObject()` is called with `{"contact": {"email": "b@example.org"}}`
- **THEN** the stored `contact.name` is still `"Alpha"`
- **AND** `contact.email` is `"b@example.org"`

#### Scenario: Arrays are replaced wholesale

- **GIVEN** a stored object with `tags: ["a", "b", "c"]`
- **WHEN** `patchObject()` is called with `{"tags": ["x"]}`
- **THEN** the stored `tags` is exactly `["x"]`

#### Scenario: A uuid identifier resolves correctly

- **GIVEN** a stored object addressed by its uuid
- **WHEN** `patchObject()` is called with that uuid and a register/schema scope
- **THEN** that object is patched
- **AND** no other object is read or written

#### Scenario: The explicit acting user is enforced

- **GIVEN** a call with `currentUser` set to a user without update permission
  on the schema
- **WHEN** `patchObject()` is called with `_rbac` left at its default
- **THEN** the call fails with a permission error
- **AND** the audit trail records no successful write

#### Scenario: A patch is validated like a save

- **GIVEN** a partial payload that makes the merged object violate its schema
- **WHEN** `patchObject()` is called
- **THEN** the call fails with the validator's message
- **AND** the stored object is unchanged

### Requirement: A match may combine several properties (REQ-OWN-014)

`match` SHALL accept one or more property/value pairs, and the node SHALL
resolve a match by requiring every pair to hold — the pairs are ANDed, never
ORed.

Each pair names a target property and a value; the value supports the same
`{{dotted.path}}` templating as `fields` (REQ-OWN-006), so a composite key can
be assembled from the item. A single-pair match remains the common case and
SHALL stay expressible without ceremony.

Resolution rules, identical for `update`, the update half of `upsert`, and
`delete`:

- Exactly one object satisfying all pairs SHALL be the match.
- More than one SHALL fail the item as ambiguous, with an error naming the
  count. The node SHALL NOT pick one — picking would be non-deterministic
  across instances, which is the defect class that only ever appears on someone
  else's system.
- Zero matches SHALL mean insert for `upsert`, an error for `update`
  (REQ-OWN-002), and `onNoMatch` for `delete` (REQ-OWN-012).
- Matching SHALL run through the ordinary read path with the run owner's RBAC
  and multitenancy applied, so an object the owner cannot see is not a match
  (REQ-OWN-003).

The node SHALL NOT accept a match expressed as a raw store query, a filter
string, or anything else that could reach beyond equality on named properties.
Composite equality is the whole widening; an expression language here would
make the delete guard of REQ-OWN-012 unauditable.

#### Scenario: Two pairs narrow to one object

- **GIVEN** two objects sharing `sourceId: "s1"` but differing on `tenant`
- **AND** a match of `sourceId: "s1"` and `tenant: "{{tenant}}"`
- **WHEN** the step executes for an item naming one tenant
- **THEN** exactly that tenant's object is matched

#### Scenario: A single-pair match still works

- **GIVEN** a match with one pair on `sourceId`
- **WHEN** the step executes
- **THEN** the match resolves on that property alone

#### Scenario: Pairs are ANDed, not ORed

- **GIVEN** an object matching the first pair but not the second
- **WHEN** the step executes
- **THEN** that object is not a match

#### Scenario: An ambiguous composite match fails

- **GIVEN** a composite match satisfied by two objects
- **WHEN** the step executes with `operation: update`
- **THEN** the item fails with an error naming the match count
- **AND** neither object is written

#### Scenario: An unmatchable object outside the owner's visibility is not matched

- **GIVEN** an object satisfying every pair but owned by another organisation
- **WHEN** the step executes as the run owner
- **THEN** it is not treated as a match

### Requirement: A step execution may not exceed its write cap (REQ-OWN-015)

The node SHALL count every object write it performs in one step execution —
creates, updates, upserts and deletes alike — and SHALL fail rather than exceed
a configured maximum.

The cap SHALL be configurable per step through `maxWrites`. When the step does
not declare one, an instance-level default SHALL apply, read from app
configuration so an administrator can raise or lower it without editing flows.
The shipped default SHALL be **1000 writes per step execution**, chosen to sit
at the same order of magnitude as `FlowEngine::MAX_TRANSITIONS` so the two
ceilings are legible together, and to be far above any hand-authored fan-out
while still bounding a runaway.

Behaviour on exceed:

- The step SHALL raise an error naming the cap and the count that hit it, so
  the author can either raise the cap deliberately or fix the fan-out.
- The error SHALL be an ordinary step failure, so the step's `onError` policy
  (`stop`, `continue`, `dead_letter`) decides what happens to the run
  (REQ-OWN-008).
- The node SHALL NOT silently truncate. Writing the first N items and
  discarding the rest while reporting success is the exact green-but-dead
  outcome REQ-OWN-008 exists to prevent — worse here, because the register
  would end up holding a partial, plausible-looking dataset.
- Writes already performed before the cap was hit SHALL NOT be rolled back, and
  the error message SHALL say how many were performed, so an operator knows the
  register is in a partial state rather than guessing.

This cap is load-bearing, not decorative. The sibling change
`openconnector-flow-nodes` settled its own open question in favour of
`openregister.synchronization-run` emitting **one item per synchronised
object** rather than a single summary item. A synchronisation over a few
thousand records therefore hands its whole result set to the next step as flow
items — and if the next step is an object-write, one trigger becomes one write
per synchronised record. Without a cap, a mis-wired pair of nodes is a
write amplifier bounded only by the size of someone else's API.

#### Scenario: A step under the cap writes normally

- **GIVEN** a step receiving fewer items than its cap
- **WHEN** the step executes
- **THEN** every item is written and the step succeeds

#### Scenario: Exceeding the cap fails the step

- **GIVEN** a step with `maxWrites: 10` receiving fifty items
- **WHEN** the step executes
- **THEN** the step fails with an error naming the cap
- **AND** the error names how many writes were performed before it stopped

#### Scenario: The cap is never silently truncated

- **GIVEN** the same step
- **WHEN** the step executes
- **THEN** it does not return a successful item list covering only the first
  ten items

#### Scenario: Exceeding the cap honours onError

- **GIVEN** the same step configured with `onError: dead_letter`
- **WHEN** the step executes
- **THEN** the run is dead-lettered rather than reported successful

#### Scenario: Deletes count against the cap

- **GIVEN** a step with `operation: delete` and `maxWrites: 5` receiving twenty
  matching items
- **WHEN** the step executes
- **THEN** the step fails once the sixth delete is reached

#### Scenario: The default applies when no cap is configured

- **GIVEN** a step with no `maxWrites` key
- **WHEN** the step executes
- **THEN** the instance-level default cap applies

### Requirement: Bypass flags are never reachable from a flow document (REQ-OWN-016)

The node SHALL NOT expose any configuration key that maps to `saveObject()`'s
`_rbac`, `_multitenancy` or `silent` parameters, to `patchObject()`'s `_rbac`
or `_multitenancy` parameters, or to `deleteObject()`'s `_rbac`,
`_multitenancy` or `_retentionSweep` parameters. Any such key appearing in a
configuration SHALL be ignored, and SHALL NOT be forwarded to any service call.

Bypass flags are excluded permanently, not deferred. Their existence in the
service signatures is for internal callers that have already made the
authorisation decision — repair steps, retention sweeps, import pipelines. A
flow has not made that decision; a flow is authored data, and authored data
must not be able to name its own exemption from the permission model.

The same reasoning excludes `ObjectService::runAsSystem()`: the node SHALL NOT
wrap any part of its work in it (REQ-OWN-004).

#### Scenario: A bypass key in the configuration has no effect

- **GIVEN** a configuration containing `_rbac: false`
- **WHEN** the step executes
- **THEN** RBAC is still enforced against the run owner

#### Scenario: A silent key does not suppress the audit trail

- **GIVEN** a configuration containing `silent: true`
- **WHEN** the step executes
- **THEN** the audit trail entry is still written
