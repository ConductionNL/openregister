# Retrofit reverse-spec: service bundle — report / import / link (4 sub-clusters)

## Why

This is a **reverse-spec (ghost) change**: it documents already-shipped,
already-merged service behavior that has no spec coverage and annotates the
implementing methods with `@spec` back-references. No production code logic
changes — only docblock annotations are added.

Four service sub-clusters are covered, each extending an existing capability:

1. **Report-rendering pipeline** (`lib/Service/Reporting/`) — the server-side
   dashboard renderer (`ReportRenderService`) and its three pluggable format
   writers (`HtmlReportWriter`, `PdfReportWriter`, `SpreadsheetReportWriter`).
   The `rapportage-bi-export` spec already names these writers in its
   Implementation section and declares the format-support requirement, but the
   **writer-abstraction contract** (resolve-then-dispatch, per-widget sheets,
   graceful per-widget failure) and the **Dompdf hardening invariant** (sandbox
   flags + stylesheet/font stripping) are not formal requirements. Annotate the
   19 methods; add writer-abstraction and PDF-sandbox requirements.

2. **Configuration import/export facade** (`lib/Service/ConfigurationService.php`)
   — the public facade over the already-spec'd `Configuration/ImportHandler`,
   `Configuration/ExportHandler`, `FetchHandler`, `PreviewHandler`,
   `UploadHandler`, and `CacheHandler` (those handlers were annotated by the
   archived `retrofit-2026-04-23` / `retrofit-2026-05-24-annotate-openregister`
   changes against `data-import-export`). The thin delegating wrappers on
   `ConfigurationService` (`importFromFilePath`, `importFromApp`,
   `fetchRemoteConfiguration`, `previewConfigurationChanges`,
   `importConfigurationWithSelection`, `checkRemoteVersion`, `compareVersions`,
   `getConfiguredAppVersion` / `setConfiguredAppVersion`, `getUploadedJson`,
   `exportConfig`) are the un-annotated facade layer. Annotate them; add one
   facade/version-lifecycle requirement.

3. **Legacy flat external-app link services** (`lib/Service/DeckCardService.php`,
   `lib/Service/NoteService.php`, `lib/Service/TaskService.php`) — the
   **pre-ADR-019** flat link services that still exist alongside the new
   `IntegrationProvider` registry being introduced by the in-flight
   `pluggable-integration-registry` change (PR #1811). This bundle does **not**
   re-specify the registry contract (owned there) — it records the
   non-provider behavior these flat services carry that the provider migration
   MUST preserve: Deck board-search + label/assignee enrichment + stale-card
   degradation, comment-backed object notes with author-edit guard, and CalDAV
   VTODO task linking with assignee-from-description extraction.

4. **DSAR personal-data handlers** (`lib/Service/DsarService.php`) — the partial
   implementation of the AVG data-subject rights flows the
   `avg-verwerkingsregister` spec lists as NOT-implemented
   (`DataSubjectSearchService`, `ErasureRequestHandler`). `DsarService`
   composes the `GdprEntity` index + `entity_relations` join + `MagicMapper`
   lookup into find / erase / rectify with processing-activity audit
   attribution and LIKE-wildcard escaping. Annotate; add a find/erase
   requirement and a rectify requirement. Authorization gaps are surfaced as
   Notes (not new requirements).

## What Changes

- Add `@spec` annotations to the implementing methods (docblock-only).
- `rapportage-bi-export`: ADD writer-abstraction + Dompdf-sandbox requirements.
- `data-import-export`: ADD a `ConfigurationService` facade / version-lifecycle
  requirement.
- `integration-registry`: ADD two legacy-flat-link-service requirements
  (behavior the provider migration must preserve).
- `avg-verwerkingsregister`: ADD DSAR find/erase + rectify requirements.

## Dropped scanner candidates (false positives)

- `MigrationService` (`resolveRegisterAndSchema`, `getStorageStatus`,
  `migrateToMagicTable`, `migrateToBlobStorage`) — magic-table / blob storage
  lifecycle, belongs to `object-lifecycle`, not import/export. Dropped.
- `Configuration/AttributionFormatter`, `Configuration/GitHubGuards`, and the
  `GitHubHandler::listIssues` / `createIssue` + `ConfigurationService::searchGitHub`
  / `searchGitLab` methods — these belong to the GitHub issue-proxy
  (`add-github-issue-proxy` / `add-features-roadmap-menu`), not configuration
  import. Already annotated there. Dropped from this bundle.
- `ApplicationService` (`findAll` / `find` / `create` / `update` / `delete`) —
  generic CRUD over the `applications` table, not an import/export concern.
  Dropped.

## Impact

- Affected specs: `rapportage-bi-export`, `data-import-export`,
  `integration-registry`, `avg-verwerkingsregister`.
- Affected code: docblock annotations only across the report writers,
  `ConfigurationService` facade, the three legacy link services, and
  `DsarService`. No behavioral change.
