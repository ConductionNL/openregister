# mdm-merge Specification

## Purpose
Merging duplicate records into one, for any schema that declares it: a preview that offers the choice per property, an atomic merge that can be reversed within a window, an audit record of every merge operation, an event downstream apps act on, and a REST surface scoped by RBAC.

## Requirements

### Requirement: Declarative merge annotation and vocabulary

OpenRegister SHALL support a schema annotation `x-openregister-merge` that declares how
duplicate objects of that schema are merged. The annotation key SHALL be registered in
`Schema::ANNOTATION_VOCABULARY` (so it is retained on the schema configuration and not
dropped as an unknown `x-openregister-*` key) and SHALL be shape-validated at schema
import by a `MergeAnnotationValidator`. The annotation SHALL declare at least:
`sourceLinkField` (the field holding the losing object's linked source records to relink,
conceptually the same field the survivorship config names), `entityType` (passed to the
survivorship recompute; defaults to the schema slug), `reversalWindowDays` (default `30`),
`statusField` (default `status`), `survivorStatus` (default `active`), and `mergedStatus`
(default `merged-into-other`). A malformed `x-openregister-merge` annotation MUST degrade
to a non-fatal warning: the schema still stores objects and merges simply fall back to the
documented defaults; it MUST NOT abort schema import — mirroring the survivorship
annotation's advisory, non-fatal handling.

#### Scenario: Annotation is registered and recognised
- **WHEN** a schema declares an `x-openregister-merge` block on import
- **THEN** the annotation MUST be retained on the schema's configuration (not dropped as an unknown `x-openregister-*` key)

#### Scenario: Malformed annotation is non-fatal
- **WHEN** a schema is imported with an `x-openregister-merge` annotation that is not an object, or whose `reversalWindowDays` is not a positive integer
- **THEN** the schema import MUST still succeed
- **AND** the invalid annotation MUST be ignored with a logged warning, and merges MUST fall back to the documented defaults

### Requirement: OR-owned mergeOperation audit-log register

OpenRegister SHALL own a generic `mergeOperation` register/schema, shipped as
`lib/Settings/merge_operation_register.json` in the same shape as
`trust_configuration_register.json`, that records one row per executed merge. Each row
SHALL capture: `mergedIntoUuid` (the survivor), `mergedFromUuids[]` (the losing objects),
`reason`, `preMergeSnapshot` (both objects' golden-record, provenance, status and linked
source records, sufficient to restore them), `reversible` (boolean), `mergedAt`,
`reversedAt`, and `reversedBy`. The register SHALL declare NO seed objects — it is a
runtime audit log, populated only by `executeMerge`. Rows SHALL be written through
`ObjectService` so they are RBAC-scoped, tenant-scoped, and captured by the OR audit trail.

#### Scenario: Register ships with no seed rows
- **WHEN** the `mergeOperation` register is imported
- **THEN** the register MUST contain the `mergeOperation` schema and MUST declare no `x-openregister-seed` objects

#### Scenario: A merge appends one operation row
- **WHEN** `executeMerge` completes for a from/into pair
- **THEN** exactly one `mergeOperation` row MUST be persisted, carrying `mergedIntoUuid`, `mergedFromUuids`, `reason`, a non-empty `preMergeSnapshot`, `reversible: true`, and `mergedAt`

### Requirement: Entity-type-agnostic merge preview

OpenRegister SHALL provide a `MergeService::previewMerge(from, into)` that computes the
post-merge outcome with NO side effects: it MUST NOT write any object, mergeOperation, or
event. The preview SHALL return the projected survivor golden record and provenance
(computed by reusing `SurvivorshipResolver` over the union of both objects' linked source
records) plus the reversal deadline derived from `reversalWindowDays`. The service MUST be
entity-type-agnostic — it takes the schema's `x-openregister-merge` / survivorship config
and never hardcodes attribute or entity-type names — and MUST reject a preview whose two
uuids are equal or whose objects are not both readable under the caller's RBAC scope.

#### Scenario: Preview has no side effects
- **WHEN** `previewMerge(from, into)` is called for two readable objects
- **THEN** the projected survivor golden record and reversal deadline MUST be returned
- **AND** no object, mergeOperation row, or `ObjectsMergedEvent` MUST be written or dispatched

