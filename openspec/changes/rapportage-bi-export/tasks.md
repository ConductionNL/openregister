# Tasks: Rapportage en BI Export

- [x] Implement: The system MUST provide a general-purpose aggregation API
      DONE: the API exists as `GET /api/objects/aggregations/{register}/{schema}/value|grouped|timeseries` (count, sum, avg,
      min, max, groupBy, filters, `metrics[...]` lists), with the spec's short spelling `metric=count,sum,avg&field=bedrag`
      (count takes no field); pinned in `AggregationControllerValueGroupedTest`. The spec's own path
      `GET /api/objects/{register}/{schema}/aggregate` (`AggregationController::report()`) answers
      `{results: [{<groupField>: ..., <metric>: ...}], total, metric, field}`, reads `groupBy=@self.created`, fills every
      bucket of an `interval` series (0 for count and sum, labelled `2026-01`; the last twelve buckets without `from`/`to`)
      and filters rows with `having[<metric>][gt|gte|lt|lte|eq|ne]=<n>` after aggregating; RBAC stays in the runner.
      Pinned in `AggregationControllerReportTest`, `AggregateReportEnvelopeTest` and `AggregateReportPeriodsTest`.
- [x] Implement: The system MUST support configurable report templates
      DONE: templates are objects of the `report-templates` schema in the `report-templates` register
      (`lib/Settings/report_templates_register.json`, imported by the `ImportReportTemplatesRegister` repair step).
      A template names `dataSource` (register, schema, filters), `dateField` (the date property a period narrows,
      `_created` when empty), `sections`, `formats` and `branding.organisation`. A section is `total` (one number),
      `grouped` (count, sum, avg, min or max per value of `groupBy`, every key in `rows` present with 0) or `series`
      (a count per `interval` bucket, every bucket present); a section may name its own register, schema, filters and
      date property, and `compareWithPreviousYear` adds it again one year earlier. `POST /api/reports/generate`
      (`{templateId, dateRange: {from, to}}` or `{templateId, year}`, `format` pdf by default, also ods, xlsx, csv,
      html) loads the template with the caller's rights, counts every section with them (a section the caller may
      not read is a 403 for the whole report, never a report with a section left out) and renders it through the
      existing report writers. Pinned in `ReportTemplateGeneratorTest`, `ReportTemplateControllerTest` and
      `ImportReportTemplatesRegisterTest`. Not in this slice: a Nextcloud Files target path, logo branding and
      the summary section types of custom calculated metrics (median, share within a term).
- [ ] Implement: The system MUST support scheduled report generation
- [x] Implement: The system MUST support export in CSV, Excel, PDF, and ODS formats
      ODS DONE: `format=ods` on `GET /api/objects/{register}/{schema}/export` writes the Excel export's
      spreadsheet with PhpSpreadsheet's Ods writer (`application/vnd.oasis.opendocument.spreadsheet`), pinned in
      `ObjectsControllerTest::testExportReturnsOdsWithTheSameSheetAsExcel`. CSV, Excel and a PDF table existed;
      PDF DONE: the report section carries the title, generation time, object count, the applied filters and a
      status breakdown with counts and percentages (only for a readable, unmasked status column), on A4 landscape with
      "Page n / N" in the footer; pinned in `ExportServicePdfTest::testBuildPdfSectionSummarisesTheStatusAndTheFilters`.
- [x] Implement: The system MUST provide chart data API endpoints for frontend visualization
      DONE: `GET /api/objects/{register}/{schema}/chart?type=bar|line|area|pie|doughnut` takes every parameter of
      `/aggregate` (groupBy, metric/field, metrics[...], interval with from/to, having, filters), runs that report
      (so RBAC, multi-tenancy and the gap-filled buckets come with it) and answers `{type, labels, series: [{name,
      data}]}`, or for pie/doughnut `{type, labels, series: [values], percentages}` (one decimal). `type` defaults
      to bar; an unknown type is a 400 before anything is read; a refused report is answered as it is. The reshaping
      is `AggregateChartData`; pinned in `AggregateChartDataTest` and `AggregationControllerChartTest`.
- [x] Implement: The system MUST support cross-register reporting
      DONE: `GET /api/reports/aggregate` (`CrossRegisterReportController`, `CrossRegisterReport`) discovers the
      register/schema pairs through `getAllRegisterSchemaPairs()` and runs each one through the aggregation runner,
      so RBAC and multi-tenancy apply per pair and a pair the caller may not read is left out without an error or a
      name. Without `interval` it answers `{total, byRegister: [{register, count}], metric, field}` (count, sum, min,
      max; avg is a 400 because an average of averages is not one); with `groupBy=@self.created&interval=month` a
      count series `{labels, series: [{name: <register>, data}]}`, every bucket filled. `registers=a,b` narrows by
      slug or id. Counted through the runner rather than `MagicStatisticsHandler::getStatistics()`, which reads
      every table without the caller's RBAC. Pinned in `CrossRegisterReportTest` and
      `CrossRegisterReportControllerTest`.
- [x] Implement: The system MUST support date range filtering and period-over-period comparison
      DONE: `from`/`to` on `/api/objects/{register}/{schema}/aggregate` narrow a categorical report to `_created` in
      that range (a date-only `to` covers the whole day); `trend=true` on an `interval` series gives each bucket
      `previous<Metric>` and its percentage `change` (null for the first). `GET /api/reports/compare` runs one metric
      over `period1.from/to` and `period2.from/to` and returns both with their labels (`Q1 2026`) and
      `change: {absolute, percentage}`. Pinned in `AggregationControllerReportTest` and `AggregateReportPeriodsTest`.
- [ ] Implement: The system MUST support custom calculated metrics
- [ ] Implement: Reports and exports MUST enforce RBAC permissions
- [ ] Implement: The system MUST support report caching for performance
      PARTLY DONE: the report, chart and ad-hoc aggregation responses now carry `X-Cache: HIT|MISS` beside the
      existing `X-OR-Cache`; results were already cached per caller and organisation by `AggregationCache` and evicted
      on every object create, update, delete and transition by `AggregationCacheInvalidationListener`. Left: the spec's
      5-minute default TTL (the cache keeps the 60 s the aggregations-backend-native spec set; changing it is a
      decision for that spec) and caching template report files, which waits on report templates.
- [ ] Implement: The system MUST support WOO transparency reporting
- [ ] Implement: The system MUST support audit report generation
- [ ] Implement: The system MUST enforce multi-tenant reporting isolation
- [ ] Implement: The system MUST provide an OData v4 endpoint for external BI tool integration
- [ ] Implement: The system MUST support API access for external BI tools beyond OData
