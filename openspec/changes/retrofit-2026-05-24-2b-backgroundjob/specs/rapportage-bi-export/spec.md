---
retrofit_extensions: [REQ-scheduled-report-render-job]
---

### Requirement: Scheduled-report rendering MUST be driven by dashboard objects and MUST harden delivery against missing-owner and path-traversal abuse

The scheduled-report rendering pipeline MUST be implemented as a daily `TimedJob` (`ReportRenderJob`) that iterates every dashboard object in the operator-imported `reports` register and renders due dashboards via `ReportRenderService`. This requirement complements the existing "scheduled report generation" requirement by specifying the **observed** dispatch and delivery contract, including security guards.

The job MUST run at a 24-hour interval. It MUST honour an operator kill switch via `IAppConfig` key `rapportage_scheduled_renders_enabled` (default `true`). When the kill switch is `false`, the job MUST log `[ReportRenderJob] Scheduled renders disabled (kill switch on), skipping` and return without enumerating dashboards.

When the `reports` register is not present (e.g. the operator has not imported the bundle yet), the job MUST log `[ReportRenderJob] No 'reports' register found — bundle not imported, skipping` and return — it MUST NOT throw.

For each dashboard, due-status MUST be computed against the dashboard payload's `schedule` subobject:

- `schedule` MUST be an array; non-array (e.g. null) MUST be treated as "not due".
- `schedule.active` MUST be a truthy value; otherwise "not due".
- `schedule.intervalSec` MUST be a positive integer; zero or negative MUST be treated as "not due".
- If `payload.lastRenderedAt` exists and parses as a `DateTime`, the dashboard MUST be considered due when `(now - lastRenderedAt) >= intervalSec`. A malformed `lastRenderedAt` MUST be treated as "due now" (not as a hard failure).
- If `payload.lastRenderedAt` is absent or empty, the dashboard MUST be rendered.

Delivery MUST be governed by the dashboard payload's `delivery` subobject:

- `delivery.format` MUST be validated against `ReportRenderService::FORMATS`; an unknown format MUST fall back to `xlsx`.
- `delivery.channel` `files` or `both` MUST trigger Files delivery; the `email` channel is deferred to Phase 2b.

Files delivery MUST enforce the following security guards:

- **Missing-owner refusal**: If `dashboard.getOwner()` is `null` or empty, the job MUST log `[ReportRenderJob] Dashboard owner missing — skipping Files delivery` and return — it MUST NOT fall back to `admin` or any other system identity. Rendered bytes are derived from user-writable JSON and dropping them into another user's home would be a phishing/redirect persistence vector.
- **User-folder fallback**: If the owner's Files root is unavailable (`NotFoundException`), the job MUST log `[ReportRenderJob] User folder unavailable, skipping delivery` and return without throwing.
- **Path-traversal rejection**: The folder path is taken from `delivery.filesFolder` (user-controlled JSON) or computed from `Reports/<slugified-titel>` when empty. The path MUST be normalised by stripping leading slashes. The job MUST reject any normalised path that is empty or contains `..` with `[ReportRenderJob] Rejected delivery folder containing path traversal`.

The slugifier MUST lowercase the input, replace any run of non-alphanumeric characters with `-`, trim leading/trailing dashes, and fall back to literal `dashboard` when the result is empty.

The job MUST resolve the `reports` register with `_rbac: false, _multitenancy: false` (system cron context, no acting user) and enumerate dashboards with the same flags via `MagicMapper::findAll(limit: 200, filters: ['register' => $register->getId()])`. Per-dashboard render failures MUST log `[ReportRenderJob] Render failed for dashboard` with the dashboard identifier and exception message, and MUST NOT halt the rest of the pass. The pass MUST end with `[ReportRenderJob] Scheduled-render pass complete` logging `candidates`, `rendered`, and `skipped` counts.

#### Scenario: Kill switch short-circuits the job

- **GIVEN** `IAppConfig` key `rapportage_scheduled_renders_enabled` is set to `false`
- **WHEN** the Nextcloud cron triggers `ReportRenderJob::run`
- **THEN** the job MUST log the kill-switch message and return
- **AND** MUST NOT call `RegisterMapper::find` for the `reports` register
- **AND** MUST NOT enumerate dashboards

#### Scenario: Missing reports register is non-fatal

- **GIVEN** the `reports` register has not been imported (no register with slug `reports` exists)
- **WHEN** the job runs
- **THEN** the job MUST log `[ReportRenderJob] No 'reports' register found — bundle not imported, skipping`
- **AND** MUST return without throwing

#### Scenario: Dashboard with `lastRenderedAt` older than `intervalSec` is rendered

- **GIVEN** a dashboard with `schedule.active=true`, `schedule.intervalSec=86400`, and `lastRenderedAt` 25 hours ago
- **WHEN** the job iterates the dashboard
- **THEN** `shouldRender` MUST return `true`
- **AND** the dashboard MUST be passed to `renderAndDeliver`

#### Scenario: Dashboard never rendered before is rendered now

- **GIVEN** a dashboard with `schedule.active=true`, `schedule.intervalSec=86400`, and no `lastRenderedAt`
- **WHEN** the job iterates the dashboard
- **THEN** `shouldRender` MUST return `true`

#### Scenario: Dashboard with inactive schedule is skipped

- **GIVEN** a dashboard with `schedule.active=false`
- **WHEN** the job iterates the dashboard
- **THEN** `shouldRender` MUST return `false`
- **AND** the `skipped` counter MUST be incremented

#### Scenario: Dashboard with missing owner is not delivered to admin

- **GIVEN** a dashboard whose `owner` property is `null` (orphaned dashboard)
- **WHEN** `renderAndDeliver` runs and `writeToFiles` is invoked
- **THEN** the job MUST log `[ReportRenderJob] Dashboard owner missing — skipping Files delivery`
- **AND** MUST NOT call `getUserFolder('admin')` or write any bytes anywhere
- **AND** MUST return from `writeToFiles` without raising

#### Scenario: Path-traversal in `delivery.filesFolder` is rejected

- **GIVEN** a dashboard with `delivery.filesFolder` set to `../../../etc/passwd`
- **WHEN** `writeToFiles` runs
- **THEN** the normalised folder MUST contain `..`
- **AND** the job MUST log `[ReportRenderJob] Rejected delivery folder containing path traversal`
- **AND** MUST NOT create the folder or write any file

#### Scenario: Default folder path follows `Reports/<slug>` convention

- **GIVEN** a dashboard with `titel = "Weekly KPI Overview!"` and no `delivery.filesFolder`
- **WHEN** `writeToFiles` runs
- **THEN** the folder path MUST be `Reports/weekly-kpi-overview`
- **AND** the folder MUST be created under the dashboard owner's home if absent

#### Scenario: Existing report file is overwritten in place

- **GIVEN** a dashboard whose delivery folder already contains a file with the rendered filename
- **WHEN** `writeToFiles` runs
- **THEN** the existing node MUST be updated via `putContent`
- **AND** a new file MUST NOT be created alongside the old one

#### Scenario: Per-dashboard render failure does not halt the pass

- **GIVEN** five due dashboards, of which the third raises a `\Throwable` during `renderAndDeliver`
- **WHEN** the job's main loop runs
- **THEN** the failure MUST be logged at warning level with the dashboard identifier
- **AND** the loop MUST continue with the remaining dashboards
- **AND** the final summary MUST report the failed dashboard in the `candidates` count but not the `rendered` count