#### Scenario: Preview rejects a self-merge
- **WHEN** `previewMerge` is called with identical from and into uuids
- **THEN** the service MUST reject the request without computing a preview

### Requirement: Atomic reversible merge execution

OpenRegister SHALL provide `MergeService::executeMerge(from, into, reason, mergedBy)` that
performs, as one server-authoritative unit of work: (1) build a `preMergeSnapshot` of both
objects (golden record, provenance, status, and the losing object's linked source-record
links); (2) relink the losing object's linked source records onto the survivor via the
`sourceLinkField`; (3) recompute the survivor's golden record by reusing
`SurvivorshipResolver` over the combined source set; (4) mark the losing object with
`mergedStatus` and the survivor with `survivorStatus`; (5) persist a `mergeOperation` row
with `reversible: true`; and (6) dispatch an `ObjectsMergedEvent`. The service MUST reject
a self-merge, a losing object already in `mergedStatus`, or a survivor not in
`survivorStatus`. Every object write MUST go through `ObjectService` (RBAC + tenant scoped,
audited).

#### Scenario: Source records relinked onto the survivor
- **WHEN** `executeMerge` runs for a losing object with linked source records
- **THEN** each of the losing object's source records MUST reference the survivor via `sourceLinkField` after the merge
- **AND** the survivor's golden record MUST be recomputed over the combined source set

#### Scenario: Already-merged source is rejected
- **WHEN** `executeMerge` is called with a losing object whose status already equals `mergedStatus`
- **THEN** the merge MUST be rejected and no new `mergeOperation` row written

#### Scenario: Downstream event dispatched, not queued
- **WHEN** `executeMerge` completes successfully
- **THEN** an `ObjectsMergedEvent` MUST be dispatched via the OR event dispatcher
- **AND** OpenRegister MUST NOT enqueue work onto any app-specific sync queue

### Requirement: Reversal within the reversal window

OpenRegister SHALL provide `MergeService::reverseMerge(mergeOperationId, reversedBy)` that
restores both objects from the `preMergeSnapshot` — golden record, provenance, status, and
the losing object's source-record links — when the operation is still reversible, then sets
`reversedAt`, `reversedBy`, and `reversible: false` on the operation. `isReversible` SHALL
return false once `reversible` is false, once `reversedAt` is set, or once the elapsed time
since `mergedAt` exceeds `reversalWindowDays`; `reversalDeadline` SHALL compute `mergedAt +
reversalWindowDays` as a date. A reversal attempt outside the window MUST be rejected and
MUST NOT mutate any object. A successful reversal SHALL dispatch an `ObjectsMergedEvent`
marked as a reversal so downstream consumers can undo their propagation.

#### Scenario: Reversal inside the window restores the snapshot
- **WHEN** `reverseMerge` is called for a reversible operation whose `mergedAt` is within `reversalWindowDays`
- **THEN** both objects MUST be restored to their `preMergeSnapshot` state (golden record, provenance, status, source links)
- **AND** the operation MUST be marked `reversible: false` with `reversedAt` and `reversedBy` set

#### Scenario: Reversal outside the window is rejected
- **WHEN** `reverseMerge` is called for an operation whose `mergedAt` is older than `reversalWindowDays`, or that is already `reversible: false`
- **THEN** the reversal MUST be rejected and no object MUST be mutated

### Requirement: ObjectsMergedEvent for downstream propagation

OpenRegister SHALL define `OCA\OpenRegister\Event\ObjectsMergedEvent` extending the
Nextcloud `Event`, carrying the survivor uuid, the merged-from uuids, the `mergeOperation`
id, and a flag distinguishing a merge from a reversal. `MergeService` SHALL dispatch it via
`IEventDispatcher` on both `executeMerge` and `reverseMerge`. Leaf apps SHALL be able to
subscribe to react (e.g. downstream sync), reusing OR's existing event/webhook
infrastructure. OpenRegister MUST NOT reimplement an app-specific sync queue for
propagation.

#### Scenario: Event exposes the merge participants
- **WHEN** a subscriber handles `ObjectsMergedEvent` after a merge
- **THEN** it MUST be able to read the survivor uuid, the merged-from uuids, and the `mergeOperation` id from the event
- **AND** distinguish a merge from a reversal via the event's reversal flag

