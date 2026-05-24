---
retrofit: true
---

# Data Import and Export

## ADDED Requirements

### Requirement: Import templates MUST be downloadable as empty header-only files per schema (REQ-018)

The `ExportService` MUST expose a `buildTemplateSpreadsheet()` method that returns a `PhpSpreadsheet\Spreadsheet` containing exactly one sheet titled `<schemaSlug>` (falling back to `'data'`) with a single header row derived from the same `getHeaders($schema, $currentUser)` call used by the regular export path. The headers SHALL include the same RBAC-filtered property columns, companion `_<relation>` columns for relation properties, and admin-only `@self.*` metadata columns that a full export would emit. The `ExportService` MUST also expose a `buildTemplateCsv()` method that wraps the spreadsheet output in `PhpSpreadsheet\Writer\Csv` with `setUseBOM(true)` so Excel detects the UTF-8 BOM correctly. The CSV writer MUST stream to `php://output` between `ob_start()` / `ob_get_clean()`.

This REQ corrects a drift in the canonical spec's "NOT implemented" list — the header-only template path IS implemented. The richer variant (example data row, `instructies` documentation sheet) described in REQ "Import templates MUST be downloadable per schema" Scenario "Download Excel import template with documentation" remains unimplemented (see Notes).

#### Scenario: Template spreadsheet has exactly one header row
- **GIVEN** schema `meldingen` with three readable properties for the current user
- **WHEN** `ExportService::buildTemplateSpreadsheet(register: $reg, schema: $meldingen, currentUser: $user)` is called
- **THEN** the returned `Spreadsheet` MUST contain exactly one sheet
- **AND** the sheet title MUST equal `$meldingen->getSlug()` (or `'data'` if the slug is null)
- **AND** row 1 MUST contain the headers from `getHeaders($meldingen, $user)`
- **AND** no further rows MUST be populated

#### Scenario: Template CSV is BOM-prefixed
- **GIVEN** the same inputs
- **WHEN** `ExportService::buildTemplateCsv($reg, $meldingen, $user)` is called
- **THEN** the returned string MUST start with the UTF-8 BOM `\xEF\xBB\xBF`
- **AND** it MUST contain exactly one CSV row (the header) followed by a line terminator

#### Scenario: Template inherits RBAC filtering from getHeaders
- **GIVEN** schema `personen` has property `bsn` restricted to group `privacy-officers`
- **AND** the current user is NOT in `privacy-officers`
- **WHEN** the user requests a template
- **THEN** the template header row MUST NOT include `bsn`
- **AND** it MUST NOT include the companion `_bsn` column

#### Scenario: Register context drives translatable column expansion
- **GIVEN** schema `producten` has a translatable property `naam`
- **AND** the register has languages `nl`, `en` configured
- **WHEN** `buildTemplateSpreadsheet(register: $producten_register, ...)` runs
- **THEN** the `$this->contextRegister` field MUST be set BEFORE `getHeaders` runs so per-language columns (`naam_nl`, `naam_en`) are emitted

### Requirement: The system MUST expose a per-user personal data export endpoint (GDPR Art. 20) (REQ-019)

OpenRegister MUST expose an authenticated `GET /apps/openregister/api/user/me/export` endpoint that returns a `DataDownloadResponse` containing a JSON document of all personal data held about the calling user. The endpoint MUST be implemented by `UserController::exportData()` and backed by `UserService::exportPersonalData()`. The endpoint MUST be marked `@NoAdminRequired` and `@NoCSRFRequired` (download-only). The export MUST be rate-limited to once per hour per user via the `last_export_time` user-value and `EXPORT_RATE_LIMIT` constant; exceeding the limit MUST return HTTP 429 with body `{"error":"...","retry_after":<seconds>}`. The JSON envelope MUST contain `exportDate` (ISO 8601), `profile`, `organisations`, `objects`, and `auditTrail` keys.

