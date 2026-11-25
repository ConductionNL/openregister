# Tasks: a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did

> Measured 2026-11-25 against main: the store and both writers this change
> asks for were built by `automation-run-records` (one table for both kinds,
> 90-day retention, a writer in the notification dispatcher and in the
> lifecycle action executor, and `GET /api/automation-records` with the
> object-rights rule). A second store would be a second answer to "what did
> this automation do", so 1.1 to 1.3 are closed against that store and this
> change adds only the read in its own shape: `GET /api/automation-outcomes`.

- [x] 1.1 An `automation-outcome` record (kind, key, at, subject register/schema/id, outcome, reason, recipients) with a 90-day prune job; the idempotency `NotificationDispatchLog` stays as it is.
  - The record is `AutomationRecord` (`openregister_automation_records`) and the prune is `AutomationRecordRetentionJob` (`automation_record_retention_days`, default 90), both from `automation-run-records`; the dispatch log is untouched.
  - `lib/Service/AutomationOutcome.php` projects a record onto this change's shape: `kind` `notification`|`lifecycle`, `key`, `at`, `subject`, `outcome`, `reason` (at most 200 characters), and `recipients` for a notification.
- [x] 1.2 The notification dispatcher writes one `notification` record per send (sent or failed, recipient count, no body or address).
  - `AnnotationNotificationDispatcher` writes one record per evaluation through `NotificationRunRecorderInterface` (automation-run-records 2.1). The projection answers `recipients` as the largest count any one channel addressed, so a person reached on two channels counts once; no body, address or channel breakdown leaves the route.
- [x] 1.3 The typed lifecycle action runner writes one `lifecycle` record per run (done or failed with the reason).
  - `LifecycleActionRecorder` writes it (automation-run-records 2.2); the projection answers its `succeeded` as `done` and a failure's message as `reason`.
- [x] 2.1 `GET /api/automation-outcomes?keyPrefix=&since=&limit=&offset=`, newest first, filtered to subject objects the caller may read.
  - `AutomationRecordsController::outcomes()` over `AutomationRecordService::find()`, so the access rule is the records route's: an admin sees all, anyone else only records of objects they may read, an anonymous caller 401. `AutomationRecordFilter::fromOutcomeParams()` reads `keyPrefix` (and `key` when it is absent) and the kinds `notification` / `lifecycle`.
- [ ] 3.1 PHPUnit per writer and for the read's object-rights filter; a Newman case per scenario.
  - [x] PHPUnit: `tests/Unit/Service/AutomationOutcomeTest.php` (the shape, recipients, done, failed with its reason, the cut reason, no body or breakdown, the outcome filter) and four `outcomes()` cases in `tests/Unit/Controller/AutomationRecordsControllerTest.php`; the writers and the object-rights filter keep their `automation-run-records` tests (`AutomationRecordWriterNotificationRunTest`, `AutomationRecordServiceTest`).
  - [x] Newman written: folder `20 outcomes` in `tests/integration/openregister-automation-records.postman_collection.json` (both kinds by `keyPrefix` in the outcome shape, the `lifecycle` filter, another department's case stays out, anonymous refused). The collection keeps its `@newman exclude` marker until its first live run.
  - [ ] First live Newman pass on a stack with the notifications app and a background worker.
- [ ] 4.1 Tell buildiq (`logic-automation-run-log` T11) the route and shape. (not code: the buildiq lane; the route and shape are in `docs/features/webhooks-and-notifications.md`)