### Requirement: RBAC-scoped merge REST surface

OpenRegister SHALL expose a `MergeController` registered in `appinfo/routes.php` with three
routes — preview, execute, and reverse — delegating entirely to `MergeService`. Every
method MUST declare `#[NoAdminRequired]` and MUST rely on `ObjectService` for RBAC and
tenant scoping (the same posture as `DuplicateController` / `QualityController`): a caller
who cannot read/write the objects under RBAC MUST receive a forbidden/not-found response
rather than a successful merge. Every route target method MUST exist on the controller and
every controller method returning a Response MUST be routed (ADR-029 reachability).

#### Scenario: Preview is reachable and read-only
- **WHEN** a client calls the merge preview route for two readable objects
- **THEN** the controller MUST return the preview payload from `MergeService::previewMerge` with no writes

#### Scenario: Execute enforces RBAC
- **WHEN** a client without write access to the target objects calls the execute route
- **THEN** the controller MUST return a forbidden/not-found response and MUST NOT perform the merge

#### Scenario: Reverse route reverses a merge
- **WHEN** a client calls the reverse route with a reversible `mergeOperation` id
- **THEN** the controller MUST return the updated operation from `MergeService::reverseMerge`

### Requirement: A merge preview offers the choice per property, and execution applies the choice (REQ-DMD-001)

`previewMerge(from, into)` SHALL return, for every property the two objects
declare, the value held by each and the value `SurvivorshipResolver`
proposes. `executeMerge()` SHALL accept a decision map naming, per property,
which of the two values survives, and SHALL apply exactly that map. A
decision map that names a property absent from the preview, or that omits a
property the preview offered, SHALL be refused with HTTP 422 naming the
property, and nothing SHALL be written. When no decision map is supplied the
resolver's proposal SHALL apply, so the existing behaviour is unchanged.

#### Scenario: the reviewer keeps the older address and the newer phone number

- **GIVEN** two party records, one with the correct address and the other with the correct phone number
- **WHEN** the merge is previewed and executed with a decision map naming the first for `address` and the second for `phone`
- **THEN** the survivor holds that address and that phone number
- @e2e exclude {service-level merge, covered by MergeService unit tests}

#### Scenario: a decision map that does not match the preview is refused

- **GIVEN** a preview offering the properties `address`, `phone` and `email`
- **WHEN** execution is requested with a map naming only `address`
- **THEN** the merge is refused with HTTP 422 naming the omitted properties
- **AND** neither object is changed

#### Scenario: no map behaves exactly as today

- **GIVEN** two readable objects and a merge requested with no decision map
- **WHEN** it executes
- **THEN** the survivor is the one `SurvivorshipResolver` computes, unchanged from before this change

### Requirement: A merge is refused when the merger cannot read everything being merged (REQ-DMD-002)

A merge SHALL be refused when either object carries a property the caller
may not read under field-level security. The refusal SHALL name the
properties, SHALL NOT disclose their values, and SHALL be recorded on the
audit trail of both objects as an attempted merge.

#### Scenario: a handler without the medical domain cannot merge two clients

- **GIVEN** a party record carrying a property restricted to a group the caller is not in
- **WHEN** the caller requests a merge of that record with another
- **THEN** the merge is refused naming the property and not its value
- **AND** both objects carry an audit entry for the attempt

### Requirement: Parties merge through the existing merge primitive (REQ-PRM-005)

Two parties that turn out to be one person SHALL merge through the
entity-type-agnostic merge, with a declared party vocabulary naming which
properties survive, how addresses combine and how roles on objects are
carried over. The merge SHALL keep every role both parties held, on every
object, and the reversal window SHALL restore both parties and their roles.

#### Scenario: two party records become one, keeping both case histories

- **GIVEN** party A holding a role on two objects and party B holding a role on one
- **WHEN** B is merged into A
- **THEN** A holds all three roles and the merge is on the merge audit register

#### Scenario: a merged party is restored within the window

- **GIVEN** the merge above, inside the reversal window
- **WHEN** the merge is reversed
- **THEN** both parties exist again and each holds the roles it held before
- @e2e exclude {reversal path, covered by the merge unit tests}
