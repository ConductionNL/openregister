# Tasks: aggregations-a-shipped-report-may-rely-on

- [x] 1 Review `AggregationRunner::runAdhocByRef()` and the timeseries endpoint against the two requirements: is the
      empty-value group returned (key `null`) or dropped today, and do `from`/`to` bound a named date field.
      > Reviewed 24 Nov. The empty-value group is RETURNED on both paths: the native `GROUP BY` keeps SQL NULL as one
      > group and `nativeGroupKeys()` reads it as key `null`; the PHP path buckets `$row[$field] ?? null`. An empty
      > string is a value and stays its own group. `from`/`to` did NOT bound anything without `interval`: the
      > validator only read them for a date bucket, so a categorical count silently ignored them.
- [x] 2 Return the empty-value group where it is dropped, on the native and the PHP fallback path alike.
      > Nothing was dropped (see 1), so no runner change; the contract test pins the null group on both paths. The
      > gap the review found is the period: `dateField` on the timeseries request (`TimeseriesDateRangeFilter`) turns
      > `from`/`to` into `{dateField: {gte: from, lt: to}}`, through the operators both paths implement. It is refused
      > for a non-date field, without both bounds, for an empty period, together with `interval`, and when `filter`
      > already matches or bounds the field. Without `dateField` a request is unchanged.
- [x] 3 A contract test (PHPUnit) per scenario, named for the report contract, so a change to the shape goes red.
      > `tests/Unit/Service/Aggregation/AggregationReportContractTest.php` (7): both scenarios on the native path (real
      > SQLite) and the PHP fallback, the `{groups: [{key, value}], backend, cached}` shape, the organisation scope, and
      > the period's refusals. Mutation-checked: dropping the period reddens three tests.
- [x] 4 Name the report contract in the aggregation docs (`docs/`), with what it promises and what it does not.
      > `docs/technical/aggregation-api.md`: "The report contract" and the `dateField` parameter.
- [ ] 5 Tell launchpad when it lands, so its `ReportLibrary` moves onto it (launchpad `dashboards-and-who-may-see-them`).
