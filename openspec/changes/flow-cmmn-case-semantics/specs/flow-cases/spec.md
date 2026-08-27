## Purpose

A case layer over the flow engine: a plan of stages, human tasks and
milestones anchored to an OpenRegister object, whose entry and exit are
governed by sentries and to which a caseworker may attach work at runtime
that no author drew. It adopts the CMMN 1.1 concepts and none of its
notation; the Petri net remains the execution substrate.

## ADDED Requirements

### Requirement: The case is the OpenRegister object

A case plan SHALL be anchored to exactly one OpenRegister object,
identified by the triple object uuid + register + schema. The system SHALL
NOT introduce a separate case record, a case identifier, or a case
lifecycle distinct from the anchoring object's own.

Every plan item in a case plan SHALL carry that same anchor triple, so that
"what is running on this object?" is answerable by one indexed lookup and
without joining through a flow run. A case plan SHALL be resolvable for an
object that has never had a flow run.

The anchor SHALL be the same shape a flow run already uses to name its
subject, so a plan item, a task and a run about the same object agree on
what they are about without translation.

#### Scenario: A case plan is found by its object

- **GIVEN** an object with a case plan containing a stage, two human items
  and a milestone
- **WHEN** the case plan is requested by the object's uuid
- **THEN** the response MUST contain every plan item with its current state,
  its type and its parent
- **AND** the response MUST NOT require a flow run uuid to be supplied
- @e2e covered by the case-plan route scenario in the case-plan Playwright
  spec

#### Scenario: A case plan exists without any flow run

- **GIVEN** an object with plan items created directly, none of which
  carries a run reference
- **WHEN** the case plan is read and an item is transitioned
- **THEN** both MUST succeed
- **AND** no code path MUST treat the absence of a run as an error or as a
  degraded case
- @e2e exclude covered by CasePlanService unit tests over run-less plans

### Requirement: Plan-item state is stored as rows, never as an encoded blob

Each plan-item instance SHALL be a persisted record with its own identity,
its own state column and its own audit trail. The system SHALL NOT store a
case's runtime plan state as an encoded string, an encoded document, or any
single field holding the state of more than one plan item.

Two plan items in the same case SHALL be transitionable concurrently
without either transition reading, rewriting or overwriting the other's
state.

Plan-item state SHALL be queryable by state, by type, by parent and by
anchor object without decoding a stored document. Answering "which cases
have an active item of type X?" SHALL be an indexed query.

Every plan-item state change SHALL append an audit entry naming the item,
the from-state, the to-state, the acting identity, the cause (a sentry id,
a user action, a realisation outcome, or a cascade from a parent) and the
timestamp. Audit entries SHALL NOT be updatable or deletable through any
API.

#### Scenario: Concurrent transitions on one case do not clobber each other

- **GIVEN** a case plan with two active human items
- **WHEN** both are completed in overlapping requests
- **THEN** both MUST be recorded as completed
- **AND** each MUST have its own audit entry
- @e2e exclude covered by a concurrency unit test over two overlapping
  transitions

#### Scenario: Stuck cases are found by query

- **GIVEN** a population of cases of which some have an item of a given type
  in state `active`
- **WHEN** those cases are listed by item type and state
- **THEN** the query MUST be answered from the datastore, with filtering,
  sorting, pagination and the total all computed there
- **AND** no stored document MUST be decoded to evaluate the filter
- @e2e exclude covered by CaseItemMapper query tests

### Requirement: One lifecycle table governs every plan item

A plan item's state SHALL be one of `available`, `enabled`, `active`,
`completed`, `terminated`, `disabled` — the same six states a task carries.
Every plan item SHALL start in `available`.

Legal transitions SHALL be defined by an exhaustive table keyed by plan-item
type, and presence in that table SHALL be the only definition of legality. A
transition absent from the table — including a transition from a state to
itself — SHALL be REFUSED with an error naming the item, its type, the
from-state and the requested to-state. It SHALL NOT be silently ignored and
SHALL NOT be coerced to a legal neighbour.

`completed`, `terminated` and `disabled` SHALL be terminal: no transition
out of them SHALL exist for any plan-item type.

A `milestone` SHALL have exactly two legal transitions, `available →
completed` and `available → terminated`. A milestone SHALL NOT be
enableable, activatable or disableable, because a milestone performs no
work and therefore has nothing to be active during.

#### Scenario: An illegal transition names all four facts

- **GIVEN** a milestone in state `available`
- **WHEN** it is transitioned to `active`
- **THEN** the transition MUST be refused with an error naming the item id,
  the type `milestone`, the from-state and the to-state
