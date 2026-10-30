# Tasks: flow-run-summary

- [x] Mapper: `summarizeByFlow`, `countSinceByFlow`, `latestRunsByFlow`; `findAllRuns` takes `since` and `flowIds`
- [x] Controller: `summary()`, `since`/`flowIds` on `index()`, 400 on an unreadable `since`
- [x] Route `flowRun#summary` above `{uuid}`
- [x] Unit tests for the controller and the mapper's assembly
- [ ] nc-vue: the flows index source reads the summary (separate PR in nextcloud-vue)
