---
status: proposed
---

# Built-in Dashboards — dashboard data-feed HTTP surface (delta)

**OpenSpec change**: `retrofit-2026-05-24-b-ctrl-graphql-rt-dash`

**Cross-references**: [built-in-dashboards stub](../../../../specs/built-in-dashboards/spec.md), [rapportage-bi-export spec](../../../../specs/rapportage-bi-export/spec.md), `lib/Controller/DashboardController.php`.

## Purpose of this delta

The local `built-in-dashboards` spec is a redirect stub. The HTTP endpoints that
actually power the mounted OpenRegister dashboard — served by
`DashboardController` against `DashboardService` — are documented nowhere. This
delta captures the **observed** data-feed contract: the dashboard SPA page, the
registers-with-schemas feed, the on-demand size-calculation trigger, and the
audit/object chart & statistics read endpoints. These complement (and are
distinct from) the user-configurable chart endpoints in `rapportage-bi-export`
(`GET /api/objects/{register}/{schema}/chart`), which are operator-defined
report queries rather than the fixed dashboard widget feeds. No behaviour change.

---

## ADDED Requirements

### Requirement: The dashboard MUST serve its SPA page with API-permissive CSP

`DashboardController::page` (`@NoAdminRequired`, `@NoCSRFRequired`) MUST render
the dashboard single-page app and configure a Content Security Policy that
permits the frontend to make API calls.

#### Scenario: Render the dashboard page
- **GIVEN** a request for the dashboard page
- **WHEN** `DashboardController::page` runs
- **THEN** it MUST return the `index` `TemplateResponse` with a CSP whose connect domain allows API calls (`addAllowedConnectDomain('*')`)
- **AND** if template rendering throws, it MUST return the `error` template with HTTP status 500 and the error message in params

### Requirement: The dashboard MUST expose a registers-with-schemas data feed

`DashboardController::index` (`@NoAdminRequired`, `@NoCSRFRequired`) MUST return
every register together with its schemas and per-schema statistics, optionally
filtered by register or schema.

#### Scenario: List registers with schemas and stats
- **GIVEN** a request to the dashboard index feed
- **WHEN** `DashboardController::index` calls `DashboardService::getRegistersWithSchemas` (passing optional `registerId` / `schemaId`)
- **THEN** the response MUST be `{registers: [...]}` where each register carries its `schemas[]` and a `stats` block (objects / logs / files / webhookLogs totals and sizes)
- **AND** pagination and routing params (`id`, `_route`, `limit`, `offset`, `page`) MUST be stripped before the service call
- **AND** on failure the response MUST be `{error: <message>}` with HTTP status 500

### Requirement: The dashboard MUST expose an on-demand size-calculation trigger

`DashboardController::calculate` (`@NoAdminRequired`, `@NoCSRFRequired`) MUST
recompute object and log storage sizes, optionally scoped to a register or
schema.

#### Scenario: Trigger size calculation
- **GIVEN** a request with optional `registerId` / `schemaId`
- **WHEN** `DashboardController::calculate` calls `DashboardService::calculate`
- **THEN** the response MUST contain the calculation result (status, scope, results, summary) with HTTP status 200
- **AND** on failure the response MUST be `{status: 'error', message: <message>, timestamp: <ISO-8601>}` with HTTP status 500

### Requirement: The dashboard MUST expose audit and object chart & statistics feeds

`DashboardController` MUST expose the fixed widget data feeds that drive the
dashboard charts and sidebar statistics. Each feed (`@NoAdminRequired`,
`@NoCSRFRequired`) accepts optional `registerId` / `schemaId` filters and maps
any exception to `{error: <message>}` with HTTP status 500.

#### Scenario: Audit-trail action chart over a date range
- **GIVEN** a request with optional `from` / `till` (`Y-m-d`) and scope filters
- **WHEN** `getAuditTrailActionChart` calls `DashboardService::getAuditTrailActionChartData`
- **THEN** the response MUST be a chart shape `{labels: [...], series: [{name, data: [...]}]}`

#### Scenario: Object distribution charts
- **GIVEN** a request with optional scope filters
- **WHEN** `getObjectsByRegisterChart`, `getObjectsBySchemaChart`, or `getObjectsBySizeChart` is called
- **THEN** each MUST return `{labels: [...], series: [...]}`
- **AND** `getObjectsBySizeChart` labels MUST be the fixed size buckets (`0-1 KB`, `1-10 KB`, `10-100 KB`, `100 KB-1 MB`, `> 1 MB`)

#### Scenario: Audit statistics and most-active objects over a look-back window
- **GIVEN** a request with optional scope filters and a `hours` look-back (default 24)
- **WHEN** `getAuditTrailStatistics`, `getAuditTrailActionDistribution`, or `getMostActiveObjects` is called
- **THEN** `getAuditTrailStatistics` MUST return action totals `{total, creates, updates, deletes, reads}`
- **AND** `getAuditTrailActionDistribution` MUST return `{actions: [{name, count}]}`
- **AND** `getMostActiveObjects` MUST return `{objects: [{id, name, count}]}` limited by the optional `limit` (default 10), logging the error context on failure before returning HTTP 500
