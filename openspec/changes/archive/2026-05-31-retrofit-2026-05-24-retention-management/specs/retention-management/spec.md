---
retrofit: true
---

# Retention Management — Retrofit Delta 2026-05-24

This delta adds three new requirements to the `retention-management` capability covering observed behavior that the existing spec does not yet describe:

1. AVG / GDPR retention enforcement driven by `processing_activity_id` from the audit-trail ledger (parallel to the Archiefwet 1995 destruction-list workflow already specified).
2. Daily prune of the `openregister_realtime_events` log table.
3. Retention/Object/Archival settings handler utility behavior (version reporting and boolean coercion of mixed-type config values).

Code already exists — requirements describe what the code does, not what it should do.

## ADDED Requirements

### Requirement: The system MUST run a daily AVG / GDPR retention pass driven by per-activity bewaartermijn

A `TimedJob` (`AvgRetentionJob`, 24-hour interval) MUST invoke `AvgRetentionService::runRetentionPass()` which walks every published `Verwerkingsactiviteit` catalog row, computes a bewaartermijn cut-off per activity, finds audit-trail objects whose **latest** `created` timestamp predates the cut-off, and soft-deletes them. This is distinct from the Archiefwet destruction-list workflow: it operates on the audit-trail's `processing_activity_id` column, not on `archiefactiedatum`, and serves GDPR Article 5(1)(e) (storage limitation).

#### Scenario: Daily job runs the retention pass unless disabled

- **GIVEN** the app-config key `avg_retention_enabled` is unset or `true`
- **WHEN** the `AvgRetentionJob` (24-hour `TimedJob`) fires
- **THEN** it MUST call `AvgRetentionService::runRetentionPass()` with the `dryRun` flag read from app-config key `avg_retention_dry_run` (default `false`)
- **AND** it MUST log an info entry with `evaluatedActivities`, `skippedActivities`, `objectsErased`, and the resolved `dryRun` flag
- **AND** any `\Throwable` raised by the service MUST be caught and logged at `error` level without re-throwing

#### Scenario: Disable toggle short-circuits the job

- **GIVEN** the app-config key `avg_retention_enabled` is the string `"false"` (or any value parsed as boolean `false` by `FILTER_VALIDATE_BOOLEAN`)
- **WHEN** the `AvgRetentionJob` fires
- **THEN** the job MUST log an info entry indicating retention is disabled
- **AND** it MUST return without invoking the service

#### Scenario: Pass evaluates only published activities

- **GIVEN** the catalog contains two `Verwerkingsactiviteit` rows with `status='published'` and one with `status='draft'`
- **WHEN** `runRetentionPass(dryRun: false)` is invoked
- **THEN** the service MUST iterate exactly the two published activities (via `VerwerkingsactiviteitMapper::findAll(status: 'published')`)
- **AND** the summary's `evaluatedActivities` MUST equal the number of activities that produced a per-activity result, and `skippedActivities` MUST equal the number that returned `null`

#### Scenario: Activity without bewaartermijn is skipped

- **GIVEN** a published activity whose `bewaartermijn` is null or the empty string
- **WHEN** `processActivity()` is invoked for that activity
- **THEN** the method MUST return `null` and the activity MUST NOT contribute to `perActivity`
- **AND** the summary's `skippedActivities` counter MUST be incremented by 1

#### Scenario: Activity with unparseable bewaartermijn is skipped with a warning

- **GIVEN** a published activity whose `bewaartermijn` is a string that is not a valid ISO-8601 duration (e.g. `"10 years"` instead of `"P10Y"`)
- **WHEN** `computeCutoff()` attempts `new DateInterval($duration)` and catches the resulting `\Exception`
- **THEN** `computeCutoff()` MUST return `null`
- **AND** `processActivity()` MUST log a warning `[AVG retention] Unparseable bewaartermijn — skipping activity` carrying the activity uuid and the offending value
- **AND** the activity MUST be reported under `skippedActivities`

#### Scenario: Cut-off is computed as now-minus-bewaartermijn

