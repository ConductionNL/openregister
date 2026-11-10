## ADDED Requirements

### Requirement: The system MUST support configuring a recurring scheduled report
Users SHALL be able to create a `ScheduledReport` naming a register, optional schema, opaque filter set (the same shape export endpoints accept), export format (`csv`, `excel`, or `pdf` — validated against `ExportService`'s supported set), a simple schedule (`daily`, `weekly`, or `monthly` plus an hour, no cron expressions), a delivery folder, and an enabled flag. `csv` format SHALL require a specific schema (mirroring `ExportService::exportToCsv()`'s own single-schema requirement). `weekly` SHALL require a day-of-week and `monthly` a day-of-month. Invalid combinations SHALL be rejected at create/update time with HTTP 422, not deferred to the next scheduled run.

#### Scenario: Create a valid weekly CSV scheduled report
- **GIVEN** a user has read access to register `meldingen-register` and schema `meldingen`
- **WHEN** they call `POST /api/scheduled-reports` with `{name, registerId, schemaId, format: "csv", scheduleType: "weekly", scheduleHour: 8, scheduleDayOfWeek: 0}`
- **THEN** the system creates a `ScheduledReport` owned by the caller with `enabled: true` and `lastRunAt: null`

#### Scenario: CSV format without a schema is rejected
- **WHEN** a user calls `POST /api/scheduled-reports` with `format: "csv"` and no `schemaId`
- **THEN** the system returns HTTP 422 with an error explaining CSV export requires a specific schema

#### Scenario: Unsupported format is rejected
- **WHEN** a user calls `POST /api/scheduled-reports` with `format: "json"` (or any value outside `csv|excel|pdf`)
- **THEN** the system returns HTTP 422 identifying the allowed format set

#### Scenario: Weekly schedule without a day-of-week is rejected
- **WHEN** a user calls `POST /api/scheduled-reports` with `scheduleType: "weekly"` and no `scheduleDayOfWeek`
- **THEN** the system returns HTTP 422

### Requirement: The system MUST run due scheduled reports on an hourly catch-up-safe cadence
`ScheduledReportJob` (an hourly `TimedJob`) SHALL, on each run, load every enabled `ScheduledReport` and execute those that are due. A report is due when it has never run (`lastRunAt` is null) or when the elapsed time since `lastRunAt` is at least as long as its schedule period (`daily` = 24h, `weekly` = 7 days, `monthly` = 30 days). This SHALL be catch-up-safe: if the job did not run for an extended period, every report whose period has elapsed SHALL run on the next tick rather than waiting for its next nominal occurrence. Disabled reports SHALL never run.

#### Scenario: Daily report is due after 24 hours
- **GIVEN** a `daily` scheduled report last ran 25 hours ago
- **WHEN** `ScheduledReportJob` runs
- **THEN** the report is executed and `lastRunAt` is updated to the current time

#### Scenario: Daily report is not due before 24 hours
- **GIVEN** a `daily` scheduled report last ran 2 hours ago
- **WHEN** `ScheduledReportJob` runs
- **THEN** the report is skipped and `lastRunAt` is unchanged

#### Scenario: Weekly and monthly reports use their own periods
- **GIVEN** a `weekly` report last ran 8 days ago and a `monthly` report last ran 20 days ago
- **WHEN** `ScheduledReportJob` runs
- **THEN** the weekly report executes (period elapsed) and the monthly report is skipped (period not yet elapsed)

#### Scenario: Never-run report is always due
- **GIVEN** a scheduled report with `lastRunAt: null`
- **WHEN** `ScheduledReportJob` runs
- **THEN** the report executes regardless of its schedule type or hour

#### Scenario: Disabled report never runs
- **GIVEN** a `daily` scheduled report with `enabled: false` and `lastRunAt` 30 days in the past
- **WHEN** `ScheduledReportJob` runs
- **THEN** the report is not executed

#### Scenario: Catch-up after downtime runs all elapsed reports
- **GIVEN** three enabled reports (daily, weekly, monthly), all with `lastRunAt` more than their respective periods in the past because the background job did not run for several days
- **WHEN** `ScheduledReportJob` finally runs
- **THEN** all three reports execute in the same pass

### Requirement: Scheduled report execution enforces the owner's RBAC and multi-tenancy scope
`ScheduledReportService::runOne()` SHALL execute the export as the report's owning user — not as an elevated system principal — so `ExportService` applies exactly the RBAC and multi-tenancy filtering that owner would see interactively. This SHALL use session impersonation (`IUserSession::setUser()`) scoped to the single run and restored afterward, never a privilege-escalating system-context bypass.

#### Scenario: Scheduled report only exports data its owner can see
- **GIVEN** a scheduled report owned by a user who belongs to organisation `gemeente-utrecht`
- **AND** the target register/schema also contains objects belonging to organisation `gemeente-amsterdam`
- **WHEN** the report runs
- **THEN** the exported file contains only `gemeente-utrecht` objects
- **AND** the previously-active session user (if any) is restored after the run completes

