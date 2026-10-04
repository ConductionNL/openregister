## Scope

An app can now remove the example data it loaded. Every `ConfigurationService::importFromApp()` call runs under its own import job id, the jobs that created objects are recorded per app id, and `ConfigurationService::softDeleteAppImports($appId)` soft-deletes what they created: the one call a setup wizard's "remove this example set" makes.

Learniq round 2, recon A, section 4 row `demo-data-purge-by-batch`. Recon section 1 found the gap fleet-wide: learniq's `DemoDataService::install()` and decidesk's `SeedProfileService::install()` both load through `importFromApp()`, and `ImportService::softDeleteByImportJobId()` was wired only to the CSV and Excel import path, so nothing could find those objects again.

## What changes

- `ImportHandler::importFromApp()` wraps its `importFromJson()` call in a new `AppImportJobRecorder`: `begin()` sets the request-scoped job id the CSV importer already uses (`AuditTrailMapper::setRequestImportJobId()`), `end()` restores the outer one in `finally`. Every audit row the import writes carries the id, for created and updated objects.
- After the import the recorder counts the job's `create` rows (`AuditTrailMapper::countByImportJobId()`, new). When there is at least one, it appends `{jobId, version, created, importedAt}` to OpenRegister's app config under `import_jobs_<appId>` (lazy, capped at 50, hashed when the key would pass 64 characters). The result carries `importJobId`. Upgrades that only update or skip add nothing.
- When an import wrote objects and none carries the job id, it logs a warning naming the app: the audit trail is off, so the import cannot be removed by job.
- `ConfigurationService::listImportJobs($appId)` lists the recorded jobs. `softDeleteAppImports($appId)` calls `ImportService::softDeleteByImportJobId()` per job inside `SystemOperationContext::run()` (the import ran as a system operation too), forgets each job that removed cleanly, and keeps a job with errors so it can be retried or finished with `occ`. `ImportService.php` itself is unchanged.
- `occ openregister:objects:purge --import-job <id>` resolves the job's objects (`AuditTrailMapper::objectUuidsByImportJobId()`, new) and purges them under the command's existing rules: dry run unless `--apply`, archival and live rows refused unless `--force`. In job mode a missing object reads as already gone, so a re-run after a partial purge exits 0.
- `RegistersController::rollbackImport()` answers `409` for a recorded app import job and deletes nothing. Archival schemas refuse HTTP deletes, and learniq's demo set spans 17 archival and 11 append-only schemas, so an app's example data leaves through the app's own service call or through `occ`, never through HTTP. CSV and Excel rollbacks work as before.

Learniq and decidesk already load each example set under its own app id (`learniq.demo`, `decidesk.profile.<id>`), so each set is removable on its own. The wizard change in learniq is one call:

```php
$report = $this->configurationService()->softDeleteAppImports(appId: 'learniq.demo');
```

Imports made before this change carry no job id and cannot be removed by job. Documented in `docs/Technical/register-descriptors.md`.

## Spec

`openspec/changes/demo-data-purge-by-batch/` (proposal, design, tasks, two delta specs: four requirements on `data-import-export`, one on `archival-annotation-vocabulary`). `openspec validate demo-data-purge-by-batch`: valid. Both main specs set to in-progress.

## Verification

Each command with its exit code, run in the lane clone on the committed tree:

| Check | Exit | Notes |
|---|---|---|
| `vendor/bin/phpunit --no-coverage --filter 'ImportHandler\|AppImportJobRecorder\|ConfigurationService\|PurgeObjectCommand\|RegistersController\|AuditTrailMapper\|ImportService\|ApplicationTest\|ImportRollback'` | 0 | 657 tests, 1584 assertions, plus `AuditTrailImportJobQueriesTest` (3 tests). |
| Mutation check: the `finally` that ends the stamp, and the rollback `409` | tests red | `testTheStampIsClearedWhenTheImportThrows` and `testRollbackRefusesAnAppImportJob` both failed, files restored. |
| `composer check:strict` (once, `TMPDIR` outside the checkout, `COMPOSER_PROCESS_TIMEOUT=0`) | 1 | lint 0, migration-version 0, phpcs 0, psalm 0 (full tree), phpstan 0 (full tree). Two reds, neither on this diff: see the next two rows. |
| red 1: `phpmd` | 2 | Flags only `lib/Listener/ConsentEnvelopeOnSaveListener.php` (from #4049, not touched here). That file passes phpmd with a fresh pdepend cache; the shared `~/.pdepend` cache across lane clones serves a stale measure. Full `phpmd lib` rerun (both rulesets) with a fresh private pdepend cache: exit 0. |
| red 2: `test:all` | 1 | 24,105 tests OK; the exit 1 is PHPUnit's "No code coverage driver available" (no xdebug or pcov on this box). Full suite rerun with `--no-coverage`: 24,105 tests, exit 0. |
| phpcs, phpmd (fresh cache), phpstan, psalm on the seven touched lib files | 0, 0, 0, 0 | |
| `npm run lint` | 0 | 0 errors, 932 inherited warnings. |
| `npm run format` | 0 | |
| `npm run test:l10n` | 0 | |
| `run-hydra-gates.sh --scope-to-diff --base origin/development` | 2 | gate-57 orphaned-write-capability flagged `ConfigurationService::importJobs()` (a read whose name starts with "import"); renamed to `listImportJobs()` in `054905abfc`, and the gate-57 checker rerun on the file reports nothing. gate-112 newman-reach is inherited (below). gate-5 route-auth, gate-6 orphan-auth, gate-7 no-admin-idor, gate-8, gate-9, gate-14, gate-16 spec-coverage, gate-25, gate-46, gate-48, gate-49, gate-1/2/3 PASS. gate-19 e2e-coverage is advisory (every new scenario carries a reason-bearing `@e2e exclude`). gate-68 did not run (its checker exited without a count). |
| `openspec validate demo-data-purge-by-batch` | 0 | valid |

## CI (read once)

36 pass, 8 skipping, 0 fail: the full 44-check set, including the PHPUnit coverage guard and CI's own phpmd.

## opsx-verify (headless)

| Dimension | Result |
|---|---|
| Completeness | 10/10 tasks, 5/5 requirements implemented |
| Correctness | 5/5 requirements mapped to code; all 15 tests the specs cite exist and pass |
| Coherence | Design D1 to D5 followed (request-scope stamp restoring the outer one, per-app-id list of jobs that created objects, system-operation removal forgetting clean jobs, `--import-job` on the existing command, `409` on the HTTP rollback) |
| API and browser tests | Skipped: no UI surface in OpenRegister; the consuming app's wizard owns the button. Covered by PHPUnit |

No CRITICAL and no WARNING.

## Inherited findings

gate-112 newman-reach names 21 Postman collections that CI never runs; this PR touches none of them. phpmd's `ConsentEnvelopeOnSaveListener` finding (above) is in a file this PR does not touch.

Base: `development` (not stacked on #4079; the two changes touch different files).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
