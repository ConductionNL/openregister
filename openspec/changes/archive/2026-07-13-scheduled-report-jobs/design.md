# Design Notes — Scheduled Report Jobs

## Reconciliation with `rapportage-bi-export` and `ReportRenderJob`

Investigated at HEAD before designing (both merged today): `openspec/changes/rapportage-bi-export/` is `status: proposed`, unimplemented for the scheduling requirement, and its spec (`specs/rapportage-bi-export/spec.md`) literally names the class this change ships:

> "a `ScheduledReportJob` (extending `TimedJob`) MUST generate the ... report ... store the file ... send a Nextcloud notification to the report owner via `INotifier`" (Scenario: Schedule a weekly status report)
>
> "`ExportService::exportToCsv()` MUST generate the CSV ... filename MUST include the date ... previous exports MUST be retained ..." (Scenario: Schedule a daily CSV export for data warehouse)

Its "Current Implementation Status" section explicitly lists **"Not implemented -- Scheduled report generation: No `ScheduledReportJob` or cron-based report generation. No report delivery via Nextcloud Files or notifications."**

`lib/BackgroundJob/ReportRenderJob.php` (already merged, part of a different, narrower slice of `rapportage-bi-export`) is **not** that job. It:
- operates only on the operator-imported `reports` register's dashboard objects (a BI/branding/aggregation concept — `ReportRenderService::render()`, formats `csv|xlsx|ods|html|pdf`),
- runs on a fixed daily interval (not user-configurable daily/weekly/monthly),
- delivers Files-only — its own docblock states email is deferred and it has **no notification delivery of any kind yet**, not even Files-delivery success/failure notifications,
- requires the BI reporting bundle to be imported at all (returns immediately if the `reports` register doesn't exist).

This change is the generic counterpart the reserved `ScheduledReportJob` name was reserved for: schedule an `ExportService` export of **any** register/schema + filter combination (the same shape every export endpoint already accepts), with Files delivery **and** an owner notification, available without the BI bundle. It satisfies `rapportage-bi-export`'s "daily CSV export" and "weekly status report" scenarios' mechanics (schedule → `ExportService::exportTo*()` → dated filename → Files → notify) using the reserved class/entity names. It deliberately does **not** implement: report *templates* with branding/sections (that's `ReportRenderService`'s domain), cron expressions (out of scope — see proposal.md), retention-period cleanup of old exported files, or n8n-workflow email delivery — those remain open under `rapportage-bi-export` and are noted as future extensions below. No files under `rapportage-bi-export/` or `ReportRenderJob.php` are modified by this change; the two mechanisms coexist and don't collide (different table, different job, different register requirement).

## Storage: infrastructure DB row, not an OR object

Followed the `PushSubscription`/`PushSubscriptionMapper` pattern (`lib/Db/PushSubscription.php`), not the OR-objects/register pattern `ReportRenderJob` reads from. Rationale (ADR-001): a scheduled-report config is infrastructure state owned by exactly one user, carries no cross-app business meaning, no RBAC beyond "owner or admin", and no audit/relation value — the same reasoning that keeps `Webhook`/`PushSubscription` out of the object store. Modelling it as an OR object would additionally force every consuming app to have a register/schema provisioned just to hold scheduling config, defeating the "available to every app at once" goal (a controller app can schedule an export of *its own* register without OpenRegister's BI bundle involved at all).

New table `openregister_scheduled_reports` (migration `Version1Date20260713000000.php`, idempotent `hasTable()` guard, same shape as `Version1Date20260615130000.php`):

| column | type | notes |
|---|---|---|
| `id` | bigint PK autoincrement | |
| `owner` | string(64) not null | owning uid; index |
| `name` | string(255) not null | user-facing label |
| `register_id` | bigint not null | index |
| `schema_id` | bigint, nullable | required for `csv` (single-schema format, mirrors `ExportService::exportToCsv()`'s own guard) |
| `filters` | text, nullable | opaque JSON, `@self.*`-prefixed filter map, same shape `ExportService::fetchObjectsForExport()` accepts |
| `format` | string(16) not null | `csv|excel|pdf` |
| `schedule_type` | string(16) not null | `daily|weekly|monthly` |
| `schedule_hour` | smallint not null | 0-23, target hour (informational — see due-logic below) |
| `schedule_day_of_week` | smallint, nullable | 0(Mon)-6(Sun), required when `weekly` |
| `schedule_day_of_month` | smallint, nullable | 1-28, required when `monthly` (capped at 28 to avoid month-length edge cases) |
| `delivery_folder` | string(512) not null default `Reports/` | sanitized the same way as `ReportRenderJob::writeToFiles()` |
| `enabled` | boolean not null default true | index (job loads enabled rows only) |
| `last_run_at` | datetime, nullable | |
| `last_status` | string(16), nullable | `success|failed` |
| `last_error` | text, nullable | reason when `last_status = failed` |
| `created_at` / `updated_at` | datetime not null | |

Indexes: `(owner)` for `findByOwner()`, `(enabled)` for the job's `findEnabled()` scan.

## Due-logic: elapsed-period since `lastRunAt`, catch-up-safe

The task scope explicitly specifies the firing rule: *"run if lastRunAt older than the period"*. `ScheduledReportJob` runs **hourly** and, for every enabled report, computes:

```
periodSeconds = match(scheduleType) { daily => 86400, weekly => 604800, monthly => 2592000 (30d) }
due = enabled && (lastRunAt === null || (now - lastRunAt) >= periodSeconds)
```

This is deliberately **not** precise cron-style hour/day gating. `scheduleHour`/`scheduleDayOfWeek`/`scheduleDayOfMonth` are captured, validated, and returned via the REST API (so a future iteration can add windowed gating, e.g. "only fire between hour H and H+1"), but the v1 firing condition is purely elapsed-period-based. This is a deliberate simplification for three reasons: (1) it is exactly what the task scope specifies, (2) it is trivially catch-up-safe — if the hourly job itself was down for two days, the next tick fires every report whose period elapsed, rather than requiring them to wait for their exact configured hour to come back around, and (3) it is simple to unit-test as a due-logic matrix (daily/weekly/monthly × catch-up × disabled) without a fake clock walking hour-by-hour through a month. `monthly`'s 30-day approximation is documented here rather than computing real calendar-month boundaries (`DateInterval('P1M')`) to keep the due-check a pure, easily-tested duration comparison; a report scheduled monthly will, over a year, fire roughly 12 times with drift of at most a few days — acceptable for the "management report" use case this targets.

## Owner impersonation for RBAC-correct export

`ExportService::fetchObjectsForExport()` calls `ObjectService::searchObjects(..., _rbac: true, _multitenancy: true, ...)`, which resolves the acting user from the current Nextcloud session, not from an explicit parameter. A background job has no session by default. Followed the established `HandoffService::drainEntry()` pattern (`lib/Service/Handoff/HandoffService.php`) rather than `SystemOperationContext`/`runAsSystem()` — that trusted-system-principal mechanism is for the *opposite* case (bypassing RBAC entirely), which would leak cross-tenant/cross-permission data into a scheduled export. Instead `ScheduledReportService::runOne()`:

```php
$owner = $this->userManager->get($report->getOwner());
if ($owner === null) { /* owner account deleted — mark failed, skip (no notification target), continue */ }

$previousUser = $this->userSession->getUser();
$this->userSession->setUser($owner);
try {
    $bytes = match ($report->getFormat()) {
        'csv'   => $this->exportService->exportToCsv(...),
        'excel' => $this->exportService->exportToExcel(...) (then Xlsx-written),
        'pdf'   => $this->exportService->exportToPdf(...),
    };
    // ... write to Files, notify, mark success
} catch (ExportTooLargeException $e) {
    // mark failed with reason, notify owner, no retry
} catch (\Throwable $e) {
    // mark failed with reason, notify owner, no retry — per-report isolation
} finally {
    $this->userSession->setUser($previousUser);
}
```

This guarantees the export sees exactly the owner's RBAC- and multi-tenancy-scoped object set — satisfying `rapportage-bi-export`'s "Scheduled report respects tenant context" scenario mechanics — and never escalates privilege. Files delivery itself (`IRootFolder::getUserFolder($ownerUid)`) does not need the session swap (confirmed against `ReportRenderJob::writeToFiles()`, which addresses the owner's folder directly with no session involved), so it happens either inside or outside the `setUser()` block without effect; kept inside for a single easy-to-audit impersonation scope per run.

## Per-report isolation

Both layers guard against one bad report aborting the batch: `ScheduledReportService::runOne()` catches `\Throwable` internally (never propagates), and `ScheduledReportJob::run()` additionally wraps each `runOne()` call in its own try/catch and logs, matching `ReportRenderJob::run()`'s per-dashboard try/catch loop. `lastStatus`/`lastError` are always written on failure so the controller/UI can surface it without relying on log-diving.

## `run-now`: queued, not inline

`ScheduledReportsController::runNow()` calls `IJobList::add(ScheduledReportRunNowJob::class, ['scheduledReportId' => $id])` and returns `202 Accepted` immediately — mirrors `WebhookService::dispatchEvent()`'s move from synchronous to `IJobList`-queued delivery (async-webhook-delivery) for exactly the same reason: `ExportService` (especially PDF rendering, capped at 5000 rows) can take real wall-clock time, and a REST endpoint must not block on it. `ScheduledReportRunNowJob` is a `QueuedJob` (fire-once, not listed in `appinfo/info.xml`, registered ad hoc via `IJobList::add()` — same convention as `WebhookDeliveryJob`) whose `run()` loads the report by id and calls the *same* `ScheduledReportService::runOne()` the hourly `ScheduledReportJob` uses (no logic duplication), skipping the due-check since an explicit run-now is, by definition, due.

## Format validation against `ExportService`'s supported set

`ScheduledReportService` validates `format` against a local `ALLOWED_FORMATS = ['csv', 'excel', 'pdf']` constant — deliberately excluding `json` (which `ExportService::exportToJson()` supports) because a scheduled recurring JSON dump of raw objects isn't a "report" in the sense this feature targets (no management/print use case), and excluding it keeps the REST contract's format enum tight and matches the task's explicit scope (`csv|excel|pdf`). `csv` additionally requires `schemaId` to be set at create/update time (`ExportService::exportToCsv()` itself throws `InvalidArgumentException` for a schema-less multi-schema export) — validated server-side at create/update, not deferred to job-run time, so a misconfigured report is rejected immediately with 422 rather than silently failing on its first scheduled run.

## Notifications

Extends `lib/Notification/Notifier.php` (not `AnnotationNotifier` — this is an app-level lifecycle event like `configuration_update_available`, not an object-lifecycle annotation event) with two subjects: `scheduled_report_delivered` (success — action link to the delivery folder) and `scheduled_report_failed` (failure — includes the `lastError` reason). Both follow the exact `IManager::createNotification()` → `setApp/setUser/setObject/setSubject` → `notify()` pattern already used by `NotificationService::sendUpdateNotification()`.

## Future extensions (explicitly out of scope here)

- **Email delivery** — `rapportage-bi-export`'s n8n-workflow email scenario remains the planned mechanism; this change's `deliveryFolder`/notification-link approach covers the Files + in-app-notification half of that requirement.
- **Retention/cleanup of old exported files** — `rapportage-bi-export`'s "Report retention management" scenario (default 90 days) is not implemented here; each scheduled run's file currently overwrites the previous run's file for the same report (same filename would collide only if two runs land on the same day — dated filenames make same-day re-runs the only overwrite case, matching `ReportRenderJob`'s existing overwrite-on-same-filename behaviour).
- **Cron-expression / precise-hour scheduling** — see due-logic section above.