- **GIVEN** the current reference time is `2026-05-24T00:00:00Z` and bewaartermijn is `"P10Y"`
- **WHEN** `computeCutoff()` runs
- **THEN** the returned cut-off `DateTime` MUST equal `2016-05-24T00:00:00Z`
- **AND** it MUST be returned in ISO-8601 form (`format('c')`) within the per-activity summary's `cutoff` field

#### Scenario: Overdue candidates are those whose newest audit row predates the cut-off

- **GIVEN** an activity uuid `act-1` with cut-off `2016-05-24`
- **AND** the `openregister_audit_trails` table contains rows for object A (latest `created` = `2015-12-01`) and object B (latest `created` = `2020-03-15`), both tagged `processing_activity_id='act-1'`
- **WHEN** `findOverdueObjectsForActivity('act-1', cutoff)` runs
- **THEN** the SQL MUST `GROUP BY object, object_uuid, register, schema` and apply `HAVING MAX(created) < :cutoff`
- **AND** object A MUST be returned and object B MUST NOT
- **AND** each returned row MUST carry `object` (int), `object_uuid` (string|null), `register` (int|null), `schema` (int|null)

#### Scenario: SQL failure during enumeration is contained per activity

- **GIVEN** the query builder for an activity uuid throws a `\Throwable`
- **WHEN** `findOverdueObjectsForActivity()` catches it
- **THEN** the method MUST log a warning `[AVG retention] Failed to enumerate overdue objects for activity` with the activity uuid and the error message
- **AND** the method MUST return `[]` so the retention pass continues with the next activity

#### Scenario: Erasure is a soft-delete carrying the activity attribution

- **GIVEN** `processActivity()` resolves three candidate rows and `dryRun=false`
- **WHEN** `erasePastRetention()` runs
- **THEN** for each candidate the loader MUST call `MagicMapper::find($uuidOrIntId, _rbac: false, _multitenancy: false)`
- **AND** each loaded `ObjectEntity` MUST have its `deleted` field set to an array `{ deletedBy: 'system', deletedAt: <ISO 8601 ATOM>, reason: 'avg-bewaartermijn', activityUuid: <activity uuid>, bewaartermijn: <activity bewaartermijn> }`
- **AND** the same `ObjectEntity` MUST have its `processingActivityId` set to the owning activity's uuid before being passed to `objectMapper->update()`
- **AND** the per-activity `erased` count MUST equal the number of successful `update()` calls; failures MUST be logged at warning level and MUST NOT abort the loop

#### Scenario: Dry run reports candidates without writing

- **GIVEN** `runRetentionPass(dryRun: true)` is invoked with two overdue candidates for an activity
- **WHEN** `processActivity()` runs
- **THEN** `erasePastRetention()` MUST NOT be called
- **AND** the per-activity entry MUST report `matchedObjects=2`, `erased=0`
- **AND** the summary's `dryRun` field MUST be `true`

#### Scenario: Candidate loader prefers UUID over int id

- **GIVEN** a candidate row with both `object_uuid='abc-123'` and `object=42`
- **WHEN** `loadCandidate()` runs
- **THEN** it MUST call `objectMapper->find('abc-123', ...)` (uuid wins)

#### Scenario: Candidate loader falls back to int id when uuid is empty

- **GIVEN** a candidate row with `object_uuid=null` and `object=42`
- **WHEN** `loadCandidate()` runs
- **THEN** it MUST call `objectMapper->find(42, ...)`

#### Scenario: Candidate that no longer exists is skipped silently

- **GIVEN** `objectMapper->find()` raises `DoesNotExistException` for a candidate (e.g. already hard-deleted)
- **WHEN** `loadCandidate()` catches it
- **THEN** the method MUST return `null` without logging
- **AND** the candidate MUST NOT count toward `erased`

### Requirement: The system MUST prune the realtime events log daily

A separate `TimedJob` (`RealtimeEventRetentionJob`, 24-hour interval) MUST bound the size of the `openregister_realtime_events` table by deleting rows older than a configurable retention window. This is independent of the AVG retention pass and of the Archiefwet destruction workflow — it manages the SSE event ledger used by the realtime-updates feature.

#### Scenario: Default retention window is 7 days

