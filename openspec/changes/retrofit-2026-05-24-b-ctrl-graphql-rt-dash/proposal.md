# Retrofit: reverse-spec controller bundle — graphql / realtime / dashboard / reports / revert

**Type**: retrofit (reverse-spec, documentation-only — no behaviour change)

**Date**: 2026-05-24

**Bundle**: `ctrl-graphql-rt-dash` (5 controller sub-clusters)

## Why

Five HTTP controllers in `lib/Controller/` expose API surface that was either
undocumented in its capability spec or documented only as an implementation
note. This change is a reverse-spec pass: it captures the **observed**
controller contracts as `@spec` annotations and extends the five target
capabilities with the few endpoint-contract requirements that were genuinely
uncovered. No production code behaviour changes — only docblock `@spec`
annotations are added.

Bias is toward **extend** (the capabilities already exist) and toward
**annotation** where the contract is already specified. New requirements are
added only where the controller's HTTP contract (status codes, response shape,
auth gate, multi-tenancy scoping) was not described anywhere.

## What changes

Per sub-cluster (all `--extend`):

- **graphql-api-surface** → `graphql-api`: `GraphQLController::execute` / `explorer`
  / `getGraphiQLHtml`. **Annotation only** — the GraphiQL explorer, the
  `POST /api/graphql` execute contract, the 429 + `Retry-After` rate-limit path,
  and the error-response shape are already fully specified (see existing
  requirements "The GraphQL endpoint MUST include an interactive GraphiQL
  explorer" and the HTTP status-code scenarios). Re-point the existing
  `task-46` / `task-47` annotations at this bundle. **0 new REQs.**

- **realtime-sse-api** → `realtime-updates`: `RealtimeController::events` / `cursor`.
  The spec covers SSE plus client-side polling fallback against the generic
  REST list API, but the **dedicated cursor-polling HTTP endpoints**
  (`GET /api/realtime/events?since=…` and `GET /api/realtime/cursor`) and their
  contract — 401 anonymous short-circuit, active-organisation scoping (fail-closed
  to empty / `cursor: 0`), `{events, cursor, hasMore}` envelope, and `limit`
  clamping to `1..1000` — were undocumented. **2 new REQs.**

- **dashboard-charts-api** → `built-in-dashboards`: `DashboardController` page /
  index / calculate + the six audit/object chart & statistics feeds. The
  `built-in-dashboards` local spec is a redirect stub with only one redirect
  requirement, and the dashboard's own data-feed HTTP endpoints are documented
  nowhere. Capture the observed data-feed surface (template page + CSP, the
  registers-with-schemas feed, the size-calculation trigger, and the chart /
  statistics read endpoints). **4 new REQs.**

- **report-render-api** → `rapportage-bi-export`: `ReportsController::render` /
  `preview` / `loadDashboard`. `POST /api/reports/{id}/render` appears in the
  spec's design notes but has no requirement/scenario for the controller's HTTP
  contract: default `xlsx` format, `DataDownloadResponse` download vs inline
  preview, the 404 / 422 / 500 mapping, and the RBAC/multi-tenancy gate on
  dashboard load. **2 new REQs.**

- **revert-content-versioning** → `content-versioning`: `RevertController::revert`.
  **Annotation only** — the `POST /api/revert/{register}/{schema}/{id}` endpoint
  with `datetime` / `auditTrailId` / `version` modes, the `LockedException`
  (423) path, and the new-version-on-revert semantics are already fully
  specified (see "The system MUST support version rollback" and the
  implementation note listing `RevertController`). **0 new REQs.**

## Total new requirements

8 new requirements (≤ 12 budget): 2 realtime + 4 dashboards + 2 reports.

## Impact

- **Code**: docblock `@spec` annotations only (Edit tool, no logic change).
- **Specs**: extends `realtime-updates`, `built-in-dashboards`,
  `rapportage-bi-export`; re-annotates `graphql-api` and `content-versioning`.
- **Risk**: none — documentation/annotation pass over already-shipped endpoints.

## Dropped scanner false-positives

- `GraphQLController::render` and `getGraphiQLHtml` are private/anonymous-class
  helpers behind `explorer()`; annotated at the method that owns the HTTP
  contract (`explorer`), not as separate requirements.
- `DashboardController::page` returns the SPA template, not a data feed; folded
  into a single "dashboard page + data feed" requirement rather than its own REQ.
- `ReportsController::loadDashboard` is a private RBAC-gated loader; its
  multi-tenancy contract is captured inside the render/preview requirement, not
  as a standalone REQ.