- **AND** the item's state MUST be unchanged
- @e2e exclude covered by the transition-table unit test matrix

#### Scenario: A terminal item accepts nothing further

- **GIVEN** a human plan item in state `completed`
- **WHEN** any transition is requested on it
- **THEN** it MUST be refused naming the current state
- @e2e exclude covered by the transition-table unit test matrix

### Requirement: Sentries are entry and exit criteria over existing engine primitives

A plan item SHALL carry an ordered set of entry criteria and an ordered set
of exit criteria. Each criterion (a sentry) SHALL consist of an optional
on-part and an optional if-part.

A sentry SHALL fire when its on-part has occurred AND its if-part evaluates
true — conjunction WITHIN one sentry. An item's entry or exit SHALL be
satisfied when ANY of its sentries fires — disjunction ACROSS the set. An
empty criteria set SHALL mean "satisfied as soon as the parent is active"
for entry, and "never satisfied" for exit; these two defaults SHALL be
stated in the stored definition rather than inferred by each caller.

The **if-part** SHALL be expressed in the flow engine's existing expression
language, evaluated against a document containing the anchoring object's
current state and the case plan's current item states. The system SHALL NOT
introduce a second condition language, a second operator vocabulary or a
second expression evaluator for sentries. An if-part that cannot be
evaluated SHALL be treated as FALSE, never as vacuously true.

The **on-part** SHALL be an event drawn from the flow engine's existing
event catalog. The catalog SHALL be extended with plan-item lifecycle
events for `completed`, `terminated` and `disabled`, so that one plan item's
outcome can satisfy another item's sentry. A sentry naming an event that is
not in the catalog SHALL be REFUSED at save time, not at run time.

A malformed sentry — one with neither an on-part nor an if-part, or with an
if-part naming no field — SHALL never fire.

#### Scenario: A milestone satisfies another item's entry

- **GIVEN** a milestone `advice-received` and a human item whose only entry
  sentry has an on-part on that milestone completing
- **WHEN** the milestone is completed
- **THEN** the human item MUST become actionable without any further call
- **AND** its audit entry MUST name the sentry that admitted it
- @e2e covered by the sentry-cascade scenario in the case-plan Playwright
  spec

#### Scenario: An unevaluable condition blocks rather than admits

- **GIVEN** an entry sentry whose if-part references a field the anchoring
  object does not have
- **WHEN** the case plan is evaluated
- **THEN** the sentry MUST NOT fire
- **AND** the item MUST remain unentered
- @e2e exclude covered by sentry-evaluation unit tests

#### Scenario: An unknown event is refused at save time

- **GIVEN** a case-plan definition with a sentry naming an event that is not
  in the event catalog
- **WHEN** the definition is saved
- **THEN** the save MUST be refused with an error naming the unknown event
- **AND** no plan item MUST be created
- @e2e exclude covered by case-plan definition validation unit tests

### Requirement: Stages nest, and complete by a written rule

A `stage` SHALL contain other plan items, SHALL itself be a plan item with
its own entry and exit criteria, and SHALL be nestable to arbitrary depth.
A child SHALL NOT become actionable while its parent stage is not `active`.

A stage SHALL complete automatically when every REQUIRED child is terminal
and no child is `active`. A stage whose children are ALL optional SHALL NOT
auto-complete on activation: absence of required children SHALL be treated
as "not complete", never as "trivially complete".

Exiting a stage — by its own exit criteria, by termination, or by its parent
terminating — SHALL cascade: every non-terminal child SHALL be terminated,
and every child not yet entered SHALL be disabled. Each cascaded transition
SHALL be individually audited with the parent exit as its cause.

Cascading evaluation SHALL be bounded. A definition whose sentries produce
an unbounded cascade SHALL fail with an error naming the bound, and SHALL
NOT loop.

A plan item SHALL declare whether it is required. Required-ness SHALL govern
only the parent's completion rule and SHALL NOT make an item auto-start.

A plan item MAY declare a repetition rule. A repeating item SHALL produce a
new realisation per repetition while remaining ONE plan item, and each
realisation SHALL be individually addressable.

#### Scenario: A stage with only optional children stays open

- **GIVEN** a stage that becomes `active` and contains only discretionary
  children, none of which has been enabled
- **WHEN** the case plan is evaluated
- **THEN** the stage MUST remain `active`
- @e2e exclude covered by stage-completion unit tests

#### Scenario: Terminating a stage terminates what is under it

- **GIVEN** an active stage with one active human item and one unentered
  milestone
- **WHEN** the stage is terminated
- **THEN** the human item MUST be `terminated` and the milestone MUST be
  `disabled`
