# Tasks: migrate-run-between-versions

## 1. Engine

- [ ] 1.1 `FlowRunMigrationService`: marking validation against the target with a mapping, dry run, transactional apply under the run lock, `migrated` log entry.
- [ ] 1.2 Timer supersession with reason `migrated`; pending tasks re-referenced.

## 2. API

- [ ] 2.1 Single-run and bulk routes with the `run` plus `manage` guard and per-run reporting.

## 3. Retirement

- [ ] 3.1 A deprecated version with no pinned runs can be retired; refused otherwise with the count.

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/run-migration.spec.ts`: publish a version with a renamed node, migrate a parked run, complete the task.
- [ ] 4.2 Unit tests for the validator, dry run, apply, timers and bulk skip.
