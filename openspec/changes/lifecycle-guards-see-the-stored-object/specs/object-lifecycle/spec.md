# object-lifecycle

## ADDED Requirements

### Requirement: A transition guard can judge the stored object

The system SHALL call a transition guard that implements
`LifecycleTransitionGuardInterface` with a `LifecycleGuardContext` holding the
incoming data, the entity being saved as it is stored, the transition action
and the caller's uid. The context's `uuid()` SHALL come from the stored
entity, never from the incoming data, so a guard never resolves the object
it judges from client data. A guard that implements only
`LifecycleGuardInterface` SHALL be called with `check()` and the incoming
data, as before.

#### Scenario: a guard reads the applicant from the stored object

- **GIVEN** a stored request filed by `alice`, and a guard that refuses when the stored applicant is the caller
- **WHEN** `alice` moves it to the next state with a payload that names `bob` as the applicant
- **THEN** the guard sees `alice` as the stored applicant and the transition is refused
- @e2e exclude {needs an app guard; covered by `LifecycleValidationListenerTest::testAStoredObjectGuardCannotBeFooledByThePayload`}

#### Scenario: a payload id that names another object does not move the guard

- **GIVEN** a stored request `real-request` filed by `alice`
- **WHEN** a write to it sends `id: someone-elses-request` in its payload
- **THEN** the guard's context names `real-request` and its stored data says `alice` filed it
- @e2e exclude {covered by `LifecycleValidationListenerTest::testAPayloadIdNamingAnotherObjectDoesNotMoveTheGuard`}

#### Scenario: a payload guard is unchanged

- **GIVEN** a guard that implements only `check()`
- **WHEN** the transition runs
- **THEN** `check()` receives the incoming data, the action and the caller
- @e2e exclude {covered by `LifecycleValidationListenerTest::testAPayloadGuardStillReceivesThePayload`}

### Requirement: A create starts in the declared initial state

When a schema declares `x-openregister-lifecycle.initial` and a create sets
the lifecycle field, the system SHALL refuse the create with
`lifecycle-invalid-initial` unless the value equals the resolved initial
state or is one of the states listed in `x-openregister-lifecycle.createStates`.
The comparison SHALL be strict. When a dynamic initial cannot be resolved,
only a `createStates` value SHALL be accepted. A `createStates` that is not a
list of strings SHALL allow nothing extra, and the annotation validator SHALL
report it, as it SHALL report a listed state outside the field's enum. A
create that leaves the field empty SHALL get the initial state. A write
inside `SystemOperationContext` SHALL NOT be refused by this check.
`createStates` SHALL survive a schema save, export and update.

#### Scenario: a client creates an object in a later state

- **GIVEN** a lifecycle with `initial: open`
- **WHEN** a client creates an object with `status: closed`
- **THEN** the create is refused with `lifecycle-invalid-initial` and nothing is stored
- @e2e exclude {covered by `LifecycleInitialStateListenerTest::testACreateInAnotherStateIsRefused`}

#### Scenario: a seed creates an object in a later state

- **WHEN** the same create runs inside `SystemOperationContext`
- **THEN** it is stored with `status: closed`
- @e2e exclude {covered by `LifecycleInitialStateListenerTest::testTheSystemMayCreateInAnyState`}

#### Scenario: a dynamic initial is compared

- **GIVEN** a lifecycle whose initial comes from the case type, which says `intake`
- **WHEN** a client creates a case with `status: closed`, and another with `status: intake`
- **THEN** the first is refused and the second is stored
- @e2e exclude {covered by `LifecycleInitialStateListenerTest::testADynamicInitialIsComparedWithTheCallersValue`}

#### Scenario: a schema lets a create start in a later state

- **GIVEN** a lifecycle with `initial: draft` and `createStates: [published]`
- **WHEN** an app creates a version with `status: published`, and another with `status: archived`
- **THEN** the first is stored and the second is refused, naming the allowed states
- @e2e exclude {covered by `LifecycleInitialStateListenerTest::testADeclaredCreateStateMayBeChosen` and `SchemaLifecycleCreateStatesRoundTripTest`}

### Requirement: An administrator can import historic data in its own states

The system SHALL accept a `historic` flag on the register import
(`POST /api/registers/{id}/import`). When it is set and the caller is an
administrator, each row SHALL keep its own lifecycle state: the import runs
inside `KeepLifecycleStatesScope`, which lifts only the initial-state check on
creates. The import SHALL NOT be elevated: it runs as the administrator, and
permissions, guards and validation still apply (ADR-099). When it is set and the caller is not an
administrator, the import SHALL be refused with 403 before any row is read.
Without the flag, every row SHALL be checked as an ordinary create.

#### Scenario: an administrator imports closed cases

- **GIVEN** an administrator and a CSV whose rows are in later states
- **WHEN** the administrator imports it with `historic=true`
- **THEN** the rows keep their states, and the import does not run inside `SystemOperationContext`
- @e2e exclude {covered by `RegistersControllerImportGoldenTest::testAnAdminsHistoricImportKeepsTheRowsStates` and `LifecycleInitialStateListenerTest::testAHistoricImportKeepsTheState`}

#### Scenario: a register manager asks for a historic import

- **GIVEN** a user who may manage the register but is not an administrator
- **WHEN** the user imports with `historic=true`
- **THEN** the answer is 403 and nothing is imported
- @e2e exclude {covered by `RegistersControllerImportGoldenTest::testAHistoricImportIsRefusedForSomebodyWhoIsNotAnAdmin`}