### Requirement: Scheduled report execution delivers to the owner's Files and notifies them
On successful execution, the generated export SHALL be written into the report owner's Nextcloud Files under the configured delivery folder (default `Reports/`), with a filename including the current date, and the owner SHALL receive a Nextcloud notification linking to the delivery location. Folder paths SHALL be sanitized against path traversal (leading slashes stripped, `..` segments rejected) the same way `ReportRenderJob::writeToFiles()` already does.

#### Scenario: Successful run delivers file and notifies
- **GIVEN** an enabled, due scheduled report for schema `meldingen`, format `csv`, delivery folder `Reports/`
- **WHEN** `ScheduledReportJob` executes it
- **THEN** a file named `meldingen_<current-date>.csv` is created under the owner's `Reports/` folder in Nextcloud Files
- **AND** the owner receives a Nextcloud notification referencing the report and the delivery location
- **AND** the report's `lastRunAt` is updated and `lastStatus` is set to `success`

#### Scenario: Delivery folder path traversal is rejected
- **GIVEN** a scheduled report whose stored `deliveryFolder` contains `../../etc`
- **WHEN** the report runs
- **THEN** the system rejects the delivery folder, does not write any file outside the owner's home, logs the rejection, and marks the run as failed

### Requirement: Row-cap failures and unexpected errors mark the report failed without retry-looping, isolated per report
When `ExportService` throws `ExportTooLargeException` (or any other exception) during a scheduled run, `ScheduledReportService::runOne()` SHALL catch it, set `lastStatus = failed` with a human-readable `lastError`, and send the owner a failure notification — without re-throwing and without immediately re-attempting the run. `ScheduledReportJob` SHALL isolate each report's execution so that one report's failure does not prevent any other enabled, due report in the same pass from running.

#### Scenario: Export too large marks the report failed, not retried
- **GIVEN** a scheduled `pdf` report whose matching object count exceeds `ExportService::MAX_PDF_EXPORT_ROWS`
- **WHEN** the report runs
- **THEN** `ExportTooLargeException` is caught, `lastStatus` is set to `failed` with a `lastError` mentioning the row count and limit
- **AND** the owner receives a failure notification
- **AND** the job does not immediately re-attempt the same report in the same pass

#### Scenario: One report's failure does not block the others
- **GIVEN** two enabled, due scheduled reports, A and B, where executing A raises an unexpected exception
- **WHEN** `ScheduledReportJob` runs
- **THEN** A is marked `lastStatus: failed`
- **AND** B still executes and, if successful, is marked `lastStatus: success`

### Requirement: The system MUST provide owner-scoped REST CRUD with admin visibility
`ScheduledReportsController` SHALL expose `GET /api/scheduled-reports` (own reports; an admin caller additionally sees all reports via `?all=true`), `GET /api/scheduled-reports/{id}`, `POST /api/scheduled-reports`, `PUT /api/scheduled-reports/{id}`, and `DELETE /api/scheduled-reports/{id}`. Every read/write on an existing report SHALL be rejected with 403 (or 404, to avoid confirming existence) unless the caller owns the report or is a Nextcloud administrator.

#### Scenario: Owner can manage their own report
- **WHEN** a user calls `GET`, `PUT`, or `DELETE` on a scheduled report they own
- **THEN** the request succeeds

#### Scenario: Non-owner cannot access another user's report
- **GIVEN** scheduled report `42` is owned by user `alice`
- **WHEN** user `bob` (not an admin) calls `GET /api/scheduled-reports/42`, `PUT /api/scheduled-reports/42`, or `DELETE /api/scheduled-reports/42`
- **THEN** the system returns 403 or 404, and the report is unchanged

#### Scenario: Admin can list all reports
- **GIVEN** reports exist owned by several different users
- **WHEN** an administrator calls `GET /api/scheduled-reports?all=true`
- **THEN** the response includes reports owned by every user
- **AND** a non-admin caller passing `?all=true` still only sees their own reports

### Requirement: The system MUST support immediate execution via a queued run-now action
`POST /api/scheduled-reports/{id}/run-now` SHALL trigger an immediate execution of the identified scheduled report, subject to the same ownership/admin gate as the other write endpoints. The export SHALL run asynchronously via a queued background job (`ScheduledReportRunNowJob`, dispatched through `IJobList::add()`) — the HTTP request SHALL NOT block on `ExportService` execution and SHALL return before the export completes.

#### Scenario: Run-now queues the job and returns immediately
- **WHEN** the report's owner calls `POST /api/scheduled-reports/{id}/run-now`
- **THEN** the system queues `ScheduledReportRunNowJob` with the report id via `IJobList::add()`
- **AND** returns HTTP 202 without waiting for the export to complete
- **AND** the export executes via the same `ScheduledReportService::runOne()` logic the hourly job uses, without re-checking due-ness

#### Scenario: Run-now respects ownership
- **GIVEN** scheduled report `42` is owned by user `alice`
- **WHEN** user `bob` (not an admin) calls `POST /api/scheduled-reports/42/run-now`
- **THEN** the system returns 403 or 404 and no job is queued
