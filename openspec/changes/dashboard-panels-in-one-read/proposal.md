# The OR Dashboard reads its chart and statistics panels in one request

## What was wrong

On load the OR Dashboard sent seven reads at once for its panels: four charts
(`/api/dashboard/charts/audit-trail-actions`, `objects-by-register`,
`objects-by-schema`, `objects-by-size`) and three statistics
(`/api/dashboard/statistics/audit-trail`, `audit-trail-distribution`,
`most-active-objects`). Each read pays Nextcloud's whole per-request cost
(bootstrap, session, app loading: ~70 ms on the reference stack) for a few
milliseconds of work (74-121 ms each, ~610 ms of server time together). On a
full page load they queue behind each other in php-fpm, and the page is not
usable until the last one has answered.

## What changes

- `GET /api/dashboard/panels` answers all seven panels in one request, with the
  same parameters (`from`, `till`, `registerId`, `schemaId`, `hours`, `limit`).
  Each panel carries the status and body its own endpoint answers
  (`{panel: {status, data}}`), so one failing panel does not fail the others.
  Admin only, like the panels themselves (403 for anyone else, nothing computed).
- The dashboard store's `fetchAllPanels()` reads it and keeps each panel where
  the per-panel reads kept it. `preload()`, the register/schema scope watchers
  and the live refetch use it. A panel whose own read is already in flight is
  left to it (then the per-panel reads run as before).
- The per-panel endpoints stay (other views read single charts).

## Out of scope

- The register detail and schema detail views, which read only the charts.
