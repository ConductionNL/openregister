---
kind: code
depends_on: []
---

# Remove an app's example data by its import job

## Why

Setup wizards say "you can delete it afterwards" and then offer nothing that does.
Learniq's `DemoDataService::install()` and decidesk's `SeedProfileService::install()`
both load example data through `ConfigurationService::importFromApp()`, and nothing on
that path can find those objects again. Learniq's demo set alone is 405 objects across
134 schemas, 17 of them archival and 11 append-only.

OpenRegister already has the removal primitive. `ImportService::softDeleteByImportJobId()`
soft-deletes every object whose `create` audit row carries an import job id, and the CSV
and Excel importers stamp that id on every row they write. `importFromApp()` never stamps
one, so the primitive has nothing to find (recon A, section 1, row "Clean removal
(purge) of a loaded example dataset": "Missing fleet-wide, not just in learniq").

The recon row `demo-data-purge-by-batch` (learniq round 2, recon A, section 4) proposes
this in OpenRegister so every app that seeds through `importFromApp()` gets it, not only
learniq.

## What changes

- Every `importFromApp()` call runs under its own import job id. Every audit row the
  import writes (objects created and objects updated) carries it, through the request
  scope the CSV importer already uses.
- After the import, OpenRegister records the job id per app in its app config, but only
  when the job created at least one traceable object. The import result carries the id
  as `importJobId`.
- When an import wrote objects and none of them carries the job id, OpenRegister logs a
  warning: the audit trail is off, so this import cannot be removed by job.
- `ConfigurationService::importJobs($appId)` lists the recorded jobs, and
  `ConfigurationService::softDeleteAppImports($appId)` soft-deletes every object those
  jobs created and forgets each job that removed cleanly. This is the call a setup
  wizard's "remove this example set" makes. It runs in-process, as the import did.
- `occ openregister:objects:purge --import-job <id>` resolves the objects a job created
  and purges them with the command's existing rules: dry run unless `--apply`, archival
  and live objects refused unless `--force`.
- The HTTP rollback route (`rollbackImport`) refuses a job id that belongs to an app
  import. Archival schemas refuse HTTP deletes, so an app's example data leaves through
  the app's own wizard or through `occ`, never through a new HTTP door.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `data-import-export`: app configuration imports are stamped with an import job id,
  recorded per app, and removable by job through the service.
- `archival-annotation-vocabulary`: the purge command gains `--import-job`.

## Impact

- Code: `lib/Service/Configuration/ImportHandler.php`,
  `lib/Service/Configuration/AppImportJobRecorder.php` (new),
  `lib/Service/ConfigurationService.php`, `lib/Db/AuditTrailMapper.php` (a count and a UUID query),
  `lib/Command/PurgeObjectCommand.php`, `lib/Controller/RegistersController.php`,
  `lib/AppInfo/Application.php` (wiring).
- `lib/Service/ImportService.php` is unchanged: `softDeleteByImportJobId()` already does
  what the wizard needs once the rows carry the id.
- Data: no migration. The job id lives on the audit row (the column exists since
  `Version1Date20260502120000`) and the per-app record lives in app config.
- Dependent apps: learniq (`learniq.demo`) and decidesk (`decidesk.profile.<id>`, one app
  id per example set) can offer a real "remove this example set" button, per set. Nothing
  changes for an app that does not call the new methods.