- **AND** each MUST carry an audit entry naming the stage exit as the cause
- @e2e covered by the stage-termination scenario in the case-plan Playwright
  spec

#### Scenario: An unbounded cascade fails loudly

- **GIVEN** a definition whose sentries admit each other in a cycle
- **WHEN** the case plan is evaluated
- **THEN** evaluation MUST stop at the declared bound and report an error
  naming it
- **AND** the case plan MUST NOT be left partially transitioned
- @e2e exclude covered by cascade-bound unit tests

### Requirement: A human plan item is realised by a task, and a stage may be realised by a flow run

The case layer SHALL NOT execute work. When a plan item becomes `active` it
SHALL do exactly one of:

- **humanTask** — create a task through the task capability, carrying the
  case anchor, the item's candidate performers, and the item's deadline
  values;
- **stage bound to a flow** — queue a flow run against the flow's pinned
  published version;
- **milestone** — complete immediately, performing no work.

The case layer SHALL NOT advance a marking, queue a transition, alter a
run's status, or participate in the engine's scheduling in any other way.

The coupling SHALL be one-directional: the realisation's terminal outcome
SHALL drive the plan item's terminal state, and the plan item SHALL write to
its realisation only to TERMINATE it when the item is exited or cascaded.
The plan item SHALL NOT otherwise set a task's state, and a task SHALL NOT
set a plan item's non-terminal state.

A plan item's realisation SHALL be recorded on the plan item, so that a task
and the plan item that produced it are mutually resolvable.

#### Scenario: Completing the task completes the item

- **GIVEN** an active human plan item whose task has been created
- **WHEN** the task is completed by its performer
- **THEN** the plan item MUST become `completed`
- **AND** the plan item's audit entry MUST name the task completion as the
  cause
- @e2e covered by the task-realisation scenario in the case-plan Playwright
  spec

#### Scenario: Exiting an item terminates its open task

- **GIVEN** an active human plan item with an open task in someone's inbox
- **WHEN** the item's exit criteria fire
- **THEN** the task MUST be terminated with a reason
- **AND** it MUST no longer appear in that person's inbox
- @e2e exclude covered by realisation-termination unit tests

#### Scenario: The case layer touches no marking

- **GIVEN** a case plan being evaluated over a run that is suspended
- **WHEN** every plan item that can transition has transitioned
- **THEN** the run's marking, status and log MUST be byte-identical to what
  they were before
- @e2e exclude covered by a unit test asserting run immutability across
  case-plan evaluation

### Requirement: A caseworker may attach work no author drew

A plan item MAY be declared discretionary: present in the definition but not
entered automatically, requiring an explicit act to enable it. The system
SHALL be able to list, for a given case, exactly which discretionary items
are currently enableable — those whose parent is `active` and whose entry
criteria are satisfied.

The system SHALL additionally allow an AD-HOC item: a plan item attached to
a live case that appears in no definition at all. An ad-hoc item SHALL be a
first-class plan item, subject to the same lifecycle table, the same audit
and the same completion rules as a defined one, and SHALL be distinguishable
from a defined item in the record and in the API.

Adding an ad-hoc item SHALL NOT modify any flow definition, SHALL NOT create
a new definition version, and SHALL NOT alter the graph any in-flight run is
pinned to.

Enabling a discretionary item and adding an ad-hoc item SHALL each be
authorized fail-closed against the item's declared authorization before
anything is written. An authorization answer that cannot be determined — an
unresolvable role, an unavailable group backend — SHALL DENY. The
authorization decision SHALL be made by the system, not returned to a caller
to interpret.

An ad-hoc item's authorization SHALL derive from its parent stage, or from
the case plan root when it has no parent; an ad-hoc item SHALL NOT be able
to declare itself unguarded.

#### Scenario: A caseworker adds an unplanned advice request

- **GIVEN** a live case whose definition contains no external-advice item
- **WHEN** an authorized caseworker attaches an ad-hoc human item to the
  active stage
- **THEN** the item MUST be created, entered and realised as a task
- **AND** no flow definition and no definition version MUST change
- @e2e covered by the ad-hoc-item scenario in the case-plan Playwright spec

#### Scenario: An unauthorized attach is refused before anything is written

- **GIVEN** a user who does not hold the parent stage's authorization
- **WHEN** they attempt to enable a discretionary item or attach an ad-hoc
  one
- **THEN** the attempt MUST be refused
- **AND** no plan item, task or audit-visible state change MUST exist
  afterwards other than the recorded denial
- @e2e exclude covered by CasePlanAuthorizationService unit tests

#### Scenario: An indeterminate authorization denies

