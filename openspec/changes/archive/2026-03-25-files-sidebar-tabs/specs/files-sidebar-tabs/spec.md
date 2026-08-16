---
status: draft
---
# Files Sidebar Tabs

## Purpose
Integrates OpenRegister into the Nextcloud Files app sidebar by registering custom tabs that display register object references and file extraction metadata for any selected file. This enables records managers, privacy officers, and archivists to understand the relationship between files and structured register data without leaving the Files app.

## Requirements

### Requirement: Sidebar Tab Registration
The system SHALL register two custom tabs in the Nextcloud Files app sidebar using the `OCA.Files.Sidebar.Tab` API. The tabs MUST be loaded only when the Files app is active and MUST NOT interfere with other sidebar tabs or the core Files app functionality.

#### Scenario: Tabs are registered on DOMContentLoaded
- **GIVEN** the Nextcloud Files app is loaded
- **WHEN** the DOMContentLoaded event fires
- **THEN** two sidebar tabs MUST be registered via `OCA.Files.Sidebar.registerTab()`
- **AND** the first tab MUST have id `openregister-objects` and name "Register Objects"
- **AND** the second tab MUST have id `openregister-extraction` and name "Extraction"

#### Scenario: Tabs are only loaded in the Files app context
- **GIVEN** the OpenRegister app is enabled
- **WHEN** the user navigates to the Files app
- **THEN** the `openregister-files-sidebar.js` script MUST be loaded via `\OCP\Util::addScript()` in a `BeforeTemplateRenderedEvent` listener scoped to the `files` app
- **AND** the script MUST NOT be loaded in other app contexts

#### Scenario: Tabs are not registered when sidebar is unavailable
- **GIVEN** the Files app page has loaded
- **WHEN** `OCA.Files.Sidebar` is undefined (e.g., public share page without sidebar)
- **THEN** the tab registration code MUST exit gracefully without errors

#### Scenario: Tab icons use Material Design Icons
- **GIVEN** the sidebar tabs are registered
- **WHEN** the tab icon is rendered
- **THEN** the Register Objects tab MUST use the `database-outline` MDI SVG icon
- **AND** the Extraction tab MUST use the `text-box-search-outline` MDI SVG icon

### Requirement: Register Objects Tab
The Register Objects tab SHALL display a list of all OpenRegister objects that reference the selected file. Each object entry MUST show the register name, schema name, object title (or UUID), and a clickable link to open the object in the OpenRegister app.

#### Scenario: Objects referencing the file are displayed
- **GIVEN** the user selects a file in the Files app
- **WHEN** the Register Objects tab is mounted or updated with the file info
- **THEN** the tab MUST call `GET /apps/openregister/api/files/{fileId}/objects` with the Nextcloud file ID
- **AND** the response objects MUST be rendered as a list showing register name, schema name, and object title

#### Scenario: No objects reference the file
- **GIVEN** the user selects a file that is not referenced by any OpenRegister object
- **WHEN** the Register Objects tab loads the data
- **THEN** the tab MUST display an NcEmptyContent component with the message "No register objects reference this file"
- **AND** the empty state MUST include the `database-off-outline` icon

#### Scenario: Object link navigates to OpenRegister
- **GIVEN** the Register Objects tab displays a list of referencing objects
- **WHEN** the user clicks on an object entry
- **THEN** the browser MUST navigate to the OpenRegister app at `/apps/openregister/registers/{registerId}/schemas/{schemaId}/objects/{objectUuid}`

#### Scenario: Tab shows loading state
- **GIVEN** the Register Objects tab is mounted
- **WHEN** the API request is in progress
- **THEN** the tab MUST display an NcLoadingIcon centered in the tab area

#### Scenario: API error is handled gracefully
- **GIVEN** the Register Objects tab calls the objects-by-file API
- **WHEN** the API returns an error (4xx or 5xx)
- **THEN** the tab MUST display an NcEmptyContent with the message "Failed to load register data"
- **AND** the error MUST be logged to the browser console

#### Scenario: Tab updates when file selection changes
- **GIVEN** the Register Objects tab is mounted and showing data for file A
- **WHEN** the user selects a different file B
- **THEN** the tab's `update(fileInfo)` callback MUST fetch and display objects for file B
- **AND** the previous data MUST be cleared before the new data loads

### Requirement: Objects-by-File API Endpoint
The system SHALL expose an authenticated API endpoint at `GET /api/files/{fileId}/objects` that returns all OpenRegister objects referencing the given Nextcloud file ID. The endpoint MUST search across all registers and schemas that the current user has access to.

#### Scenario: File referenced by multiple objects across registers
- **GIVEN** file ID 42 is referenced in object A (register 1, schema 1) and object B (register 2, schema 3)
- **WHEN** an authenticated user calls `GET /api/files/42/objects`
- **THEN** the response MUST be HTTP 200 with a JSON array containing both objects
- **AND** each object MUST include `uuid`, `register` (object with `id` and `title`), `schema` (object with `id` and `title`), and `title` (first string property value or UUID)

