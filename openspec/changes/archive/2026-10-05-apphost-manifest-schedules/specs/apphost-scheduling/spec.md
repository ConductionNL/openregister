## ADDED Requirements

### Requirement: Manifest declares scheduled tasks

An OpenBuild/manifest-driven app SHALL be able to declare recurring work in its
manifest via a top-level `schedules[]` array (manifest v2). Each entry SHALL
carry a stable `id`, exactly one of `interval` (positive integer seconds) or
`cron` (a cron expression), an allow-listed `action` type, an `arguments`
object, and an `enabled` boolean (default `true`). The schedule declaration
SHALL NOT carry a runtime execution identity — the executing user is resolved
from the owning application, never supplied by the manifest author.

#### Scenario: Virtual app declares an interval schedule

- **WHEN** a virtual app whose manifest is stored as an OpenRegister
  `application` object in the `openbuild` register declares
  `schedules: [{ id: "nightly-sync", interval: 86400, action:
  "openconnector:synchronization", arguments: { synchronization:
  "00000000-0000-0000-0000-000000000000" }, enabled: true }]`
- **THEN** the manifest validates against the manifest v2 schema and the
  reconciler treats `nightly-sync` as a schedulable declaration for that
  application

#### Scenario: Virtual app declares a cron schedule

- **WHEN** a virtual app declares `schedules: [{ id: "weekday-report", cron:
  "0 6 * * 1-5", action: "openconnector:synchronization", arguments: {
  synchronization: "00000000-0000-0000-0000-000000000000" }, enabled: true }]`
- **THEN** the manifest validates and the reconciler computes the schedule's
  next fire time from the cron expression and writes it as the reconciled OC
  `job` object's `nextRun`, so the existing `JobService` executes it on schedule

#### Scenario: On-disk manifest app declares a schedule

- **WHEN** an on-disk AppHost manifest app (manifest served by OpenRegister
  directly) declares the same `schedules[]` entry
- **THEN** the reconciler discovers and treats the declaration identically to a
  virtual app's — the manifest source does not change the scheduling behaviour

#### Scenario: Schedule missing both interval and cron is rejected

- **WHEN** a schedule entry declares neither `interval` nor `cron` (or declares
  both)
- **THEN** the entry SHALL NOT be reconciled into a `job` object and the
  reconciler SHALL log the rejected entry with its `applicationId` and `id`

### Requirement: Reconciler upserts an OpenConnector job idempotently

A single generic OpenRegister AppHost reconciler `TimedJob` SHALL, each tick,
enumerate the manifests of published apps (on-disk and virtual), read each
`schedules[]` entry, and idempotently UPSERT a corresponding OpenConnector `job`
OR object keyed on `applicationId + scheduleId` (mirroring
`lib/BackgroundJob/ScheduledWorkflowJob.php`). For an `interval` schedule the
reconciled `job` carries that `interval`; for a `cron` schedule the reconciler
SHALL evaluate the cron expression to the next fire time and write it as the
job's `nextRun`. Execution of the resulting `job` objects SHALL reuse the
existing OpenConnector `JobTask`/`JobService` path unchanged; the reconciler
SHALL NOT implement its own execution or logging.

#### Scenario: First reconciliation creates a job

- **WHEN** the reconciler runs and finds a declared, enabled, allow-listed
  schedule for which no `job` object keyed on `applicationId + scheduleId` yet
  exists
- **THEN** it creates one OpenConnector `job` object with `interval`/`cron`,
  `arguments`, `isEnabled: true`, `jobClass` set to the vetted generic action
  class for the schedule's `action` type, and `userId` set to the resolved
  application owner

#### Scenario: Re-reconciliation of an unchanged schedule is a no-op

- **WHEN** the reconciler runs again and a `job` object already exists for
  `applicationId + scheduleId` and matches the current declaration
- **THEN** no duplicate `job` object is created and the existing job's
  `lastRun`/`nextRun` bookkeeping (owned by `JobService`) is not disturbed

#### Scenario: Changed schedule fields are updated in place

- **WHEN** a manifest changes an existing schedule's `interval`, `cron`, or
  `arguments`
- **THEN** the reconciler updates the SAME `job` object (matched on
  `applicationId + scheduleId`) rather than creating a second one

### Requirement: Disabled or removed schedule disables or removes its job

A schedule set `enabled: false` or removed from a manifest SHALL have its
reconciled `job` object disabled or removed on the next reconciler tick, so a
withdrawn declaration never keeps executing.

#### Scenario: Schedule disabled in manifest

- **WHEN** a previously reconciled schedule is changed to `enabled: false`
- **THEN** the reconciler sets the corresponding `job` object's `isEnabled` to
  `false` so `JobService` skips it, without deleting its run history

#### Scenario: Schedule removed from manifest

- **WHEN** a previously reconciled schedule `id` is no longer present in the
  application's manifest
- **THEN** the reconciler disables or removes the orphaned `job` object keyed on
  that `applicationId + scheduleId` (garbage collection), leaving no live job
  for a declaration that no longer exists

### Requirement: Action type must be allow-listed

The reconciler SHALL only map a schedule to a `job` whose `jobClass` is drawn
from a closed, server-controlled allow-list of generic action types. A schedule
SHALL NEVER cause a raw PHP class name (FQCN) supplied in manifest data to be
used as a `jobClass`. Any schedule whose `action` is not on the allow-list SHALL
be rejected (no `job` created/updated) and logged.

#### Scenario: Allow-listed action is mapped to a vetted job class

- **WHEN** a schedule declares `action: "openconnector:synchronization"` and
  that type is on the allow-list
- **THEN** the reconciler sets the reconciled `job`'s `jobClass` to the vetted
  server-side class for that action type — not to any value taken from the
  manifest

#### Scenario: Non-allow-listed action is rejected

- **WHEN** a schedule declares `action: "OCA\\Evil\\Backdoor"` or any other
  value not on the allow-list
- **THEN** the reconciler creates/updates NO `job` object for that schedule and
  logs the rejected `action` with its `applicationId` and `id`

### Requirement: Reconciled job runs as the application owner

The reconciler SHALL set the reconciled `job` object's `userId` to the identity
resolved from the owning application (reusing the existing owner-impersonation
seam), never to an identity supplied by the manifest author. One tenant's
schedule SHALL NOT be able to run as another tenant's user.

#### Scenario: Owner resolved from the application, not the manifest

- **WHEN** a manifest schedule includes an author-supplied `runAs`/`owner`
  field naming a different user
- **THEN** the reconciler ignores the author-supplied value and sets the
  reconciled `job`'s `userId` to the owner resolved from the application record,
  so execution occurs under the app owner's identity

#### Scenario: Owner cannot be resolved

- **WHEN** the reconciler cannot resolve an owner for an application
- **THEN** it does NOT create a `job` that would run under an ambiguous or
  elevated identity, and logs the unresolved-owner skip for that application
