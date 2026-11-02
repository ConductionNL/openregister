# object-lifecycle

## ADDED Requirements

### Requirement: A transition guard can judge the stored object

The system SHALL call a transition guard that implements
`LifecycleTransitionGuardInterface` with a `LifecycleGuardContext` holding the
incoming data, the stored object, the transition action and the caller's
uid. A guard that implements only `LifecycleGuardInterface` SHALL be called
with `check()` and the incoming data, as before.

#### Scenario: a guard reads the applicant from the stored object

- **GIVEN** a stored request filed by `alice`, and a guard that refuses when the stored applicant is the caller
- **WHEN** `alice` moves it to the next state with a payload that names `bob` as the applicant
- **THEN** the guard sees `alice` as the stored applicant and the transition is refused
- @e2e exclude {needs an app guard; covered by `LifecycleValidationListenerTest::testAStoredObjectGuardCannotBeFooledByThePayload`}

#### Scenario: a payload guard is unchanged

- **GIVEN** a guard that implements only `check()`
- **WHEN** the transition runs
- **THEN** `check()` receives the incoming data, the action and the caller
- @e2e exclude {covered by `LifecycleValidationListenerTest::testAPayloadGuardStillReceivesThePayload`}

### Requirement: A create starts in the declared initial state

When a schema declares `x-openregister-lifecycle.initial` and a create sets
the lifecycle field, the system SHALL refuse the create with
`lifecycle-invalid-initial` unless the value equals the resolved initial
state. When a dynamic initial cannot be resolved, a value the caller sets
SHALL be refused. A create that leaves the field empty SHALL get the initial
state. A write inside `SystemOperationContext` SHALL NOT be refused by this
check.

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