#### Scenario: Authenticated user downloads their data
- **GIVEN** an authenticated user calls `GET /apps/openregister/api/user/me/export`
- **WHEN** `UserController::exportData()` runs
- **THEN** the response MUST be a `DataDownloadResponse` with content type `application/json`
- **AND** the filename MUST follow pattern `openregister-export-<uid>-<YYYY-MM-DD>.json`
- **AND** the JSON body MUST be pretty-printed with `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`
- **AND** the body MUST contain top-level keys `exportDate`, `profile`, `organisations`, `objects`, `auditTrail`

#### Scenario: Unauthenticated request is rejected
- **GIVEN** there is no current user (session expired)
- **WHEN** the endpoint runs
- **THEN** the response MUST be HTTP 401 with body `{"error":"Not authenticated"}`

#### Scenario: Second export within an hour is rate limited
- **GIVEN** the user successfully exported 30 minutes ago (`last_export_time` user-value set)
- **WHEN** they call the endpoint again
- **THEN** `UserService::exportPersonalData()` MUST throw a `RuntimeException` with code 429
- **AND** the exception message MUST be a JSON string `{"error":"Data export is limited to once per hour","retry_after":<seconds>}`
- **AND** the controller MUST return a 429 `JSONResponse` with the decoded payload
- **AND** the response MUST go through `SecurityService::addSecurityHeaders()`

#### Scenario: Audit trail is sliced to the last 1000 entries
- **GIVEN** the user has 5000 audit trail entries authored by them
- **WHEN** the export runs
- **THEN** `AuditTrailMapper::findByActor($uid, 1000, 0)` MUST be called
- **AND** the `auditTrail` array in the response MUST contain at most 1000 `jsonSerialize()`-d entries

#### Scenario: Vue component triggers download with date-stamped filename
- **GIVEN** the user clicks "Export my data" in `ExportSection.vue`
- **WHEN** `exportData()` runs
- **THEN** the component MUST `GET /apps/openregister/api/user/me/export` with `responseType: 'blob'`
- **AND** on success it MUST trigger a browser download with filename `openregister-export-<YYYY-MM-DD>.json`
- **AND** on HTTP 429 it MUST display the localized message "Export is rate limited. Please try again later."
- **AND** on other errors it MUST display "Failed to export data"

### Requirement: Frontend file-type sniffing MUST route uploads to the correct importer by extension (REQ-020)

`ImportRegister.vue` MUST sniff the extension of any uploaded file via a single helper `getFileExtension(filename)` that lowercases the substring after the last dot. The result MUST drive: (a) the format-specific sub-form rendering (CSV column-mapping panel, Excel sheet selector), (b) the human-readable type label in the upload preview ("JSON Configuration", "Excel Spreadsheet", "CSV Data"), (c) the allow-list validation rejecting non-CSV/XLS/XLSX/JSON uploads with a translated error, and (d) the per-format request payload shape sent to the backend import endpoint.

#### Scenario: Helper is case-insensitive and reads the last extension
- **GIVEN** filename `Data.Backup.CSV`
- **WHEN** `getFileExtension(filename)` runs
- **THEN** the return value MUST be the lowercased string `"csv"`
- **AND** intermediate dots MUST be ignored

#### Scenario: Disallowed extension is rejected at upload time
- **GIVEN** the user picks a file `archive.zip`
- **AND** `allowedFileTypes` does not include `"zip"`
- **WHEN** `handleFileUpload(event)` runs
- **THEN** `this.error` MUST be set to a translated message naming the file and the allowed extensions
- **AND** `this.selectedFile` MUST be cleared so the submit button stays disabled

#### Scenario: Extension drives the import request shape
- **GIVEN** the user submits a `.json` file
- **WHEN** the import method runs
- **THEN** the request body MUST be the JSON form payload (no `multipart/form-data`)
- **GIVEN** the user submits a `.xlsx` or `.xls` file
- **WHEN** the import method runs
- **THEN** the request MUST use `multipart/form-data` with the Excel sheet selector value attached
- **GIVEN** the user submits a `.csv` file
- **WHEN** the import method runs
- **THEN** the request MUST use `multipart/form-data` with the CSV column-mapping values attached

### Requirement: Configuration import-from-source MUST pre-flight check API token availability (REQ-021)