#### Scenario: File not referenced by any object
- **GIVEN** file ID 99 is not referenced by any OpenRegister object
- **WHEN** an authenticated user calls `GET /api/files/99/objects`
- **THEN** the response MUST be HTTP 200 with an empty JSON array `[]`

#### Scenario: Search strategy for file references
- **GIVEN** OpenRegister schemas can have properties of format `file` that store Nextcloud file IDs
- **WHEN** the `FileSidebarService::getObjectsForFile()` method is called
- **THEN** it MUST query the `oc_openregister_objects` table (or the per-schema magic tables) for rows where any JSON property value contains the file ID
- **AND** it MUST also check the `oc_openregister_file_relations` table if it exists for indexed file-to-object mappings

#### Scenario: Results respect RBAC permissions
- **GIVEN** a user without access to register 3
- **WHEN** the user calls `GET /api/files/42/objects` and file 42 is referenced in register 3
- **THEN** objects from register 3 MUST NOT appear in the response
- **AND** only objects from registers the user has read access to MUST be returned

#### Scenario: Unauthenticated access is rejected
- **GIVEN** an unauthenticated client
- **WHEN** the client calls `GET /api/files/42/objects`
- **THEN** the Nextcloud framework MUST return HTTP 401

### Requirement: Extraction & Metadata Tab
The Extraction tab SHALL display text extraction status, chunk statistics, detected entity counts, risk level, and anonymization information for the selected file. This gives privacy officers immediate visibility into the PII analysis status of any file.

#### Scenario: Extraction data is displayed for a processed file
- **GIVEN** the user selects a file that has been processed by OpenRegister's text extraction
- **WHEN** the Extraction tab is mounted or updated
- **THEN** the tab MUST call `GET /apps/openregister/api/files/{fileId}/extraction-status`
- **AND** the response data MUST be rendered showing: extraction status (pending/processing/completed/failed), chunk count, entity count, risk level, and extraction timestamp

#### Scenario: File has not been processed
- **GIVEN** the user selects a file that has no extraction records in OpenRegister
- **WHEN** the Extraction tab loads
- **THEN** the tab MUST display an NcEmptyContent with the message "No extraction data available for this file"
- **AND** a button labeled "Extract Now" MUST be shown that triggers `POST /apps/openregister/api/files/{fileId}/extract`

#### Scenario: Risk level is displayed with appropriate styling
- **GIVEN** the extraction data includes a risk level
- **WHEN** the Extraction tab renders the risk level
- **THEN** risk level "none" MUST be styled with a neutral badge
- **AND** risk level "low" MUST be styled with a green/success badge
- **AND** risk level "medium" MUST be styled with a yellow/warning badge
- **AND** risk level "high" MUST be styled with a red/error badge
- **AND** risk level "very_high" MUST be styled with a dark red/critical badge

#### Scenario: Entity details are expandable
- **GIVEN** the file has detected entities
- **WHEN** the user views the Extraction tab
- **THEN** the entity count MUST be displayed as a summary (e.g., "12 entities detected")
- **AND** clicking the entity count MUST expand a list showing entity types and their counts (e.g., "PERSON: 3, EMAIL: 5, PHONE_NUMBER: 4")

#### Scenario: Anonymization status is shown
- **GIVEN** the file has been anonymized via OpenRegister
- **WHEN** the Extraction tab renders
- **THEN** a badge MUST display "Anonymized" with the anonymization timestamp
- **AND** a link to the anonymized file copy MUST be provided if available

#### Scenario: Extract Now button triggers extraction
- **GIVEN** the file has not been extracted or extraction failed
- **WHEN** the user clicks the "Extract Now" button
- **THEN** a `POST /apps/openregister/api/files/{fileId}/extract` request MUST be sent
- **AND** the button MUST show a loading spinner during the request
- **AND** on success the tab MUST refresh to show the updated extraction status

### Requirement: Extraction Status API Endpoint
The system SHALL expose an authenticated API endpoint at `GET /api/files/{fileId}/extraction-status` that returns the text extraction and entity recognition status for a specific Nextcloud file.

#### Scenario: File with completed extraction
- **GIVEN** file ID 42 has been successfully extracted
- **WHEN** an authenticated user calls `GET /api/files/42/extraction-status`
- **THEN** the response MUST be HTTP 200 with JSON containing:
  - `fileId` (integer)
  - `extractionStatus` (string: "completed")
  - `chunkCount` (integer)
  - `entityCount` (integer)
  - `riskLevel` (string: "none"|"low"|"medium"|"high"|"very_high")
  - `extractedAt` (ISO 8601 timestamp or null)
  - `entities` (array of `{type, count}` objects)
  - `anonymized` (boolean)
  - `anonymizedAt` (ISO 8601 timestamp or null)
  - `anonymizedFileId` (integer or null)

