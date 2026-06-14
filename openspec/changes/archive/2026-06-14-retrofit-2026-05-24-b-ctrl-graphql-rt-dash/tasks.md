# Tasks: reverse-spec controller bundle — graphql / realtime / dashboard / reports / revert

Reverse-spec annotation pass. Each task corresponds to a controller method (or
group) whose `@spec` annotation points back to the task anchor below. No
production behaviour changes.

## Capability: graphql-api (annotation only)

### Task 1 — GraphQLController::execute HTTP contract
`POST /api/graphql` — public + CORS execute endpoint. Accepts JSON body
`{query, variables, operationName}`; returns 400 when body is not JSON or
`query` is missing; 200 for data (even with partial errors); 429 +
`Retry-After` header when the first error code is `RATE_LIMITED`. Already
specified by the existing graphql-api requirements (GraphiQL explorer, HTTP
status codes, error response). Annotation re-point only.

### Task 2 — GraphQLController::explorer GraphiQL surface
`GET /api/graphql/explorer` — serves the CDN-hosted GraphiQL v3 HTML page with a
relaxed CSP (unpkg.com script/style/font, inline style, eval) and the CSRF
`requesttoken` baked into the fetcher. `getGraphiQLHtml()` is the private body
builder. Already specified. Annotation re-point only.

## Capability: realtime-updates (extend — cursor-polling HTTP surface)

### Task 3 — RealtimeController::events cursor-polling endpoint
`GET /api/realtime/events?since={cursor}&limit=100&register=&schema=&objectUuid=&eventType=`.
Returns `{events: CloudEvent[], cursor: int, hasMore: bool}`. Anonymous callers
get HTTP 401. `limit` is clamped to `1..1000`. Events are scoped to the caller's
active organisation; with no active org the endpoint returns an empty envelope
(fail-open to empty, not 500). `cursor` is the id of the last returned event, or
`since` (or 0) when empty. `hasMore` is true when the clamped limit was reached.

### Task 4 — RealtimeController::cursor head-pointer endpoint
`GET /api/realtime/cursor` → `{cursor: int}`. Anonymous callers get HTTP 401.
The head cursor is scoped to the caller's active organisation
(`getMaxIdForOrganisation`) to avoid a cross-tenant write-rate side channel;
with no active organisation it fails closed to `{cursor: 0}`. Clients call this
once on subscribe to fast-forward past historical events.

## Capability: built-in-dashboards (extend — dashboard data-feed HTTP surface)

### Task 5 — DashboardController::page dashboard SPA page
`GET` dashboard page — returns the `index` TemplateResponse with a CSP that
allows API connect domains (`connect-src *`); on render failure returns the
`error` template with status 500. Serves the dashboard SPA, not data.

### Task 6 — DashboardController::index registers-with-schemas feed
Returns `{registers: [...]}` — every register with its schemas and per-schema
stats (objects / logs / files / webhookLogs totals + sizes). Optional
`registerId` / `schemaId` filters; pagination/routing params are stripped before
the service call. Errors return `{error}` with status 500.

### Task 7 — DashboardController::calculate size-calculation trigger
Triggers recomputation of object + log storage sizes (optionally scoped by
`registerId` / `schemaId`). Returns the calculation result on 200; on failure
returns `{status: 'error', message, timestamp}` with status 500.

### Task 8 — DashboardController chart & statistics feeds
The six audit/object data feeds powering the dashboard widgets:
`getAuditTrailActionChart` (date-ranged action chart),
`getObjectsByRegisterChart`, `getObjectsBySchemaChart`, `getObjectsBySizeChart`
(distribution buckets), `getAuditTrailStatistics` (totals by action over a
look-back window), `getAuditTrailActionDistribution`, and `getMostActiveObjects`
(top-N by audit activity). All accept optional `registerId` / `schemaId` (and
`from`/`till`/`hours`/`limit` where relevant), return `{labels, series}` /
statistics shapes, and map exceptions to `{error}` with status 500.

## Capability: rapportage-bi-export (extend — report render HTTP surface)

### Task 9 — ReportsController::render on-demand report render
`POST /api/reports/{id}/render?format=xlsx` — renders a stored dashboard
definition to the requested format (default `xlsx`; `csv` / `ods` / `html`
supported). Success returns a `DataDownloadResponse` (browser download).
Dashboard-not-found maps to 404, `InvalidArgumentException` (validation) to 422,
any other render/load failure to a generic 500 with internal detail logged
(never leaked to the response body).

### Task 10 — ReportsController::preview inline HTML preview + RBAC load
`GET /api/reports/{id}/preview` — renders the dashboard to HTML and returns it
with `Content-Disposition: inline` so an iframe can preview before download;
not-found maps to 404. `loadDashboard()` resolves the dashboard by
numeric-id / uuid / slug through `MagicMapper::find`, which applies standard
RBAC + multi-tenancy filtering on every load (no bypass — prevents
cross-tenant render exfiltration).

## Capability: content-versioning (annotation only)

### Task 11 — RevertController::revert object reversion endpoint
`POST /api/revert/{register}/{schema}/{id}` — reverts an object to a prior state
via one of `datetime` / `auditTrailId` / `version` (400 if none supplied),
optional `overwriteVersion`. Maps `DoesNotExistException` → 404,
`NotAuthorizedException` → 403, `LockedException` → 423, other failures → 500.
Already specified by the content-versioning rollback requirements. Annotation
re-point only.
