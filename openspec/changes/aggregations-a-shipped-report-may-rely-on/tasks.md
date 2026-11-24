# Tasks: aggregations-a-shipped-report-may-rely-on

- [ ] 1 Review `AggregationRunner::runAdhocByRef()` and the timeseries endpoint against the two requirements: is the
      empty-value group returned (key `null`) or dropped today, and do `from`/`to` bound a named date field.
- [ ] 2 Return the empty-value group where it is dropped, on the native and the PHP fallback path alike.
- [ ] 3 A contract test (PHPUnit) per scenario, named for the report contract, so a change to the shape goes red.
- [ ] 4 Name the report contract in the aggregation docs (`docs/`), with what it promises and what it does not.
- [ ] 5 Tell launchpad when it lands, so its `ReportLibrary` moves onto it (launchpad `dashboards-and-who-may-see-them`).