- **GIVEN** a discretionary item guarded by a role that cannot be resolved
- **WHEN** enabling it is attempted
- **THEN** it MUST be denied
- @e2e exclude covered by CasePlanAuthorizationService unit tests

### Requirement: Business state is written through to the register, never owned by the engine

Runtime plan state SHALL live in the case layer's own records. Business
state that a citizen, a caseworker or an auditor relies on — the anchoring
object's status, its result, the moment each was reached — SHALL be mirrored
onto the register object through the ordinary object-write path.

The register object SHALL be the record of the business fact. The case layer
SHALL NOT be a system of record for anything a consumer of the register
needs, and no consumer SHALL be required to read plan-item rows to learn the
object's business status.

The mirror SHALL be one-directional. A write to the register object SHALL
NOT be interpreted as a plan-item transition; it MAY satisfy a sentry, which
is a different thing and goes through sentry evaluation like any other
condition.

Deleting or archiving a case's plan items SHALL NOT remove or alter the
business state already mirrored onto the object.

#### Scenario: The object carries the status without the plan

- **GIVEN** a case whose plan has advanced through two milestones
- **WHEN** the anchoring object is read through the ordinary object API by a
  consumer that knows nothing about plan items
- **THEN** the object MUST carry the current business status and the moment
  it was reached
- @e2e covered by the write-through scenario in the case-plan Playwright spec

#### Scenario: Removing the plan does not rewrite history

- **GIVEN** a case whose plan items are deleted
- **WHEN** the anchoring object is read
- **THEN** its mirrored business status MUST be unchanged
- @e2e exclude covered by write-through unit tests

### Requirement: A zaaktype maps to a case skeleton, and reports what it could not map

The system SHALL produce a case-plan skeleton from a VNG Catalogi
`zaaktype` document that is already present in a register. The mapping SHALL
be a pure transformation over a supplied document; the system SHALL NOT
fetch from a remote catalogue as part of this capability.

The mapping SHALL be:

- `statustypen`, in their declared order, become milestones in that order;
- `roltypen` become candidate roles on human plan items, preserving the
  generic role designation where one is present;
- `resultaattypen` become the constrained set of end states the case may
  finish in — a case SHALL NOT be completable with a result outside that
  set;
- `doorlooptijd` and `servicenorm` are CARRIED onto the produced items as
  deadline values. This capability SHALL store them and SHALL NOT compute,
  schedule, escalate or sweep on them.

The import SHALL produce a report naming every element it could not map,
every element it mapped approximately, and what the author should do about
each. A zaaktype element outside the mapping SHALL be reported, not silently
dropped and not guessed at.

The produced skeleton SHALL be a draft the author edits, never a definition
that becomes live by the act of importing.

#### Scenario: An ordered status list becomes ordered milestones

- **GIVEN** a zaaktype with four `statustypen` carrying sequence numbers out
  of document order
- **WHEN** the skeleton is produced
- **THEN** the skeleton MUST contain four milestones in sequence-number
  order
- @e2e exclude covered by the zaaktype-mapping unit test fixtures

#### Scenario: A result outside the zaaktype's set is refused

- **GIVEN** a case produced from a zaaktype declaring three
  `resultaattypen`
- **WHEN** the case is completed with a result that is not one of the three
- **THEN** the completion MUST be refused naming the allowed set
- @e2e exclude covered by end-state constraint unit tests

#### Scenario: Unmappable content is reported, never dropped silently

- **GIVEN** a zaaktype carrying elements the mapping does not cover
- **WHEN** the skeleton is produced
- **THEN** the report MUST name each such element and why it was not mapped
- **AND** the skeleton MUST be marked as a draft
- @e2e exclude covered by the zaaktype-mapping report unit tests

### Requirement: CMMN notation is not adopted, and BPMN remains a format

The system SHALL NOT parse CMMN XML, SHALL NOT serialise CMMN XML, and SHALL
NOT depend on any CMMN notation artifact. The case layer's definition format
SHALL be the system's own, and the CMMN standard SHALL be present as
vocabulary and semantics only.

BPMN SHALL remain an interchange FORMAT and SHALL NOT become an execution
semantic. No construct imported from BPMN SHALL execute differently from the
same construct authored natively, and nothing in the case layer SHALL be
reachable only via a BPMN document.

#### Scenario: No CMMN document is accepted or produced

- **GIVEN** a request to import or export a case plan as CMMN XML
- **WHEN** it is made against any endpoint in this capability
- **THEN** no such endpoint MUST exist
- @e2e exclude covered by the route inventory test asserting the absence of
  CMMN endpoints
