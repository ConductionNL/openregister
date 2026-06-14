---
status: proposed
---

# Rapportage / BI Export — report render HTTP surface (delta)

**OpenSpec change**: `retrofit-2026-05-24-b-ctrl-graphql-rt-dash`

**Cross-references**: [rapportage-bi-export main spec](../../../../specs/rapportage-bi-export/spec.md), `lib/Controller/ReportsController.php`, `lib/Service/Reporting/ReportRenderService.php`.

## Purpose of this delta

The `rapportage-bi-export` spec mentions `POST /api/reports/{id}/render?format=…`
in its design notes but has no requirement or scenario for the HTTP contract of
`ReportsController` — the render/preview status-code mapping, the
download-vs-inline behaviour, and the RBAC/multi-tenancy gate on dashboard load.
This delta documents the **observed** controller contract. No behaviour change.

---

## ADDED Requirements

### Requirement: The report render endpoint MUST stream a download with a defined error contract

The report render endpoint MUST render a stored dashboard definition to the
requested format and return it as a browser download, with a defined mapping
from failure modes to HTTP status codes that never leaks internal exception
detail. The endpoint is `ReportsController::render` (`POST /api/reports/{id}/render`,
`@NoAdminRequired`, `@NoCSRFRequired`).

#### Scenario: Successful render returns a download
- **GIVEN** a `POST /api/reports/{id}/render?format=xlsx` for an accessible dashboard
- **WHEN** `ReportsController::render` resolves the dashboard and calls `ReportRenderService::render`
- **THEN** the `format` MUST default to `xlsx` (lower-cased) when omitted, supporting `csv` / `xlsx` / `ods` / `html`
- **AND** the response MUST be a `DataDownloadResponse` carrying the rendered `bytes`, `filename`, and `mime`

#### Scenario: Error contract for render failures
- **GIVEN** a render request
- **WHEN** the dashboard cannot be found (`DoesNotExistException`)
- **THEN** the response MUST be HTTP 404 `{error: 'Dashboard not found', identifier: <id>}`
- **AND** when load fails for any other reason, the response MUST be HTTP 500 `{error: 'Failed to load dashboard'}` with the internal detail logged (never returned in the body)
- **AND** when `ReportRenderService::render` throws `InvalidArgumentException` (caller-controlled validation), the response MUST be HTTP 422 with the validation message
- **AND** when render fails otherwise, the response MUST be HTTP 500 `{error: 'Render failed; see server logs for details'}` with the internal detail logged only

### Requirement: The report preview endpoint MUST render inline and enforce tenant-scoped loads

The report preview endpoint MUST render the dashboard to HTML for inline
preview, and the private `loadDashboard` resolver MUST apply standard RBAC and
multi-tenancy filtering on every dashboard load. The endpoint is
`ReportsController::preview` (`GET /api/reports/{id}/preview`, `@NoAdminRequired`,
`@NoCSRFRequired`).

#### Scenario: Inline HTML preview
- **GIVEN** a `GET /api/reports/{id}/preview` for an accessible dashboard
- **WHEN** `ReportsController::preview` renders the dashboard with `format: 'html'`
- **THEN** the response MUST be a `DataDownloadResponse` with header `Content-Disposition: inline; filename="…"` (preview, not forced download)
- **AND** a dashboard that cannot be found MUST return HTTP 404 `{error: 'Dashboard not found', identifier: <id>}`

#### Scenario: Dashboard load is RBAC- and tenant-filtered
- **GIVEN** any render or preview request
- **WHEN** `loadDashboard` resolves the identifier (numeric id, uuid, or slug) via `MagicMapper::find`
- **THEN** the mapper's standard RBAC and multi-tenancy filters MUST apply on every load
- **AND** the loader MUST NOT bypass those filters (preventing cross-tenant render exfiltration by guessing identifiers)