`ImportConfiguration.vue` MUST call `checkTokenAvailability()` from its mounted hook before the user can search GitHub or GitLab for shareable configurations. The method MUST `GET /apps/openregister/api/settings/api-tokens`, set `this.hasGithubToken` and `this.hasGitlabToken` based on whether the masked tokens are present and non-empty in the response, and silently fall back to `false` for both on any network/HTTP error. The token flags MUST gate (a) the warning banner above the search box, (b) the GitHub/GitLab search buttons (`:disabled="!hasGithubToken"` etc.), and (c) the warning title/message dynamically based on which tokens are missing.

#### Scenario: Both tokens missing shows full warning
- **GIVEN** `/api/settings/api-tokens` returns `{"github_token":"","gitlab_token":""}`
- **WHEN** `checkTokenAvailability()` runs after mount
- **THEN** `hasGithubToken` MUST be `false`
- **AND** `hasGitlabToken` MUST be `false`
- **AND** the warning banner MUST render with title "API Tokens Not Configured"
- **AND** both search buttons MUST be disabled

#### Scenario: Only GitHub token configured
- **GIVEN** the API returns `{"github_token":"ghp_xxxxx****","gitlab_token":""}`
- **WHEN** the check runs
- **THEN** `hasGithubToken` MUST be `true`, `hasGitlabToken` MUST be `false`
- **AND** the warning title MUST be "GitLab Token Not Configured"
- **AND** the GitHub button MUST be enabled, the GitLab button MUST be disabled

#### Scenario: API error degrades to "no tokens"
- **GIVEN** the `/api/settings/api-tokens` request throws (network error, 500, etc.)
- **WHEN** the catch block runs
- **THEN** both flags MUST be set to `false`
- **AND** no exception MUST propagate to the user
- **AND** the warning banner MUST render as if no tokens were configured

### Requirement: Import and export modals MUST reset all form state on close (REQ-022)

Each modal under `src/modals/configuration/` and `src/modals/register/` that drives an import or export workflow MUST expose a `closeModal()` method that (a) sets `navigationStore.setModal(false)` to dismiss the modal, and (b) resets every locally-tracked form/result/error field to its initial value so reopening the modal starts from a clean slate. Modals MUST invoke `closeModal()` from: the close button click, the `@update:open` handler on the underlying `NcDialog`, and any success-path `setTimeout` after a successful submission. The backend cross-import cache state in `ImportService::clearCaches()` MUST clear `$schemaPropertiesCache` to prevent stale property metadata from leaking between consecutive imports in the same PHP process.

#### Scenario: ImportConfiguration modal close resets the form
- **GIVEN** the user filled the search query, picked a branch, and saw search results
- **WHEN** they click the close button
- **THEN** `closeModal()` MUST set the navigation modal to `false`
- **AND** `resetForm()` MUST run, clearing `loading`, `success`, `error`, `searchQuery`, `searchResults`, `repoOwner`, `repoNamespace`, `repoName`, `branches`, `selectedBranch`, `configFiles`, `selectedFile`, `importUrl`, `urlError`
- **AND** the next time the modal opens, the form MUST be empty

#### Scenario: ExportConfiguration / ExportRegister close resets to defaults
- **GIVEN** the user has changed the export format and toggled `includeObjects`
- **WHEN** they close the modal
- **THEN** `closeModal()` MUST reset `loading=false`, `error=null`, and the format/includeObjects fields to their defaults (`'excel'` and `false` respectively)

#### Scenario: ImportRegister close clears the picked file and submit flags
- **GIVEN** the user picked a CSV and toggled `validation=false`
- **WHEN** they close the modal
- **THEN** `closeModal()` MUST clear `selectedFile`, `loading`, `success`, `error`
- **AND** it MUST reset the boolean toggles `includeObjects`, `validation`, `events` to their initial defaults

#### Scenario: ImportService cross-import cache reset
- **GIVEN** an import of register A has populated `$schemaPropertiesCache` with entries keyed by schema id
- **WHEN** the caller invokes `ImportService::clearCaches()` before importing register B
- **THEN** `$schemaPropertiesCache` MUST be reset to `[]`
- **AND** the next call into the import pipeline MUST recompute property metadata from the source-of-truth schema mapper
