# Design: demo-data-purge-by-batch

## Context

See proposal.md for why. The pieces that exist today:

- `AuditTrailMapper::setRequestImportJobId()` holds a request-scoped job id, and
  `createAuditTrail()` stamps it on every audit row it builds
  (`$objectEntity->getImportJobId() ?? $this->requestImportJobId`). The column
  `import_job_id` is indexed (`Version1Date20260502120000`).
- `ImportService::importFromCsv()` and `importFromExcel()` generate a UUID v4, set the
  scope, and clear it in `finally`.
- `ImportService::softDeleteByImportJobId()` reads the `create` rows for a job and calls
  `ObjectService::deleteObject($uuid)` for each, reporting per-object outcomes. The
  bare-uuid call has no schema in scope, so it soft-deletes objects on archival and
  append-only schemas too (the documented behaviour of a scopeless `deleteObject()`).
- `RegistersController::rollbackImport()` exposes that over HTTP to the importer or an
  admin.
- `PurgeObjectCommand` hard-deletes rows by UUID, dry run by default, `--force` for
  archival or live rows.
- `ConfigurationService::importFromApp()` runs `ImportHandler::importFromApp()` as a
  system operation, which calls `importFromJson()`. Learniq's demo import passes the app
  id `learniq.demo`, separate from the real configuration import `learniq`.

## Goals / Non-Goals

**Goals:**

- One call a setup wizard can make to remove the example set it loaded.
- No new HTTP door onto example data that spans archival schemas.
- A failure to trace an import is loud, not an empty list.

**Non-Goals:**

- No new column on the object tables. The audit trail stays the canonical record, as
  `ObjectEntity::$importJobId`'s docblock decided.
- No change to the CSV and Excel import rollback.
- No UI. The wizard button lives in each app.
- No removal of objects a job only updated.

## Decisions

### D1: Stamp through the request scope, restoring an outer one

`AppImportJobRecorder::begin()` generates the id, remembers the scope that was active,
and sets the new one; `end()` restores what was there. `ImportHandler::importFromApp()`
wraps its `importFromJson()` call in `begin()` / `finally end()`. Restoring rather than
clearing matters when an import runs inside another import: the outer import keeps its
own id for the rows it writes after the inner one returns.

Considered: stamping each `ObjectEntity` with `setImportJobId()`. Rejected, because
`importFromJson()` writes through `saveObject()` with arrays in several places (seed
blocks, `components.objects`, top-level `objects`), and the request scope covers all of
them without threading the id through each.

### D2: Record only jobs that created something, keyed by the import's app id

The record lives in OpenRegister's own app config, one lazy key per app id
(`import_jobs_<appId>`, or `import_jobs_<sha1 of appId>` when that would pass the 64
character key limit), as JSON `{appId, jobs: [{jobId, version, created, importedAt}]}`,
capped at 50 jobs. Decidesk loads each example set under its own app id
(`decidesk.profile.<id>`), so each set is removable on its own.

It is written only when the job created at least one traceable object, counted from
the audit table (`AuditTrailMapper::countByImportJobId()`), which is the authority
`softDeleteByImportJobId()` reads. Every app upgrade re-runs `importFromApp()`; recording
the no-op runs would bury the one job that matters.

A list, not one id, because a set can be loaded twice: a second load after an upgrade
creates only the objects that are new, and removing only the latest job would leave the
first load's objects behind.

Considered: writing into the consuming app's own config namespace. Rejected: OpenRegister
owns the record and the removal; writing into another app's namespace makes ownership
unclear.

### D3: The removal runs in-process, as a system operation, and forgets clean jobs

`ConfigurationService::softDeleteAppImports($appId)` resolves the recorder and
`ImportService` lazily through the container (the same reason `getImportHandler()` is
lazy: circular construction), and runs inside `SystemOperationContext::run()` because the
import did. The objects were written by a system operation, and RBAC on a schema such as
learniq's append-only records would otherwise refuse the administrator who clicked the
button for a row the system wrote. Who may click is the app's decision (learniq's wizard is
admin-only).

A job is forgotten only when its report has no errors, so a partial removal can be retried
or finished with `occ`.

### D4: `--import-job` on the existing purge command, not a new command

The purge command already holds the right rules for destroying rows (dry run, `--force`
for archival and live rows). `--import-job` only changes where the UUIDs come from. In job
mode a missing object reads as already gone, so the command is safe to re-run.

### D5: The HTTP rollback refuses app import jobs

Stamping app imports makes their job ids valid input for `rollbackImport()`, which would
soft-delete archival example rows over HTTP. The route asks the recorder whether the id
belongs to a recorded app import and answers `409` if so. It resolves the recorder through
the controller's existing container, so the constructor does not change.

### Declarative-vs-imperative decision

| Behaviour | Path | Rationale |
|---|---|---|
| Stamp and record app import jobs | Imperative (`AppImportJobRecorder`) | Bookkeeping inside the import pipeline; no schema behaviour. |
| Remove an app's example set | Imperative (`ConfigurationService`) | ADR-031 exception: scheduled or bulk work across many schemas, driven by an app's wizard. |
| Purge by job | Imperative (`occ`) | Administrative CLI, the only path allowed to destroy archival rows. |

## How learniq adopts this

`DemoDataService::install()` already passes `learniq.demo`. The wizard's "remove this
example set" action calls:

```php
$report = $this->configurationService()->softDeleteAppImports(appId: 'learniq.demo');
```

and shows `$report['softDeleted']` and, when `$report['errors']` is not empty, tells the
administrator that the rest can be removed with
`occ openregister:objects:purge --import-job <id> --force --apply`.

## Seed Data

None. This change adds no schema.

## Risks / Trade-offs

- [Audit trails disabled] → nothing is stamped, nothing is recorded, and the import logs
  a warning naming the app. The wizard should hide its remove button when
  `listImportJobs()` is empty.
- [An audit row expires] → a schema whose retention expires audit rows loses the trace
  after that period. The platform default is indefinite retention.
- [A user edited an example object] → it is still removed, because the job created it.
  Removal is a soft delete, so it can be restored.
- [Side-effect objects] → objects created by hooks during the import carry the id too
  and are removed with the set. They exist because of the set.
- [A bare-uuid soft delete skips the archival gate] → that is `softDeleteByImportJobId()`'s
  existing behaviour and why D5 closes the HTTP route for app jobs; permanent destruction
  of archival rows still needs `occ ... --force`.

## Migration Plan

Additive, no data migration. Imports before this change carry no job id and cannot be
removed by job; their objects stay where they are. Rollback is a revert.
