# apphost-scheduling Specification

## Purpose
Recurring work for manifest-driven apps that ship no PHP: the manifest declares schedules.

Reconciling those schedules into OpenConnector jobs is no longer a requirement. The reconciler `TimedJob` was removed on purpose when OpenRegister moved to one flow engine (#3382), and the `ScheduleReconciler` class it drove has been deleted. Scheduled work runs as scheduled flows.

## Requirements

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
- **THEN** the manifest validates against the manifest v2 schema and
  `nightly-sync` parses as a schedule declaration for that application

#### Scenario: Virtual app declares a cron schedule

- **WHEN** a virtual app declares `schedules: [{ id: "weekday-report", cron:
  "0 6 * * 1-5", action: "openconnector:synchronization", arguments: {
  synchronization: "00000000-0000-0000-0000-000000000000" }, enabled: true }]`
- **THEN** the manifest validates and the schedule's next fire time can be
  computed from the cron expression

#### Scenario: On-disk manifest app declares a schedule

- **WHEN** an on-disk AppHost manifest app (manifest served by OpenRegister
  directly) declares the same `schedules[]` entry
- **THEN** the declaration parses identically to a virtual app's: the manifest
  source does not change it

#### Scenario: Schedule missing both interval and cron is rejected

- **WHEN** a schedule entry declares neither `interval` nor `cron` (or declares
  both)
- **THEN** the entry SHALL be refused as a schedule declaration, naming its `id`
