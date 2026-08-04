# Tasks: retrofit reverse-spec service bundle — report / import / link

Reverse-spec: every task annotates already-shipped behavior. No logic changes.

## Sub-cluster 1 — Report-rendering pipeline (extend `rapportage-bi-export`)

- [x] task-1 — `ReportRenderService::render()` dispatch: validate format against
  `FORMATS`, normalise the dashboard payload, resolve every widget, build the
  slugified `{slug}_{timestamp}.{ext}` filename, and route to the html / pdf /
  spreadsheet writer with the correct MIME. (`lib/Service/Reporting/ReportRenderService.php`)
- [x] task-2 — `ReportRenderService` widget resolution + payload normalisation:
  `resolveWidgetData()` (aggregation via `AggregationRunner::run(bypassRbac:true)`,
  graphql via `GraphQLService::execute`, per-widget try/catch → null on failure),
  `normalisePayload()`, `slugify()`, `mimeFor()`.
  (`lib/Service/Reporting/ReportRenderService.php`)
- [x] task-3 — `HtmlReportWriter` print-friendly HTML composition: `write()`,
  `renderWidget()`, `renderBody()`, `renderGroupTable()`, `renderStatsBlock()`,
  `extractHeadline()`, `css()`, `escape()`. (`lib/Service/Reporting/HtmlReportWriter.php`)
- [x] task-4 — `PdfReportWriter::write()` Dompdf hardening: strip
  `<link rel=stylesheet>` + `@font-face` from the HTML, pin
  `isRemoteEnabled=false` / `isPhpEnabled=false`, assert the flags did not drift
  (throw on drift), render A4 portrait. (`lib/Service/Reporting/PdfReportWriter.php`)
- [x] task-5 — `SpreadsheetReportWriter` per-widget sheets: `write()`,
  `writeOverviewSheet()` (cover sheet), `writeWidgetSheet()` (31-char Excel-safe
  sheet title sanitisation, group vs scalar layout), `writeFormat()` (csv with
  BOM + per-sheet concat, xlsx, ods), `headlineFor()`, `describeSource()`,
  `numericOrString()`. (`lib/Service/Reporting/SpreadsheetReportWriter.php`)

## Sub-cluster 2 — Configuration import/export facade (extend `data-import-export`)

- [x] task-6 — `ConfigurationService` import/export facade: `exportConfig()`,
  `getUploadedJson()`, `importFromFilePath()`, `importFromApp()`,
  `fetchRemoteConfiguration()`, `previewConfigurationChanges()`,
  `importConfigurationWithSelection()` — thin delegations to the already-spec'd
  Configuration/* handlers. (`lib/Service/ConfigurationService.php`)
- [x] task-7 — `ConfigurationService` remote-version lifecycle: `checkRemoteVersion()`
  (fetch + persist `remoteVersion`/`lastChecked`), `compareVersions()`
  (`version_compare` → hasUpdate/message), `getConfiguredAppVersion()` /
  `setConfiguredAppVersion()` (appconfig version tracking).
  (`lib/Service/ConfigurationService.php`)

## Sub-cluster 3 — Legacy flat link services (extend `integration-registry`)

- [x] task-8 — `DeckCardService` legacy Deck link service: `getCardsForObject()`
  (link rows enriched with dueDate/labels/assignees, best-effort, stale-card
  degradation), `linkOrCreateCard()`, `unlinkCard()`, `getObjectsForBoard()`
  (board search), `deleteLinksForObject()` (cleanup), plus the Deck-entity
  extraction helpers. (`lib/Service/DeckCardService.php`)
- [x] task-9 — `NoteService` comment-backed object notes: `getNotesForObject()`,
  `createNote()`, `updateNote()` (author-only edit guard), `deleteNote()`,
  `deleteNotesForObject()` (cleanup) over `ICommentsManager` with objectType
  `openregister`. (`lib/Service/NoteService.php`)
- [x] task-10 — `TaskService` CalDAV VTODO task linking:
  `getAllUserTasks()` (cross-calendar, status/assignee filter, due-sorted),
  `getTasksForObject()`, `createTask()` (X-OPENREGISTER-* + RFC 9253 LINK),
  `updateTask()`, `deleteTask()`, `extractAssigneeFromDescription()`. NOTE:
  `getTasksForObject` / `createTask` already carry an archived
  `retrofit-annotate-openregister-2026-04-30#task-61` annotation; this task adds
  the integration-registry back-reference to the remaining un-annotated methods.
  (`lib/Service/TaskService.php`)

## Sub-cluster 4 — DSAR personal-data handlers (extend `avg-verwerkingsregister`)

- [x] task-11 — `DsarService` find / erase: `findObjectsForSubject()` (Art 15
  inzage envelope), `eraseObjectsForSubject()` (Art 17 vergetelheid soft-delete
  with dry-run + DSAR processing-activity audit attribution), plus the
  `matchEntities()` LIKE-wildcard-escaped GdprEntity join, `buildObjectKey()`,
  `loadObjectByEntry()`, `getDsarProcessingActivityUuid()`.
  (`lib/Service/DsarService.php`)
- [x] task-12 — `DsarService::rectifyObjectForSubject()` (Art 16 rectificatie):
  merge changes into the object payload, pin the DSAR processing-activity uuid,
  persist via `MagicMapper::update`. (`lib/Service/DsarService.php`)
