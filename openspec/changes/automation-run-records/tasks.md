# Tasks: automation-run-records

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Store

- [x] 1.1 Migration `openregister_automation_records` (D-1), `lib/Db/AutomationRecord.php`, `AutomationRecordMapper.php`; `key` on notification and lifecycle action declarations in the annotation vocabulary.
- [x] 1.2 Retention job and `automation_record_retention_days` (D-5).

## 2. Write

- [x] 2.1 `lib/Service/Notification/AnnotationNotificationDispatcher.php`: one record per evaluation, aggregated per D-2, skipped with its gate. Verify: unit tests for sent, partial, duplicate skipped, no recipients.
- [x] 2.2 `lib/Service/Lifecycle/LifecycleActionExecutor.php`: one record per action reached (D-3), rollback never `succeeded`. Verify: unit tests for succeeded, skipped, failed, rolled back.

## 3. Read

- [ ] 3.1 `lib/Controller/AutomationRecordsController.php` and `lib/Service/AutomationRecordService.php` with the access rule of D-4; route `GET /api/automation-records`. Newman: both kinds by key prefix, a caller without read access sees nothing.
  - [x] Controller, service, route and access rule, with unit tests (admin, reader, foreign object, anonymous).
  - [ ] Newman case: needs a running stack.
- [ ] 3.2 `docs/`: the `key` on declarations and the records route. Tell buildiq the change name so `logic-automation-run-log` reads it.
  - [x] `docs/features/webhooks-and-notifications.md` and `docs/features/workflow-automation.md`.
  - [ ] Tell buildiq: left to the maintainers.

## 4. Close

- [ ] 4.1 `@spec` tags; `openspec validate automation-run-records --strict`.
  - [x] `@spec` tags on every new class and method.
  - [ ] `openspec validate`: the CLI is not installed in the build environment.
- [x] 4.2 Hydra gates route-auth and no-admin-idor on the new controller.
