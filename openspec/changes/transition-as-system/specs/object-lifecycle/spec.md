# object-lifecycle

## ADDED Requirements

### Requirement: An app can run a transition as the system after approving the caller itself (REQ-TAS-001)

OpenRegister SHALL offer a server-side entry point, `TransitionEngine::transitionAsSystem()`,
through which app code that has already checked the caller runs a named
transition on an object the caller holds no right on. The entry point SHALL
require the id of the app taking that decision and SHALL refuse an empty
one. On that path OpenRegister SHALL skip its own read check on the
subject, its `update` check on the subject, and RBAC and the organisation
filter on the lifecycle save, and nothing else.

#### Scenario: the normal path still refuses a caller without rights

- **GIVEN** a draft object the session user may not read or update
- **WHEN** the user's request runs the transition through `transition()`
- **THEN** the transition is refused and nothing is written
- @e2e exclude {engine-level contract, covered by TransitionEngineAsSystemTest}

#### Scenario: the same caller succeeds through the system entry point

- **GIVEN** the same draft and the same session user, and an app that has checked the user itself
- **WHEN** the app calls `transitionAsSystem()` naming itself
- **THEN** the object moves to the transition's target state
- **AND** OpenRegister's `update` check is not consulted
- @e2e exclude {server-side entry point with no HTTP route, covered by TransitionEngineAsSystemTest}

#### Scenario: an app must name itself

- **WHEN** `transitionAsSystem()` is called with an empty app id
- **THEN** it is refused before the object is read
- @e2e exclude {argument check, covered by TransitionEngineAsSystemTest}

### Requirement: What a transition declares still runs on the system path (REQ-TAS-002)

On the system path the lifecycle save SHALL still dispatch the update
event, so the transition's declared `authorization`, `condition` and
`requires` guard run and see the session user, the real caller, and a
refusal from any of them SHALL refuse the transition. The declared
`actions[]` and the transitioned event SHALL run with the real caller as
their user.

#### Scenario: the guard runs with the real caller

- **GIVEN** a transition that `requires` an app guard
- **WHEN** an app runs it through `transitionAsSystem()`
- **THEN** the guard is asked once, with the session user's id
- @e2e exclude {listener contract, covered by TransitionEngineAsSystemTest with the real LifecycleValidationListener}

#### Scenario: a guard refusal still refuses

- **GIVEN** a guard that denies the move
- **WHEN** an app runs the transition through `transitionAsSystem()`
- **THEN** the transition is refused with `lifecycle-guard-denied`
- @e2e exclude {listener contract, covered by TransitionEngineAsSystemTest with the real LifecycleValidationListener}

### Requirement: A system transition is recorded with the real caller and the app (REQ-TAS-003)

The audit row of a system transition SHALL name the real caller as its
user and SHALL carry `transitionAsSystem: {"app": "<app id>"}` in its change
set. The mark SHALL apply only while the transition's write runs and SHALL
be released when the write is refused. An ordinary write SHALL carry no
such mark. Each system transition SHALL also be logged at info level with
the app, the caller, the object and the action.

#### Scenario: the audit row names both

- **GIVEN** an app runs a transition through `transitionAsSystem()` for user `learner-1`
- **WHEN** the audit row of that write is built
- **THEN** its user is `learner-1` and its change set carries `transitionAsSystem` with the app id
- @e2e exclude {audit row builder, covered by TransitionEngineAsSystemTest}

#### Scenario: the mark does not outlive the write

- **GIVEN** a system transition that finished, or was refused
- **WHEN** the same object is saved again in the same request
- **THEN** that save carries no system mark
- @e2e exclude {request-scoped state, covered by TransitionEngineAsSystemTest}

### Requirement: The HTTP API cannot reach the system path (REQ-TAS-004)

No controller SHALL call `transitionAsSystem()`, and nothing in the
transition payload SHALL select the system path.

#### Scenario: the payload cannot ask for it

- **GIVEN** a caller without rights on the object
- **WHEN** the transition endpoint is called with `_rbac`, `asSystem` or `app` in the payload
- **THEN** the transition is refused as for any caller without rights
- @e2e exclude {engine-level contract, covered by TransitionEngineAsSystemTest}

#### Scenario: no controller calls it

- **WHEN** the source under `lib/` is searched for calls to `transitionAsSystem()`
- **THEN** none is found under `lib/Controller`
- @e2e exclude {structural assertion, covered by TransitionEngineAsSystemTest}
