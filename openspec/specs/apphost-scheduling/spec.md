# apphost-scheduling Specification

## Purpose
Recurring work for manifest-driven apps that ship no PHP: the manifest declares schedules, and publishing the manifest makes each one a scheduled flow on the one flow engine. There is no reconciler: the `TimedJob` went with #3382 and the class it drove is deleted. A schedule's run acts as the user who published it, in the application's organisation.

## Requirements

### Requirement: Manifest declares scheduled tasks

An OpenBuild/manifest-driven app SHALL be able to declare recurring work in its
manifest via a top-level `schedules[]` array (manifest v2). Each entry SHALL
carry a stable `id`, exactly one of `interval` (positive integer seconds) or
`cron` (a cron expression), an allow-listed `action` type, an `arguments`
object, and an `enabled` boolean (default `true`). The schedule declaration
SHALL NOT carry a runtime execution identity: the executing user is the user who
published the production version, never supplied by the manifest author.

#### Scenario: Virtual app declares an interval schedule

- **WHEN** a virtual app whose manifest is stored as an OpenRegister
  `application` object in the `openbuild` register declares
  `schedules: [{ id: "nightly-sync", interval: 86400, action:
  "openconnector:synchronization", arguments: { synchronizationId:
  "00000000-0000-0000-0000-000000000000" }, enabled: true }]`
- **THEN** the manifest validates against the manifest v2 schema and
  `nightly-sync` parses as a schedule declaration for that application

#### Scenario: Virtual app declares a cron schedule

- **WHEN** a virtual app declares `schedules: [{ id: "weekday-report", cron:
  "0 6 * * 1-5", action: "openconnector:synchronization", arguments: {
  synchronizationId: "00000000-0000-0000-0000-000000000000" }, enabled: true }]`
- **THEN** the manifest validates and the schedule parses as a declaration for
  that application

#### Scenario: On-disk manifest app declares a schedule

- **WHEN** an on-disk AppHost manifest app (manifest served by OpenRegister
  directly) declares the same `schedules[]` entry
- **THEN** the declaration parses identically to a virtual app's: the manifest
  source does not change it

#### Scenario: Schedule missing both interval and cron is rejected

- **WHEN** a schedule entry declares neither `interval` nor `cron` (or declares
  both)
- **THEN** the entry SHALL be refused as a schedule declaration, naming its `id`

#### Scenario: A manifest cannot name the identity

- **WHEN** a schedule entry carries a `runAs` or `owner` key naming a user
- **THEN** the key is ignored and the run acts as the publisher

### Requirement: Publishing a manifest applies its schedules as scheduled flows

When an application's production version is published, or the schedules of its production version change, OpenRegister SHALL make each schedule one scheduled flow. Applying SHALL be idempotent, and an unrelated save of the application SHALL NOT apply anything.

#### Scenario: First publish creates a published flow

- **WHEN** a production version with one enabled cron schedule is published
- **THEN** one enabled flow exists with trigger `schedule`, that cron, and a published version, and the schedule worker fires it when it is due

#### Scenario: Publishing the same manifest again changes nothing

- **WHEN** the same user publishes a manifest whose schedules are unchanged
- **THEN** no flow is written and no flow version is added

#### Scenario: A changed schedule updates its flow

- **WHEN** a published schedule's cron or arguments change
- **THEN** the same flow carries them in a new published version

#### Scenario: A disabled schedule disables its flow

- **WHEN** a schedule is published with `enabled: false`
- **THEN** its flow stays and does not fire

#### Scenario: A production version of another application is refused

- **WHEN** an application's production version cannot be read or belongs to another application
- **THEN** no flow is written or removed and the refusal is logged

#### Scenario: A removed schedule removes its flow

- **WHEN** a published manifest no longer holds a schedule, or the application is deleted
- **THEN** its flow is removed, and flows an author drew for that application stay

### Requirement: Action type must be allow-listed

A schedule SHALL only become a flow through a closed, server-controlled map from action type to flow node. A schedule whose `action` is not on the map, or that lacks the action's argument, SHALL be refused and logged with its application and id. `openconnector:synchronization` SHALL read `arguments.synchronizationId`.

#### Scenario: Non-allow-listed action is refused

- **WHEN** a schedule declares `action: "OCA\\Evil\\Backdoor"`
- **THEN** no flow is written for it and the refusal is logged

### Requirement: An interval maps to an exact cron expression or is refused

An `interval` SHALL become a cron expression only when one fires at exactly that interval: whole minutes dividing an hour, whole hours dividing a day, one day, or one week. Any other interval SHALL be refused and logged.

#### Scenario: A daily interval

- **WHEN** a schedule declares `interval: 86400`
- **THEN** its flow's cron is `0 0 * * *`

#### Scenario: An interval with no exact cron

- **WHEN** a schedule declares `interval: 5400`
- **THEN** no flow is written for it and the refusal names the schedule

### Requirement: A schedule runs as the user who published it

A compiled flow's schedule trigger SHALL declare as `runAs` the user who published the production version, and that user SHALL own the flow. Publishing again as another user SHALL change the identity of the application's schedules, and each change SHALL be recorded in the application's audit trail with the previous user, the new user and the time. The identity SHALL be re-resolved at each firing as `delegated-identity` requires.

#### Scenario: The publisher is the acting identity

- **WHEN** user P publishes an application owned by user O
- **THEN** a run of its schedule acts as P, not O

#### Scenario: Publishing as someone else changes the identity

- **GIVEN** a schedule published by P
- **WHEN** Q publishes the same schedules
- **THEN** the run acts as Q, and the audit trail records that it changed from P to Q, and when

#### Scenario: No publisher is refused

- **WHEN** a production version changes with no signed-in user
- **THEN** no flow is written and the refusal is logged

#### Scenario: A refusal reaches the caller of the publish

- **WHEN** a publish succeeds and a schedule of it is refused
- **THEN** the response carries the reason in the header `X-OpenRegister-Schedule-Refused`

#### Scenario: An on-disk app with no publisher is refused

- **WHEN** an app ships schedules in its own manifest and has no published application object
- **THEN** no flow exists for them and each refusal is logged

### Requirement: A schedule SHALL NOT run as another tenant's user

A compiled flow SHALL belong to the application's `@self.organisation`. One tenant's schedule SHALL NOT be able to run as another tenant's user: the publisher SHALL be a member of that organisation when publishing, and the flow's `runAs` SHALL be a member at each firing.

#### Scenario: The flow takes the application's organisation

- **WHEN** a member of the application's organisation publishes while another organisation is active
- **THEN** the flow belongs to the application's organisation

#### Scenario: The same slug in another organisation is another application

- **GIVEN** a compiled flow of an application
- **WHEN** an application of another organisation with the same slug is published or deleted
- **THEN** that flow is untouched

#### Scenario: A publisher outside the organisation is refused

- **WHEN** a user who is not a member of the application's organisation publishes, administrators included
- **THEN** no flow is written and the refusal is logged

#### Scenario: An application with no organisation is refused

- **WHEN** an application with no organisation is published
- **THEN** no flow is written and the refusal is logged

#### Scenario: A member who left stops the schedule

- **GIVEN** a compiled flow whose `runAs` has left the organisation
- **WHEN** the schedule worker ticks
- **THEN** no run is queued, the flow is disabled, and its status message gives the reason
