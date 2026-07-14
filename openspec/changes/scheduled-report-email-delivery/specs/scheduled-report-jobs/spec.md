## MODIFIED Requirements

### Requirement: The system MUST support configuring a recurring scheduled report
Users SHALL be able to create a `ScheduledReport` naming a register, optional schema, opaque filter set (the same shape export endpoints accept), export format (`csv`, `excel`, or `pdf` — validated against `ExportService`'s supported set), a simple schedule (`daily`, `weekly`, or `monthly` plus an hour, no cron expressions), a delivery folder, a delivery mode (`files`, `email`, or `both`; default `files`), an optional list of up to 20 recipient email addresses (each validated as a syntactically correct address at create/update time; when empty, delivery defaults to the owner's own Nextcloud email address, resolved at run time), and an enabled flag. `csv` format SHALL require a specific schema (mirroring `ExportService::exportToCsv()`'s own single-schema requirement). `weekly` SHALL require a day-of-week and `monthly` a day-of-month. Invalid combinations SHALL be rejected at create/update time with HTTP 422, not deferred to the next scheduled run — this now also covers an unsupported `deliveryMode` value, a non-array `recipients` payload, more than 20 recipients, and any syntactically invalid recipient address.

#### Scenario: Create a valid weekly CSV scheduled report
- **GIVEN** a user has read access to register `meldingen-register` and schema `meldingen`
- **WHEN** they call `POST /api/scheduled-reports` with `{name, registerId, schemaId, format: "csv", scheduleType: "weekly", scheduleHour: 8, scheduleDayOfWeek: 0}`
- **THEN** the system creates a `ScheduledReport` owned by the caller with `enabled: true` and `lastRunAt: null`
- **AND** `deliveryMode` defaults to `"files"` and `recipients` defaults to `[]` — identical behaviour to before this change

#### Scenario: CSV format without a schema is rejected
- **WHEN** a user calls `POST /api/scheduled-reports` with `format: "csv"` and no `schemaId`
- **THEN** the system returns HTTP 422 with an error explaining CSV export requires a specific schema

#### Scenario: Unsupported format is rejected
- **WHEN** a user calls `POST /api/scheduled-reports` with `format: "json"` (or any value outside `csv|excel|pdf`)
- **THEN** the system returns HTTP 422 identifying the allowed format set

#### Scenario: Weekly schedule without a day-of-week is rejected
- **WHEN** a user calls `POST /api/scheduled-reports` with `scheduleType: "weekly"` and no `scheduleDayOfWeek`
- **THEN** the system returns HTTP 422

#### Scenario: Create a report with email delivery and explicit recipients
- **GIVEN** a user has read access to register `meldingen-register` and schema `meldingen`
- **WHEN** they call `POST /api/scheduled-reports` with `deliveryMode: "both"` and `recipients: ["a@example.com", "b@example.com"]`
- **THEN** the system creates a `ScheduledReport` with `deliveryMode: "both"` and both recipients stored
- **AND** `GET /api/scheduled-reports/{id}` returns both fields unchanged

#### Scenario: Unsupported delivery mode is rejected
- **WHEN** a user calls `POST /api/scheduled-reports` with `deliveryMode: "sms"` (or any value outside `files|email|both`)
- **THEN** the system returns HTTP 422 identifying the allowed delivery-mode set

#### Scenario: Invalid recipient email address is rejected
- **WHEN** a user calls `POST /api/scheduled-reports` with `recipients: ["not-an-email"]`
- **THEN** the system returns HTTP 422 and no report is created

#### Scenario: Recipients over the cap are rejected
- **WHEN** a user calls `POST /api/scheduled-reports` with 21 recipient addresses
- **THEN** the system returns HTTP 422 identifying the 20-address cap

#### Scenario: Update without sending recipients preserves the existing list
- **GIVEN** a report was created with `recipients: ["a@example.com"]`
- **WHEN** its owner calls `PUT /api/scheduled-reports/{id}` with `{name: "Renamed"}` (no `recipients` key)
- **THEN** the updated report's `recipients` remains `["a@example.com"]`

## ADDED Requirements

### Requirement: Scheduled report execution MUST support email delivery of the export, isolated from Files delivery
When a report's `deliveryMode` includes `email`, `ScheduledReportService::runOne()` SHALL, after generating the export, send it via `OCP\Mail\IMailer` using a Nextcloud email template whose heading is the report name and whose body states the source register/schema, schedule, and exported row count. The export file SHALL be attached when its size is under 10MB. When the export exceeds that cap, the attachment SHALL be omitted and, if the export was not already delivered to Files this pass, it SHALL be delivered to Files as a fallback so the data is never silently lost — the email body SHALL note the omission and reference the Files location instead. A given run SHALL deliver to Files at most once regardless of `deliveryMode`/size combination.

A failure in the email leg SHALL NOT undo, hide, or fail an already-successful Files leg: when `deliveryMode` is `both` and Files delivery succeeds but the email leg fails, `lastStatus` SHALL be set to a distinct value `email_failed` (not `failed`) with the failure reason recorded in `lastError`, and the owner SHALL still be notified that the report was delivered (with the email failure noted). When `deliveryMode` is `email` only and the email leg fails, nothing was delivered and `lastStatus` SHALL be `failed`, matching the existing single-channel all-or-nothing semantics. `ScheduledReportJob`'s per-report batch isolation is unaffected — each report's Files/email outcome is independent of every other report in the same pass.

Recipients resolve to the report's configured `recipients` list when non-empty, otherwise to the owning user's own Nextcloud email address (`IUserManager`/`IUser::getEMailAddress()`). When neither a configured recipient nor an owner email address is available, the email leg SHALL fail with a reason describing the missing recipient, participating in the isolation rules above like any other email-leg failure.

#### Scenario: Both-mode delivery succeeds via Files and email
- **GIVEN** an enabled, due scheduled report with `deliveryMode: "both"` and a small (under 10MB) export
- **WHEN** `ScheduledReportService::runOne()` executes it
- **THEN** the export is written to the owner's configured Files folder
- **AND** an email is sent to the resolved recipients with the export file attached
- **AND** `lastStatus` is set to `success`

#### Scenario: Email-only mode never touches Files
- **GIVEN** an enabled, due scheduled report with `deliveryMode: "email"`
- **WHEN** it runs successfully
- **THEN** no Files write is attempted
- **AND** the owner-resolved or configured recipients receive the export by email
- **AND** `lastStatus` is set to `success`

#### Scenario: Oversize export omits the attachment and falls back to Files with a link
- **GIVEN** a scheduled report with `deliveryMode: "email"` whose rendered export exceeds the 10MB attachment cap
- **WHEN** the report runs
- **THEN** the export is written to the owner's Files as a fallback (even though `deliveryMode` is `email` only)
- **AND** the delivery email is sent without the export attached, its body noting the size and referencing the Files location
- **AND** `lastStatus` is set to `success`

#### Scenario: Both-mode oversize export does not double-write Files
- **GIVEN** a scheduled report with `deliveryMode: "both"` whose rendered export exceeds the 10MB attachment cap
- **WHEN** the report runs
- **THEN** the export is written to Files exactly once (by the Files leg; the email leg's fallback is skipped since it was already delivered)
- **AND** the delivery email is sent without the export attached

#### Scenario: Email leg failure under both mode is isolated from a successful Files leg
- **GIVEN** a scheduled report with `deliveryMode: "both"` and `IMailer::send()` throwing (e.g. SMTP unreachable)
- **WHEN** the report runs
- **THEN** the export is still written to the owner's Files
- **AND** `lastStatus` is set to `email_failed` (not `failed`), with `lastError` describing the email failure
- **AND** the owner is notified that the report was delivered, with a note that email delivery failed

#### Scenario: Email-only leg failure marks the report failed
- **GIVEN** a scheduled report with `deliveryMode: "email"` and `IMailer::send()` throwing
- **WHEN** the report runs
- **THEN** nothing was delivered
- **AND** `lastStatus` is set to `failed` with the email failure reason in `lastError`

#### Scenario: Recipients default to the owner's own email address
- **GIVEN** a scheduled report with `deliveryMode: "email"` and an empty `recipients` list, owned by a user with a Nextcloud email address on file
- **WHEN** the report runs
- **THEN** the delivery email is sent to the owner's own email address

#### Scenario: Owner without an email address and no configured recipients fails the email leg
- **GIVEN** a scheduled report with `deliveryMode: "email"`, an empty `recipients` list, and an owner with no email address on file
- **WHEN** the report runs
- **THEN** the email leg fails with a reason describing the missing recipient, without ever calling `IMailer::send()`
- **AND**, if `deliveryMode` were `both` instead, the Files leg would still succeed and `lastStatus` would be `email_failed`

### Requirement: The delivered-notification wording MUST cover every delivery mode without a new notification subject
`Notifier::prepareScheduledReportDelivered()` SHALL render mode-aware wording for the existing `scheduled_report_delivered` subject: Files-only wording for `deliveryMode: files` (unchanged from `scheduled-report-jobs`), email-only wording (no Files action link) for `deliveryMode: email`, and combined wording for `deliveryMode: both`. When the run's outcome carried an email failure reason (`lastStatus: email_failed`), the parsed message SHALL append a note describing the email failure. No new notification subject SHALL be introduced for any of these cases.

#### Scenario: Delivered notification for both mode mentions Files and email
- **GIVEN** a `scheduled_report_delivered` notification with `mode: "both"`
- **WHEN** `Notifier::prepare()` renders it
- **THEN** the parsed message references both the Files folder/filename and that the report was emailed
- **AND** the notification includes the "Open Files" action

#### Scenario: Delivered notification with a failed email leg includes a note
- **GIVEN** a `scheduled_report_delivered` notification with `mode: "both"` and a non-null `emailFailureReason`
- **WHEN** `Notifier::prepare()` renders it
- **THEN** the parsed message includes a note stating email delivery failed, alongside the successful Files delivery wording
