## ADDED Requirements

### Requirement: Manifest schedule can name the flow-run action

A manifest `schedules[]` entry SHALL be able to declare `action: "openregister:flow-run"`, and the AppHost schedule action allow-list SHALL resolve that action type to a single server-owned OpenRegister action class. The allow-list SHALL remain a CLOSED, server-owned map: a manifest-supplied fully-qualified class name SHALL NEVER be used as the executed `jobClass`, and an action type that is not in the map SHALL be skipped and logged rather than executed. Adding the flow-run action SHALL NOT change the `schedules[]` declaration shape — the flow is named in `arguments`, not in the `action` string.

#### Scenario: Flow-run action is allow-listed

- **WHEN** the allow-list is asked to resolve the action type `openregister:flow-run`
- **THEN** it returns the server-owned OpenRegister flow-run action class, and reports the action type as allowed

#### Scenario: Existing synchronization action is unaffected

- **WHEN** the allow-list is asked to resolve `openconnector:synchronization`
- **THEN** it still returns the OpenConnector synchronization action class, unchanged

#### Scenario: Manifest-supplied class name is never executed

- **WHEN** a manifest declares `action: "OCA\\Evil\\Payload"` (or any other value not present in the allow-list)
- **THEN** the allow-list resolves it to nothing, no job is reconciled for that schedule, and the rejection is logged with the application id, schedule id and action type

#### Scenario: Virtual app schedules a flow on a cron cadence

- **WHEN** a virtual app declares `schedules: [{ id: "nightly-intake", cron: "0 2 * * *", action: "openregister:flow-run", arguments: { flowId: "00000000-0000-0000-0000-000000000000" }, enabled: true }]`
- **THEN** the reconciler upserts one job for that schedule whose `jobClass` is the server-owned flow-run action class and whose arguments carry the declared `flowId`

### Requirement: Flow-run action queues exactly one run and never executes inline

The flow-run action SHALL, when executed, queue exactly one flow run through the flow run service with trigger `schedule`, and SHALL NOT walk the flow graph itself. Execution of the queued run SHALL be left to the existing flow run worker, so a scheduled run receives the same logging, retry, suspension and retention behaviour as every other run. The action SHALL return a result describing what it did, so that the outcome is recorded in the executing job's log.

#### Scenario: Due schedule queues one run

- **WHEN** the flow-run action executes for a schedule naming a resolvable flow, with a resolvable owner
- **THEN** exactly one flow run is created with status `queued`, trigger `schedule` and the declared flow's id, and the flow graph is not walked during the action

#### Scenario: Queued run is executed by the existing worker

- **WHEN** the flow run worker next ticks after a scheduled run has been queued
- **THEN** the worker advances that run exactly as it advances any other queued run, with no scheduling-specific execution path

#### Scenario: Two fires queue two independent runs

- **WHEN** the same schedule fires twice
- **THEN** two separate runs exist, each with its own identity and log, and neither replaces or resumes the other

### Requirement: Attribution is mandatory and fail-closed

Every run queued by the flow-run action SHALL carry a non-null acting user, resolved from the schedule's owning application rather than from any session or from manifest data. The action SHALL verify that the acting user resolves to a live user account, and when it does not, the action SHALL queue no run at all and SHALL return an error result. The action SHALL NEVER queue a run with a null acting user, and SHALL ignore any author-supplied `runAs` or `owner` value in the schedule's arguments.

#### Scenario: Run carries the application owner as its acting user

- **WHEN** the flow-run action executes for a schedule owned by a live user
- **THEN** the queued run's `triggeredBy` is that user, and when the run is executed the flow node context carries the same user as `triggeredBy`

#### Scenario: No resolvable owner queues nothing

- **WHEN** the flow-run action executes with no acting user available, or with an acting user that does not resolve to a live account
- **THEN** no run is queued, the action returns an error result naming missing attribution as the reason, and nothing is written

#### Scenario: Author-supplied identity is ignored

