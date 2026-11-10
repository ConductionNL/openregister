# Tasks — Scheduled Report Jobs

## 1. Storage: entity + mapper + migration
- [x] 1.1 `lib/Db/ScheduledReport.php` — `Entity`/`JsonSerializable`, fields per design.md table, `addType()` per column, `jsonSerialize()`.
- [x] 1.2 `lib/Db/ScheduledReportMapper.php` — `QBMapper<ScheduledReport>` over `openregister_scheduled_reports`: `findByOwner(string $owner): array`, `findAll(): array`, `find(int $id): ScheduledReport`, `findEnabled(): array`.
- [x] 1.3 `lib/Migration/Version1Date20260713000000.php` — idempotent `changeSchema()` creating `openregister_scheduled_reports` with the columns/indexes in design.md.

## 2. Service: validation, CRUD, due-logic, run
- [x] 2.1 `lib/Service/ScheduledReportService.php` — `ALLOWED_FORMATS = ['csv','excel','pdf']`, `ALLOWED_SCHEDULE_TYPES = ['daily','weekly','monthly']`.
- [x] 2.2 `create(array $data, string $ownerUid): ScheduledReport` — validates format/schedule fields, requires `schemaId` when `format === 'csv'`, resolves+validates register/schema exist and are accessible to the owner, sets `owner`, defaults `deliveryFolder = 'Reports/'`, `enabled = true`.
- [x] 2.3 `update(int $id, array $data, string $callerUid, bool $callerIsAdmin): ScheduledReport` — 404 if missing, 403 if `$callerUid` doesn't own the row and isn't admin; re-runs the same validation as create for changed fields.
- [x] 2.4 `delete(int $id, string $callerUid, bool $callerIsAdmin): void` — same ownership gate.
- [x] 2.5 `findForOwner(string $ownerUid): array` / `findAllForAdmin(): array`.
- [x] 2.6 `isDue(ScheduledReport $report, \DateTimeInterface $now): bool` — elapsed-period-since-`lastRunAt` per design.md (`daily=86400s, weekly=604800s, monthly=2592000s`), `false` when disabled.
- [x] 2.7 `runOne(ScheduledReport $report): void` — owner impersonation (`IUserSession::setUser()` + try/finally restore), dispatch to `ExportService::exportToCsv|exportToExcel|exportToPdf()` by format, Files delivery (folder sanitization + create-if-absent + overwrite-on-same-filename, mirroring `ReportRenderJob::writeToFiles()`), success/failure notification, `lastRunAt`/`lastStatus`/`lastError` persisted via the mapper. Catches `ExportTooLargeException` and `\Throwable` generically — never throws out, never retry-loops.
- [x] 2.8 Excel branch writes the returned `Spreadsheet` to bytes via `PhpOffice\PhpSpreadsheet\Writer\Xlsx` (same writer `ExportHandler`/controllers already use for `.xlsx` downloads).

## 3. Background jobs
- [x] 3.1 `lib/BackgroundJob/ScheduledReportJob.php` — hourly `TimedJob` (`setInterval(3600)`), loads `findEnabled()`, per-report `isDue()` check + `runOne()` inside its own try/catch (per-report isolation, mirrors `ReportRenderJob::run()`'s loop), summary log line (candidates/run/skipped).
- [x] 3.2 `lib/BackgroundJob/ScheduledReportRunNowJob.php` — fire-once `QueuedJob`, argument `['scheduledReportId' => int]`, loads the report and calls `ScheduledReportService::runOne()` directly (no due-check).
- [x] 3.3 Register `ScheduledReportJob` in `appinfo/info.xml`'s `<background-jobs>` block. `ScheduledReportRunNowJob` deliberately **not** listed (queued ad hoc via `IJobList::add()`, same convention as `WebhookDeliveryJob`).

## 4. Notifications
- [x] 4.1 `lib/Notification/Notifier.php` — add `scheduled_report_delivered` (success, action link to the delivery folder) and `scheduled_report_failed` (failure, includes reason) subject cases + `prepare*()` methods, following the `configuration_update_available` pattern.

## 5. REST controller + routes
- [x] 5.1 `lib/Controller/ScheduledReportsController.php` — `index()` (own rows; admin gets all via `?all=true`), `show(int $id)`, `create()`, `update(int $id)`, `destroy(int $id)`, `runNow(int $id)` (queues `ScheduledReportRunNowJob`, returns 202). Ownership enforced server-side (owner uid from `IUserSession`, admin bypass via `IGroupManager::isAdmin()`), `#[NoAdminRequired]`/`#[NoCSRFRequired]` + docblock tags per established dual-annotation convention.
- [x] 5.2 `appinfo/routes.php` — `scheduledReports#index|show|create|update|destroy|runNow` routes under `/api/scheduled-reports[/{id}][/run-now]`, `requirements: ['id' => '\d+']`, comment banner referencing this change.

## 6. Tests
- [x] 6.1 `tests/Unit/Service/ScheduledReportServiceDueTest.php` — due-logic matrix: daily/weekly/monthly under/at/over period, `lastRunAt = null` (never run), disabled report never due regardless of elapsed time.
- [x] 6.2 `tests/Unit/Service/ScheduledReportServiceValidationTest.php` — format allow-list rejection, `csv` without `schemaId` rejected, schedule-type allow-list rejection, `weekly` without `dayOfWeek` / `monthly` without `dayOfMonth` rejected.
- [x] 6.3 `tests/Unit/Service/ScheduledReportServiceRunOneTest.php` — mocks `ExportService`/Files (`IRootFolder`)/`IManager` (notifications)/`IUserSession`: success path sets `lastStatus=success`; `ExportTooLargeException` sets `lastStatus=failed` with reason and sends the failure notification without throwing; a second, unrelated report in the same batch still runs after the first fails (per-report isolation, exercised at the job level in 6.5).
- [x] 6.4 `tests/Unit/Controller/ScheduledReportsControllerTest.php` — own-row CRUD succeeds; another user's row on `show`/`update`/`destroy` returns 403/404; admin can list all via `?all=true`; `runNow()` queues via `IJobList::add()` (mocked) and returns 202, never calls `ExportService` inline.
- [x] 6.5 `tests/Unit/BackgroundJob/ScheduledReportJobTest.php` — with two enabled reports where the first's `runOne()` (via a mocked service) throws, asserts the second still runs (per-report isolation at the job loop level) and the summary log reflects both outcomes.
- [x] 6.6 Full existing PHPUnit suite stays green — verify any observed failures are pre-existing/unrelated by checking the files involved.

## 7. Quality gates
- [x] 7.1 SPDX docblocks on every new/changed PHP file.
- [x] 7.2 PHPCS clean on changed files.
- [x] 7.3 PHPMD clean on changed files (or `@SuppressWarnings` in the established pattern).
- [x] 7.4 PHPStan clean on changed files modulo baseline update (only if needed, following the established baseline-entry pattern).
- [x] 7.5 Psalm: 0 errors on changed files.
- [x] 7.6 No new composer dependencies (`composer.lock` unchanged).