- **GIVEN** the app-config key `realtime_event_retention_seconds` is unset
- **WHEN** the job runs
- **THEN** it MUST call `RealtimeEventMapper::deleteOlderThan(retentionSeconds: 604800)` (7 × 86400)
- **AND** it MUST log an info entry with `retentionSeconds=604800` and `deletedRows=<count returned by mapper>`

#### Scenario: Configurable retention window override

- **GIVEN** the app-config key `realtime_event_retention_seconds` is set to `"259200"` (3 days)
- **WHEN** the job runs
- **THEN** it MUST pass `259200` as the `retentionSeconds` argument to `deleteOlderThan()`

#### Scenario: Setting retention to zero or negative disables the prune

- **GIVEN** the app-config key `realtime_event_retention_seconds` is `"0"` (or any string parsed as int ≤ 0)
- **WHEN** the job fires on its scheduled tick
- **THEN** the job MUST log an info entry `[RealtimeEventRetentionJob] Retention disabled (value <= 0), skipping prune`
- **AND** it MUST return without touching the `openregister_realtime_events` table

#### Scenario: Mapper failure is logged and swallowed

- **GIVEN** `deleteOlderThan()` raises a `\Throwable` (e.g. DB connection error)
- **WHEN** the job's `run()` catches it
- **THEN** the job MUST log the error at `error` level with the message prefix `[RealtimeEventRetentionJob] Prune failed:`
- **AND** the job MUST NOT re-throw — the next scheduled tick will retry

#### Scenario: Job interval is fixed at 24 hours

- **GIVEN** the constructor builds a new `RealtimeEventRetentionJob`
- **WHEN** the parent `TimedJob` is configured
- **THEN** `setInterval(seconds: 86400)` MUST be called (running more often than daily is intentionally rejected per the implementation contract)

### Requirement: The retention settings handler MUST expose app metadata and normalise mixed-type toggle values

`ObjectRetentionHandler` is the back-end handler for the Retention/Object/Archival settings sections. Beyond the read/write methods already covered by the settings retrofit, it MUST provide a version-info endpoint backing the settings UI's "About" panel and MUST coerce mixed-type boolean inputs from stored JSON (strings, ints, native booleans) to PHP `bool`.

#### Scenario: Version info reports app name and version

- **WHEN** `ObjectRetentionHandler::getVersionInfoOnly()` is called
- **THEN** the method MUST return an array containing exactly `appName` = `"Open Register"` and `appVersion` = `"0.2.3"`
- **AND** any thrown `Exception` MUST be re-thrown as `\RuntimeException` with the prefix `Failed to retrieve version information:`

#### Scenario: Native booleans pass through unchanged

- **GIVEN** the input value is the PHP boolean `true` (resp. `false`)
- **WHEN** `convertToBoolean()` is called
- **THEN** it MUST return `true` (resp. `false`) without further processing

#### Scenario: Truthy strings normalise to true

- **GIVEN** the input value is the string `"true"`, `"1"`, `"yes"`, or `"on"` (case-insensitive)
- **WHEN** `convertToBoolean()` is called
- **THEN** it MUST return `true`
- **AND** any other string (e.g. `"false"`, `"no"`, `"random"`) MUST return `false`

#### Scenario: Numeric values use C-style zero/non-zero semantics

- **GIVEN** the input value is the int `0`, `1`, `42`, or the float `0.0`
- **WHEN** `convertToBoolean()` is called
- **THEN** the method MUST return `false` for `0` / `0.0` and `true` for any non-zero numeric value
- **AND** the comparison MUST use `(int) $value !== 0` (so the float `0.5` casts to int `0` and returns `false`)

#### Scenario: convertToBoolean drives audit/search-trail toggle parsing

- **GIVEN** the stored `retention` JSON blob contains `auditTrailsEnabled: "true"` and `searchTrailsEnabled: 1`
- **WHEN** `getRetentionSettingsOnly()` decodes the blob
- **THEN** both flags MUST be returned as PHP boolean `true` (via `convertToBoolean()`), not as the raw string/int — so downstream callers can rely on strict `=== true` checks
