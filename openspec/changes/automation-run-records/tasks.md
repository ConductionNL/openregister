# Tasks: automation-run-records

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Store

- [x] 1.1 Migration `openregister_automation_records` (D-1), `lib/Db/AutomationRecord.php`, `AutomationRecordMapper.php`; `key` on notification and lifecycle action declarations in the annotation vocabulary.
- [x] 1.2 Retention job and `automation_record_retention_days` (D-5).

## 2. Write

- [x] 2.1 `lib/Service/Notification/AnnotationNotificationDispatcher.php`: one record per evaluation, aggregated per D-2, skipped with its gate. Verify: unit tests for sent, partial, duplicate skipped, no recipients.
- [x] 2.2 `lib/Service/Lifecycle/LifecycleActionExecutor.php`: one record per action reached (D-3), rollback never `succeeded`. Verify: unit tests for succeeded, skipped, failed, rolled back.

## 3. Read

- [x] 3.1 `lib/Controller/AutomationRecordsController.php` and `lib/Service/AutomationRecordService.php` with the access rule of D-4; route `GET /api/automation-records`. Newman: both kinds by key prefix, a caller without read access sees nothing.
  - [x] Controller, service, route and access rule, with unit tests (admin, reader, foreign object, anonymous).
  - [x] Newman case written: `tests/integration/openregister-automation-records.postman_collection.json` (both kinds by key prefix, a caller without read access sees nothing, anonymous refused).
  - [x] First live Newman pass: needs a running stack; the collection carries `@newman exclude` until then, drop it after the first green run.
    > 2026-11-25, sandbox Nextcloud 34 on PostgreSQL 16 (main 55f2aa3e): 23 assertions, 0 failed, with a background
    > worker running jobs while the notification record is polled (CI's seed provides one; in the sandbox a cron
    > tick between polls stood in for it). Without a worker every assertion but the four on the notification record
    > passes, and one cron tick later the record is there: `aut-<stamp>-n`, `submitted`, outcome `sent`, next to the
    > lifecycle-action record `aut-<stamp>-l`, `succeeded`. The `@newman exclude` marker is dropped.
- [ ] 3.2 `docs/`: the `key` on declarations and the records route. Tell buildiq the change name so `logic-automation-run-log` reads it.
  - [x] `docs/features/webhooks-and-notifications.md` and `docs/features/workflow-automation.md`.
  - [ ] Tell buildiq: left to the maintainers.

## 4. Close

- [x] 4.1 `@spec` tags; `openspec validate automation-run-records --strict`.
  - [x] `@spec` tags on every new class and method.
  - [x] `openspec validate --strict` passes; the over-long requirements were split into one behaviour each.
- [x] 4.2 Hydra gates route-auth and no-admin-idor on the new controller.
