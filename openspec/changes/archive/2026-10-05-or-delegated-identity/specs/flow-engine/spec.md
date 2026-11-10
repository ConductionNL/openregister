## ADDED Requirements

### Requirement: A run MUST carry the identity it executes as, distinct from what caused it

A run records two identity values. `triggeredBy` names what caused the run and is
provenance: it is immutable and MUST NOT be consulted to decide access. `runAs`
names the user whose rights the run's steps execute with, and is the only value
an access decision reads.

They are allowed to differ, and for a scheduled run they always do: the cause is
a schedule, the acting identity is a person.

A run MUST NOT be queued with an absent `runAs`. A dispatch path that cannot
resolve one MUST refuse to queue rather than record a run that every
attribution-requiring node will later reject one step at a time.

#### Scenario: A run records both values
- **GIVEN** any dispatch path — manual, object event, schedule, sub-flow, MCP or
  workflow engine
- **WHEN** a run is queued
- **THEN** the run carries a non-empty `runAs`
- **AND** it carries a `triggeredBy` describing what caused it

#### Scenario: Access decisions read runAs, not triggeredBy
- **GIVEN** a run whose `runAs` and `triggeredBy` differ
- **WHEN** a node performs a read or a write
- **THEN** the access decision answers for `runAs`
- **AND** changing `triggeredBy` does not change what the node may read or write

#### Scenario: An unattributable dispatch is refused at the queue, not at the node
- **GIVEN** a dispatch path that cannot resolve an acting identity
- **WHEN** it attempts to queue a run
- **THEN** the queue is refused with a reason naming the missing identity
- **AND** no run row is created

### Requirement: A run's acting identity comes from its trigger, never from the flow

A flow MUST NOT carry an acting identity. `owner` and `organisation` on a flow
express ownership of the DEFINITION — who may edit it and which tenant it belongs
to — and MUST continue to be required and enforced on write. Neither MUST be read
as a mandate to execute as that user.

Because a flow may carry several trigger nodes, and each is an independent entry
point, a single flow can start under several different identities. The identity
therefore resolves per trigger:

| trigger | acting identity |
|---|---|
| manual | the acting session user |
| object event | the user whose action raised the event |
| schedule | the `runAs` declared on that trigger node |
| sub-flow | the calling run's `runAs` |

Where a caller is present, the caller wins: a manual run is the acting user's
act, and attributing it to the flow's author would be a worse answer than none.
Where no caller is present, the trigger's declared identity answers, and its
absence MUST fail closed.

Tenancy resolves independently of identity and keeps its own fallback: a run
whose caller has no resolvable organisation is still scoped to the flow's
organisation. The mixed case — a resolvable caller with an unresolvable tenant —
MUST yield a run scoped to the flow's tenant rather than to none.

#### Scenario: A manual run acts as the caller, not the flow's author
- **GIVEN** a flow owned by user A
- **WHEN** user B runs it manually
- **THEN** the run's `runAs` is B

#### Scenario: A scheduled run acts as its trigger's declared identity
- **GIVEN** a flow owned by user A whose schedule trigger declares `runAs` C
- **WHEN** the schedule fires
- **THEN** the run's `runAs` is C
- **AND** it is not A

#### Scenario: Identity and tenancy resolve independently
- **GIVEN** a resolvable acting identity whose active organisation cannot be
  resolved
- **WHEN** a run is queued
- **THEN** the run's acting identity is the caller
- **AND** its organisation is the flow's organisation

### Requirement: A schedule trigger MUST declare a resolvable acting identity or fail to save

A node of type `openregister.trigger-schedule` MUST declare a `runAs` naming a
user that resolves. Validation MUST refuse the save when it is missing, empty, or
names a user that does not exist — alongside the cron-expression check the node
already performs.

The registration that makes the schedule fire and the identity it fires under
MUST derive from the same node, so that the two cannot disagree.

This closes a defect class in which a scheduled run was queued with no identity
and then refused one node at a time, reported by `ObjectWriteNode` as "this flow
run has no owner" — leaving a natively scheduled flow silently incapable of
writing anything.

#### Scenario: A schedule trigger without runAs is refused at save
- **GIVEN** a node of type `openregister.trigger-schedule` with a valid cron
  expression and no `runAs`
- **WHEN** its config is validated
- **THEN** the save MUST be refused, naming the missing `runAs`
- **AND** the flow MUST NOT be stored

#### Scenario: A schedule trigger naming a non-existent user is refused at save
- **GIVEN** a schedule trigger whose `runAs` names a user that does not resolve
- **WHEN** its config is validated
- **THEN** the save MUST be refused, naming the unresolvable user

#### Scenario: Save-time validation is not the only control
- **GIVEN** a stored schedule trigger whose `runAs` resolved when it was saved
- **WHEN** the schedule fires after that user has been disabled or removed
- **THEN** the firing MUST be refused
- **AND** passing validation at save time MUST NOT be treated as standing
  authorization

### Requirement: A resumed run MUST re-resolve its acting identity before it continues

A run that suspended — on a wait, on an external signal, or in a queue — MUST
resolve its acting identity again when it resumes, and apply the rights that
identity holds at resumption. It MUST NOT apply rights captured when the run was
queued or suspended.

A run whose recorded identity no longer resolves to an enabled user MUST fail
with a reason naming it, rather than continuing under any fallback.

#### Scenario: Rights revoked during a suspension take effect on resume
- **GIVEN** a run suspended while acting as user C, who could write to a schema
- **AND** C's permission on that schema is removed while the run is suspended
- **WHEN** the run resumes and reaches a write to that schema
- **THEN** the write is refused

#### Scenario: A run whose identity has gone fails closed on resume
- **GIVEN** a suspended run whose `runAs` names a user since removed
- **WHEN** the run resumes
- **THEN** the run fails with a reason naming the unresolvable identity
- **AND** no further step executes

#### Scenario: A schedule whose identity has gone is disabled and surfaced
- **GIVEN** a registered schedule whose declared `runAs` no longer resolves to an
  enabled user
- **WHEN** the schedule would next fire
- **THEN** the schedule is disabled
- **AND** the owner of the flow definition is notified
- **AND** the schedule does not remain enabled while silently firing nothing
