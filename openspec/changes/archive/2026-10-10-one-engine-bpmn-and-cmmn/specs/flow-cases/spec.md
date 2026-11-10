# flow-cases (delta)

## ADDED Requirements

### Requirement: One engine carries both model types on a shared core

The system SHALL run BPMN processes and CMMN case plans as two model types of
one engine. Each model type SHALL own only the shape of its runtime state and
the algorithm that advances it. Human work, deadlines, conditions, events,
the subject anchor and authorization SHALL come from the shared core: the
task service, the timer service with its working calendar, the event catalog
with the flow expression evaluator, the anchor triple, and fail-closed
authorization. A plan item's lifecycle states SHALL be the task's lifecycle
states, declared once.

#### Scenario: Plan items and tasks share one list of states

- **GIVEN** the task lifecycle's states and terminal states
- **WHEN** the plan item's states and terminal states are read
- **THEN** they MUST be the same lists, by reference to one declaration
- @e2e exclude a declaration invariant; asserted by a unit test

### Requirement: A process task starts a flow

A plan item of type `processTask` SHALL be realised by a flow run on the
plan's subject when it becomes active, and the run's terminal state SHALL
drive the item exactly as it does for a stage realised by a run. A process
task SHALL name its flow in the plan settings; a definition with a process
task that names no flow SHALL be refused at create time. A process task SHALL
follow the work-item lifecycle and SHALL NOT have children.

#### Scenario: A process task queues its flow

- **GIVEN** a plan whose process task `advies` names flow `advice-flow`
- **WHEN** `advies` becomes active
- **THEN** a run of `advice-flow` MUST be queued on the plan's subject
- **AND** the item MUST record the run as its realisation
- @e2e exclude realisation is asserted by unit tests over the real realisation service

#### Scenario: A process task without a flow is refused

- **GIVEN** a definition with a process task whose key has no flow in the plan settings
- **WHEN** the plan is created
- **THEN** creation MUST be refused naming the item, and nothing MUST be written
- @e2e exclude save-time validation; asserted by unit tests

### Requirement: A flow step opens or advances a case

The flow engine SHALL offer a node that creates a case plan on the run's
subject from a definition, and a node that transitions a plan item of the
run's subject by its key. Both SHALL act as the run's attributed identity and
SHALL go through the same service verbs, validation and authorization as any
other caller. Opening a plan on an object that already has one SHALL be
reported on the node's output, not raised. A refused transition SHALL fail
the step with the engine's message.

#### Scenario: A flow reaches a milestone

- **GIVEN** a case plan on object O with milestone `besluit-genomen` in `available`
- **WHEN** a run on O executes `case-advance` with item `besluit-genomen` and `to: completed`
- **THEN** the milestone MUST be completed with the run's identity in the audit
- @e2e exclude node behaviour; asserted by unit tests over the real plan service

#### Scenario: A refused advance fails the step

- **GIVEN** a milestone already `completed`
- **WHEN** `case-advance` asks to complete it again
- **THEN** the step MUST fail with the message naming item, type, from-state and to-state
- @e2e exclude asserted by unit tests

### Requirement: Plan-item deadlines run on the shared clock

A plan item with a due date or an expiry SHALL arm a timer on the shared
timer service, subject type `case-item`, when it becomes active, and every
open timer of an item SHALL be cancelled when the item reaches a terminal
state. An enforcing expiry SHALL terminate the item. The case layer SHALL NOT
keep a clock of its own. A failure to arm SHALL be logged and SHALL NOT
block the transition.

#### Scenario: An active item with a due date gets a timer

- **GIVEN** a human item with `dueAt` set
- **WHEN** it becomes active
- **THEN** a `due` timer with subject type `case-item` and the item's uuid MUST be armed

#### Scenario: Completing an item cancels its timers

- **GIVEN** an active item with an armed timer
- **WHEN** the item is completed
- **THEN** its timer MUST be cancelled

- @e2e exclude timer wiring; asserted by unit tests over the real timer service

### Requirement: In-process callers act as a named app

The case layer SHALL offer in-process verbs to create a plan, read a plan and
ensure items as a named app, for code that runs without a user session
(listeners, commands, repair steps). The audit SHALL record the actor as
`system:<app>`. These verbs SHALL NOT be reachable over HTTP. The user verbs
SHALL keep refusing a missing identity.

#### Scenario: An app's listener creates a plan

- **GIVEN** an object without a plan
- **WHEN** app `dossiq` creates a plan for it in-process as the system
- **THEN** the plan MUST exist and its creation audit MUST name `system:dossiq`

#### Scenario: No route reaches a system verb

- **GIVEN** the controllers of the app
- **WHEN** they are searched for the system verbs
- **THEN** none MUST reference them

- @e2e exclude no HTTP surface by design; asserted by unit and structural tests

### Requirement: Plan items can be ensured convergently with their recorded states

The case layer SHALL offer an ensure verb that, keyed on the object and the
item key, creates only the items an object does not have yet, with the state
each carries, and leaves existing items untouched. It SHALL append audit
entries only for items it created in that call, and SHALL import supplied
history for those items as audit entries flagged as imported, with their
original moments. It SHALL validate every item and state before writing
anything, SHALL create no realisation for imported items, and SHALL run no
cascade. Running it twice with the same input SHALL change nothing the
second time.

#### Scenario: A half-written plan resumes without duplicates

- **GIVEN** an object holding items `intake` and `beoordeling` and an ensure call naming `intake`, `beoordeling` and `besluit`
- **WHEN** the call runs
- **THEN** only `besluit` MUST be created, with its recorded state
- **AND** `intake` and `beoordeling` MUST keep their states and gain no audit entry

#### Scenario: A second run appends nothing

- **GIVEN** an ensure call that has already run
- **WHEN** it runs again with the same items and history
- **THEN** no row and no audit entry MUST be added

#### Scenario: An illegal recorded state is refused before writing

- **GIVEN** an ensure call with a milestone carrying state `active`
- **WHEN** the call runs
- **THEN** it MUST be refused naming the item and state, and nothing MUST be written

- @e2e exclude occ-level behaviour with no browser surface; asserted by unit tests
