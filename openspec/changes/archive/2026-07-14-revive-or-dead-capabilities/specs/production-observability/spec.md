# Production Observability — delta

## MODIFIED Requirements

### Requirement: CRUD Operation Counters

The system MUST maintain monotonic counters for create, update, and delete operations on
objects, persisted across PHP request boundaries in the `openregister_metrics` table via
`MetricsService::recordMetric()`.

This requirement already existed but had **no implementation path**: `MetricsService` was
injected nowhere, `recordMetric()` had zero callers anywhere in `lib/`, and the
`openregister_metrics` table — created by a migration — was never written to. The counters
are now produced by `ObjectMetricsListener`, registered on the object lifecycle events that
`MagicMapper` (OpenRegister's canonical write path, inherited by every Conduction app)
already dispatches.

#### Scenario: Object creation writes a metric row

- **WHEN** an object in register `zaken` / schema `meldingen` is created
- **THEN** a row MUST be inserted into `openregister_metrics` with `metric_type = 'object_created'`
- **AND** `entity_type = 'object'` and `entity_id` = the object's uuid
- **AND** the `metadata` MUST carry `register` and `schema`, from which the
  `{register=…,schema=…}` counter labels are derived
- **AND** `user_id` MUST record the acting user when there is a session

#### Scenario: Object update and delete write metric rows

- **WHEN** an object is updated, **THEN** a row with `metric_type = 'object_updated'` MUST be written
- **WHEN** an object is deleted, **THEN** a row with `metric_type = 'object_deleted'` MUST be written

#### Scenario: Metrics recording never breaks the write it observes

- **WHEN** the metrics insert fails (e.g. the database is unavailable)
- **THEN** the failure MUST be logged and swallowed
- **AND** the object create/update/delete that triggered it MUST still succeed