- **WHEN** a schedule declares `arguments: { flowId: "…", runAs: "admin" }`
- **THEN** the queued run is attributed to the application owner resolved server-side, and the declared `runAs` value has no effect on the run's acting user

#### Scenario: Attributed run may write objects

- **WHEN** a scheduled run whose flow contains an object-write step is executed
- **THEN** the write is performed as the run's acting user rather than refused for missing attribution

### Requirement: Flow resolution refuses rather than queues a doomed run

The flow-run action SHALL resolve the flow named by its `flowId` argument through the flow resolver registry, accepting either a uuid or a slug, and SHALL confirm that the resolved document is flow-shaped. When `flowId` is missing or blank, or when no resolver owns it, or when the resolved object is not a flow, the action SHALL queue no run and SHALL return an error result naming the unresolvable flow. Resolution SHALL NOT rely on register/schema arguments to scope the lookup, because those arguments do not scope object lookup by id.

#### Scenario: Flow resolves by uuid

- **WHEN** the action executes with `arguments: { flowId: "00000000-0000-0000-0000-000000000000" }` naming an existing flow
- **THEN** the flow is resolved and a run is queued for it

#### Scenario: Missing flow id queues nothing

- **WHEN** the action executes with no `flowId` argument, or with a blank one
- **THEN** no run is queued and the action returns an error result

#### Scenario: Unknown flow id queues nothing

- **WHEN** the action executes with a `flowId` no resolver owns
- **THEN** no run is queued and the action returns an error result naming the flow id

#### Scenario: Object that is not a flow is refused

- **WHEN** `flowId` resolves to an object that carries neither nodes nor edges — for example a slug that matches an object in a different register
- **THEN** the action treats the flow as unresolvable, queues no run, and returns an error result

### Requirement: Existing schedule contract is consumed unchanged

The flow-run action SHALL be scheduled entirely through the existing AppHost scheduling engine, and this capability SHALL NOT introduce a second scheduler, a second cadence source or a new manifest key. Interval and cron cadences, the `enabled` flag, per-application scoping, the deterministic application-plus-schedule reference key, idempotent upsert and garbage-collection of removed schedules SHALL apply to a flow-run schedule exactly as they apply to any other allow-listed action.

#### Scenario: Disabled schedule does not fire

- **WHEN** a flow-run schedule declares `enabled: false`
- **THEN** the reconciled job is disabled and no flow run is queued for it

#### Scenario: Removed schedule is garbage-collected

- **WHEN** a flow-run schedule is removed from its application's manifest
- **THEN** the reconciler disables the corresponding job on its next sweep, preserving its run history, and no further flow runs are queued

#### Scenario: Unchanged schedule is a no-op

- **WHEN** the reconciler sweeps and a flow-run schedule's declaration is unchanged
- **THEN** no write is performed for that schedule

#### Scenario: Schedules of two applications stay separate

- **WHEN** two applications each declare a flow-run schedule with the same schedule id
- **THEN** each is reconciled into its own job under its own application-scoped reference, and each run is attributed to its own application's owner

### Requirement: Natively scheduled flows are attributed the same way

A flow whose own trigger is `schedule` SHALL be queued with a non-null acting user resolved from the flow object's owner, verified against a live user account. When that owner does not resolve, the flow SHALL be skipped for that tick with a logged warning and its last-fire marker SHALL NOT be advanced, so a flow blocked on attribution is never recorded as having run.

#### Scenario: Natively scheduled flow carries its owner

- **WHEN** a flow with trigger `schedule` becomes due and its owner is a live user
- **THEN** the queued run's `triggeredBy` is that owner, and an object-write step inside that run is performed rather than refused

#### Scenario: Ownerless scheduled flow is skipped, not fired

- **WHEN** a due flow with trigger `schedule` has no owner that resolves to a live account
- **THEN** no run is queued, a warning is logged naming the flow, and the flow's last-fire marker is left unchanged so it is re-evaluated on the next tick