#### Scenario: File with no extraction record
- **GIVEN** file ID 99 has never been processed by OpenRegister
- **WHEN** an authenticated user calls `GET /api/files/99/extraction-status`
- **THEN** the response MUST be HTTP 200 with JSON containing `extractionStatus: "none"` and all numeric fields set to 0

#### Scenario: Unauthenticated access is rejected
- **GIVEN** an unauthenticated client
- **WHEN** the client calls `GET /api/files/42/extraction-status`
- **THEN** the Nextcloud framework MUST return HTTP 401

### Requirement: Webpack Entry Point
The sidebar tabs MUST be built as a separate webpack entry point that produces a standalone JavaScript bundle. This bundle MUST NOT include the full OpenRegister app router, Pinia stores, or other app-specific dependencies -- only the minimal code needed for the two sidebar tab components.

#### Scenario: Separate entry point exists
- **GIVEN** the webpack configuration is inspected
- **WHEN** the `entry` object is checked
- **THEN** there MUST be an entry named `filesSidebar` pointing to `src/files-sidebar.js`
- **AND** the output filename MUST be `openregister-filesSidebar.js`

#### Scenario: Bundle size is minimal
- **GIVEN** the filesSidebar entry point is built
- **WHEN** the production bundle size is measured
- **THEN** the bundle SHOULD be under 50KB gzipped (excluding shared chunks)
- **AND** the bundle MUST NOT import the Vue Router, Pinia, or the main OpenRegister App.vue

#### Scenario: Bundle uses Nextcloud framework utilities
- **GIVEN** the sidebar tab components need to make API calls and generate URLs
- **WHEN** the components import dependencies
- **THEN** they MUST use `@nextcloud/axios` for HTTP requests
- **AND** they MUST use `@nextcloud/router` for URL generation
- **AND** they MUST use `@nextcloud/l10n` for translations

### Requirement: Script Loading via Event Listener
The backend MUST register an event listener that injects the sidebar tab script when the Files app renders its template. The listener MUST use the Nextcloud `BeforeTemplateRenderedEvent` to add the script at the correct time.

#### Scenario: Script is injected for Files app
- **GIVEN** the OpenRegister app is enabled
- **WHEN** a user navigates to the Files app
- **THEN** the `FilesSidebarListener` MUST handle the `BeforeTemplateRenderedEvent`
- **AND** it MUST call `\OCP\Util::addScript('openregister', 'openregister-filesSidebar')` to inject the bundle

#### Scenario: Listener is registered in Application::register
- **GIVEN** the `Application.php` boot process
- **WHEN** `register()` is called
- **THEN** the `FilesSidebarListener` MUST be registered for the `\OCA\Files\Event\LoadAdditionalScriptsEvent` event via `$context->registerEventListener()`

#### Scenario: Script is not loaded for other apps
- **GIVEN** a user navigates to the Calendar app
- **WHEN** the Calendar template is rendered
- **THEN** the `openregister-filesSidebar.js` script MUST NOT be loaded

### Requirement: Internationalization
All user-visible text in the sidebar tabs MUST support Dutch (nl) and English (en) translations using Nextcloud's `t()` function from `@nextcloud/l10n`.

#### Scenario: Tab names are translatable
- **GIVEN** the sidebar tabs are registered
- **WHEN** the Nextcloud UI language is set to Dutch
- **THEN** the Register Objects tab name MUST display "Registerobjecten"
- **AND** the Extraction tab name MUST display "Extractie"

#### Scenario: All UI text uses t() function
- **GIVEN** any user-visible string in the sidebar tab components
- **WHEN** the string is rendered
- **THEN** it MUST be wrapped in `t('openregister', '...')` for translation

### Requirement: Accessibility
The sidebar tabs and their content MUST comply with WCAG AA accessibility standards, consistent with the NL Design System requirements.

#### Scenario: Tab content uses semantic HTML
- **GIVEN** the Register Objects tab displays a list of objects
- **WHEN** a screen reader reads the content
- **THEN** the object list MUST use `<ul>` and `<li>` elements
- **AND** each list item MUST have an accessible label combining register name, schema name, and object title

#### Scenario: Interactive elements are keyboard accessible
- **GIVEN** the Extraction tab has an "Extract Now" button
- **WHEN** the user navigates via keyboard
- **THEN** the button MUST be focusable and activatable via Enter/Space keys
- **AND** the focus indicator MUST be visible

#### Scenario: Color is not the sole indicator
- **GIVEN** the risk level badges use color coding
- **WHEN** the risk level is displayed
- **THEN** the risk level text label MUST always be visible alongside the color
- **AND** the contrast ratio MUST meet WCAG AA minimum (4.5:1 for normal text)
