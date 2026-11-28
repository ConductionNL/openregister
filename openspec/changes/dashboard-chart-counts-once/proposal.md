# The dashboard's object charts read their schemas in one query and count each table once

## What was wrong

The objects-by-register and objects-by-schema charts walk every register/schema
pair (32 on the bundled registers). Each pair read its schema with its own query
(~15 ms for the walk), then counted its table (~22 ms). Read together through
`GET /api/dashboard/panels`, the two charts walked and counted the same 32 tables
twice. Each chart cost ~110-135 ms, over the 100 ms API rule.

## What changes

- The chart walk loads the schemas of all its pairs in one query
  (`SchemaMapper::preloadUnfiltered()`, as the cross-table search does since
  #11389). A schema the bulk read misses is read on its own, and a failed bulk
  read leaves every schema to its own read.
- Inside a `rememberPairCounts()` window a chart pair's count is kept and
  reused. `GET /api/dashboard/panels` runs its panels in one window. Only a count
  that succeeded is kept, under its own key, so a pair whose count fails stays out
  of every chart, as before.
- `DashboardService::countingPairsOnce()` opens that window for the controller.

## Out of scope

- The per-table count statements themselves (one per table).
