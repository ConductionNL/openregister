# Tasks: demo-data-purge-by-batch

## 1. Stamp and record

- [x] 1.1 Add `AuditTrailMapper::countByImportJobId(string $importJobId, ?string $action)` and `objectUuidsByImportJobId(string $importJobId)`; verify with `vendor/bin/phpunit --filter AuditTrailImportJobQueriesTest`.
- [x] 1.2 Add `lib/Service/Configuration/AppImportJobRecorder.php` with `begin()`, `end()`, `record()`, `jobs()`, `forget()` and `appForJob()`; verify with `vendor/bin/phpunit --filter AppImportJobRecorderTest`.
- [x] 1.3 Wrap `importFromJson()` in `ImportHandler::importFromApp()` with `begin()` / `finally end()`, record the job and return `importJobId`, taking the recorder as an optional constructor argument wired in `Application::buildImportHandler()`; verify with `vendor/bin/phpunit --filter ImportHandlerImportJobTest` and the existing `ImportHandler` tests.

## 2. Remove

- [x] 2.1 Add `ConfigurationService::listImportJobs()` and `softDeleteAppImports()` (lazy container resolution, system operation, forget clean jobs); verify with `vendor/bin/phpunit --filter ConfigurationServiceAppImportsTest`.
- [x] 2.2 Add `--import-job` to `PurgeObjectCommand` (UUID argument optional, missing object in job mode is already gone, refuse when given nothing); verify with `vendor/bin/phpunit --filter PurgeObjectCommandTest`.
- [x] 2.3 Make `RegistersController::rollbackImport()` answer 409 for a recorded app import job; verify with `vendor/bin/phpunit --filter RegistersControllerTest`.

## 3. Spec and verification

- [x] 3.1 Mark `openspec/specs/data-import-export/spec.md` and `openspec/specs/archival-annotation-vocabulary/spec.md` in progress and run `openspec validate demo-data-purge-by-batch`; verify it reports valid.
- [x] 3.2 Run `composer check:strict` once, `npm run lint`, and the hydra gates; record each exit code in the PR body.

Acceptance criteria (plain reminders, not tasks):
- A wizard can remove its example set with one service call.
- No HTTP route removes an app's example data.
- An import that cannot be traced says so in the log.
