# Tasks: flow-run-summary

- [x] Mapper: `summarizeByFlow`, `countSinceByFlow`, `latestRunsByFlow`; `findAllRuns` takes `since` and `flowIds`
- [x] Controller: `summary()`, `since`/`flowIds` on `index()`, 400 on an unreadable `since`
- [x] Route `flowRun#summary` above `{uuid}`
- [x] Unit tests for the controller and the mapper's assembly
- Not in this change (a nextcloud-vue PR; the summary read is built: flowRun#summary GET /api/flow-runs/summary (appinfo/routes.php:2159)): nc-vue: the flows index source reads the summary (separate PR in nextcloud-vue)
