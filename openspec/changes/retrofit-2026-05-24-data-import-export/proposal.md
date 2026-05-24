# Retrofit — data-import-export (extend)

Describes observed behavior of 8 kept methods across 7 files in the data-import-export cluster as 5 new REQs extending the existing `data-import-export` capability. Code already exists — this change retroactively specifies it. Triaged drops from the batch (50 methods) are recorded in the batch JSON, not re-specified here.

## Affected code units
- lib/Service/ExportService.php::buildTemplateSpreadsheet
- lib/Service/ExportService.php::buildTemplateCsv
- lib/Service/ImportService.php::clearCaches
- lib/Controller/UserController.php::exportData (backing endpoint for `ExportSection.vue::exportData`)
- lib/Service/UserService.php::exportPersonalData (backing service for the same endpoint)
- src/views/account/sections/ExportSection.vue::exportData
- src/modals/configuration/ImportConfiguration.vue::checkTokenAvailability
- src/modals/configuration/ImportConfiguration.vue::closeModal
- src/modals/configuration/ExportConfiguration.vue::closeModal
- src/modals/register/ExportRegister.vue::closeModal
- src/modals/register/ImportRegister.vue::closeModal
- src/modals/register/ImportRegister.vue::getFileExtension

## Approach

The existing canonical spec covers 17 REQs across the backend pipeline (CSV/Excel import, bulk API, validation, dedup, RBAC, scheduled imports, configuration portability, etc.). The 8 kept methods in this batch sit in five behavioral slices not yet specified:

- **REQ-018** — Import templates (download CSV/XLSX for a schema). The existing spec lists this as a planned/not-implemented requirement, but `ExportService::buildTemplateSpreadsheet()` and `ExportService::buildTemplateCsv()` already implement the schema → empty-template pipeline. Documented here as observed implementation; this also corrects a drift in the existing spec's "NOT implemented" list (see Notes).
- **REQ-019** — Personal data export endpoint (GDPR Article 20). The existing spec is entirely silent on per-user "give me my data" flows; only register/schema-level exports are covered. `UserController::exportData` + `UserService::exportPersonalData` + `ExportSection.vue` implement a hourly-rate-limited JSON download covering profile, organisation memberships, and audit trail.
- **REQ-020** — Frontend file-type sniffing routes uploads to the correct importer based on extension. `ImportRegister.vue::getFileExtension()` is the single source of truth used by template gating, file-type display, upload validation, and per-format request building.
- **REQ-021** — Configuration import-from-source pre-flight checks for GitHub/GitLab API tokens before discovery. `ImportConfiguration.vue::checkTokenAvailability` calls `/api/settings/api-tokens` on mount, drives the warning banner, and disables search buttons when tokens are missing.
- **REQ-022** — Import/export modal lifecycle: each modal MUST reset its form state on close so the next open starts clean. Covers the four `closeModal` methods plus the backend `ImportService::clearCaches()` used to reset cross-import caches.

## Notes / observed drifts

- The canonical spec's "NOT implemented" list still includes "Import template generation (downloadable CSV/Excel with headers, example data, and documentation)". `buildTemplateSpreadsheet` and `buildTemplateCsv` provide the header-only path (no example row, no `instructies` sheet). REQ-018 retroactively specifies the implemented subset; the example-row / instructions-sheet variant remains unimplemented and is now flagged below the new REQ.
- `UserService::exportPersonalData()` currently returns `'objects' => []` (placeholder). The exported envelope shape carries an `objects` key but the data is not populated — flagged in REQ-019.
- `ImportConfiguration.vue::checkTokenAvailability` assumes any non-empty (masked) token from `/api/settings/api-tokens` means "configured". This means a revoked or expired GitHub/GitLab token still passes the pre-flight; failures only surface when the actual search call returns 401/403. Flagged in REQ-021.
- `ImportService::clearCaches()` currently only clears `$schemaPropertiesCache`. Other internal caches inside ImportService (e.g. validation-result caches) are not reset by this method — flagged in REQ-022.
- `closeModal` in `ImportConfiguration.vue` calls `resetForm()` which clears ~15 fields; the other three modals reset state inline (3-5 fields each). REQ-022 spec is on the contract (state MUST be reset), not the implementation shape.

Source: openspec/coverage-report.md — Bucket 2a (extend) for `data-import-export`, batch JSON `/tmp/or-scan/rspec-cluster-data-import-export.json`. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
