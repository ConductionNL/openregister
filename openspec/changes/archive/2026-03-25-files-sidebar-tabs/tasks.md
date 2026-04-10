# Tasks: Files Sidebar Tabs

## Phase 1: Backend Foundation

- [ ] Task 1: Create FilesSidebarListener event listener
  - **Spec ref:** Sidebar Tab Registration > Script is injected for Files app; Script Loading via Event Listener
  - **Description:** Create `lib/Listener/FilesSidebarListener.php` that implements `IEventListener` for `\OCA\Files\Event\LoadAdditionalScriptsEvent`. The handler calls `\OCP\Util::addScript('openregister', 'openregister-filesSidebar')`. Register the listener in `Application::registerEventListeners()`.
  - **Files:** `lib/Listener/FilesSidebarListener.php`, `lib/AppInfo/Application.php`
  - **Acceptance criteria:**
    - GIVEN the OpenRegister app is enabled WHEN a user opens the Files app THEN the `openregister-filesSidebar.js` script is injected
    - GIVEN a user opens the Calendar app THEN the script is NOT injected
    - The listener is registered in `Application::registerEventListeners()`

- [ ] Task 2: Create FileSidebarService with getObjectsForFile method
  - **Spec ref:** Objects-by-File API Endpoint > Search strategy for file references; Results respect RBAC permissions
  - **Description:** Create `lib/Service/FileSidebarService.php` with a `getObjectsForFile(int $fileId): array` method. The method must search across all registers and schemas for objects that reference the given file ID in their JSON properties. Use `MagicMapper` for the query. Results must respect RBAC -- only return objects from registers the current user has access to. Each result includes uuid, title (first string property or UUID), register (id + title), and schema (id + title).
  - **Files:** `lib/Service/FileSidebarService.php`
  - **Acceptance criteria:**
    - GIVEN file 42 is referenced in two objects across different registers WHEN getObjectsForFile(42) is called THEN both objects are returned with register/schema metadata
    - GIVEN file 99 is not referenced WHEN getObjectsForFile(99) is called THEN an empty array is returned
    - GIVEN a user without access to register 3 WHEN getObjectsForFile returns objects from register 3 THEN those objects are filtered out

- [ ] Task 3: Add getExtractionStatus method to FileSidebarService
  - **Spec ref:** Extraction Status API Endpoint
  - **Description:** Add `getExtractionStatus(int $fileId): array` to `FileSidebarService`. Aggregate data from `ChunkMapper` (chunk count), `EntityRelationMapper` (entity counts by type, risk level), and `FileMapper` (extraction status, timestamp). Return a structured array with all extraction metadata. If no extraction record exists, return a response with `extractionStatus: "none"` and zeros.
  - **Files:** `lib/Service/FileSidebarService.php`
  - **Acceptance criteria:**
    - GIVEN file 42 has been extracted with 15 chunks and 12 entities WHEN getExtractionStatus(42) is called THEN it returns complete status with counts and entity breakdown
    - GIVEN file 99 has never been extracted WHEN getExtractionStatus(99) is called THEN it returns extractionStatus "none" with all counts at 0

- [ ] Task 4: Create FileSidebarController with API routes
  - **Spec ref:** Objects-by-File API Endpoint; Extraction Status API Endpoint
  - **Description:** Create `lib/Controller/FileSidebarController.php` with two actions: `getObjectsForFile(int $fileId)` and `getExtractionStatus(int $fileId)`. Both delegate to `FileSidebarService`. Annotate with `@NoAdminRequired` and `@NoCSRFRequired` (NOT `@PublicPage`). Add routes in `appinfo/routes.php`: `GET /api/files/{fileId}/objects` and `GET /api/files/{fileId}/extraction-status`.
  - **Files:** `lib/Controller/FileSidebarController.php`, `appinfo/routes.php`
  - **Acceptance criteria:**
    - GIVEN an authenticated user WHEN GET /api/files/42/objects is called THEN it returns HTTP 200 with the objects array
    - GIVEN an unauthenticated client WHEN GET /api/files/42/objects is called THEN HTTP 401 is returned
    - GIVEN an authenticated user WHEN GET /api/files/42/extraction-status is called THEN it returns HTTP 200 with extraction data

## Phase 2: Frontend Implementation

- [ ] Task 5: Add filesSidebar webpack entry point
  - **Spec ref:** Webpack Entry Point
  - **Description:** Add a `filesSidebar` entry to `webpack.config.js` pointing to `src/files-sidebar.js` with output filename `openregister-filesSidebar.js`. The entry point must NOT import the main app router, Pinia, or App.vue.
  - **Files:** `webpack.config.js`
  - **Acceptance criteria:**
    - GIVEN the webpack config WHEN the entry object is inspected THEN a `filesSidebar` entry exists
    - GIVEN the entry point is built WHEN the bundle is inspected THEN it does NOT contain Vue Router or Pinia imports

- [ ] Task 6: Create files-sidebar.js entry point with tab registration
  - **Spec ref:** Sidebar Tab Registration
  - **Description:** Create `src/files-sidebar.js` that registers two `OCA.Files.Sidebar.Tab` instances on `DOMContentLoaded`. Each tab follows the `mount/update/destroy` lifecycle pattern used by core Nextcloud tabs (comments, versions). The Register Objects tab uses the `database-outline` MDI SVG icon, the Extraction tab uses `text-box-search-outline`. Tab names use `t()` for translation. Gracefully exit if `OCA.Files.Sidebar` is undefined.
  - **Files:** `src/files-sidebar.js`
  - **Acceptance criteria:**
    - GIVEN the Files app is loaded WHEN DOMContentLoaded fires THEN two tabs are registered
    - GIVEN OCA.Files.Sidebar is undefined WHEN the script runs THEN no errors are thrown
    - Tab ids are `openregister-objects` and `openregister-extraction`

- [ ] Task 7: Create RegisterObjectsTab Vue component
  - **Spec ref:** Register Objects Tab
  - **Description:** Create `src/components/files-sidebar/RegisterObjectsTab.vue`. The component accepts a `fileId` prop (or receives it via `update(fileInfo)`). On mount/update, fetch objects from `GET /apps/openregister/api/files/{fileId}/objects` via `@nextcloud/axios`. Display results in a semantic `<ul>` list with each item showing register name, schema name, and object title. Each item links to `/apps/openregister/registers/{registerId}/schemas/{schemaId}/objects/{objectUuid}`. Show NcLoadingIcon during load, NcEmptyContent for no results or errors.
  - **Files:** `src/components/files-sidebar/RegisterObjectsTab.vue`
  - **Acceptance criteria:**
    - GIVEN file 42 is referenced by 2 objects WHEN the tab renders THEN 2 list items are shown with register/schema context
    - GIVEN no objects reference the file WHEN the tab renders THEN NcEmptyContent with "No register objects reference this file" is shown
    - GIVEN the user clicks an object WHEN the link is activated THEN the browser navigates to the OpenRegister object detail page
    - The list uses `<ul>` and `<li>` elements for accessibility

- [ ] Task 8: Create ExtractionTab Vue component
  - **Spec ref:** Extraction & Metadata Tab
  - **Description:** Create `src/components/files-sidebar/ExtractionTab.vue`. On mount/update, fetch status from `GET /apps/openregister/api/files/{fileId}/extraction-status`. Display extraction status, chunk count, entity count (expandable to show per-type breakdown), risk level with color-coded badge (accessible -- text always shown alongside color), extraction timestamp, and anonymization status. Include an "Extract Now" button for unextracted or failed files that calls `POST /apps/openregister/api/files/{fileId}/extract`. Use CSS variables for badge colors (no hardcoded colors).
  - **Files:** `src/components/files-sidebar/ExtractionTab.vue`
  - **Acceptance criteria:**
    - GIVEN a completed extraction WHEN the tab renders THEN status, chunk count, entity count, risk level, and timestamp are shown
    - GIVEN no extraction exists WHEN the tab renders THEN "No extraction data available" and "Extract Now" button are shown
    - GIVEN the user clicks "Extract Now" WHEN the extraction succeeds THEN the tab refreshes with updated data
    - Risk level badges show text labels alongside colors (WCAG AA)
    - Entity count is clickable and expands to show per-type breakdown

## Phase 3: Integration & Quality

- [ ] Task 9: Add translations for sidebar tab strings
  - **Spec ref:** Internationalization
  - **Description:** Add all user-visible strings from the sidebar tab components to the OpenRegister translation files. Ensure both English and Dutch translations are provided for tab names, empty states, error messages, button labels, status labels, and risk level labels.
  - **Files:** `l10n/en.js`, `l10n/nl.js` (or the translation source files)
  - **Acceptance criteria:**
    - GIVEN the UI language is Dutch WHEN the Register Objects tab name is displayed THEN it shows "Registerobjecten"
    - GIVEN the UI language is Dutch WHEN the Extraction tab name is displayed THEN it shows "Extractie"
    - All user-visible strings in both tab components use `t('openregister', '...')`

- [ ] Task 10: Write PHPUnit tests for FileSidebarService and FileSidebarController
  - **Spec ref:** Objects-by-File API Endpoint; Extraction Status API Endpoint
  - **Description:** Write unit tests covering: objects-by-file lookup with results, empty results, RBAC filtering; extraction status with completed extraction, no extraction, and various risk levels. Mock `MagicMapper`, `ChunkMapper`, `EntityRelationMapper`, and `FileMapper`.
  - **Files:** `tests/Unit/Service/FileSidebarServiceTest.php`, `tests/Unit/Controller/FileSidebarControllerTest.php`
  - **Acceptance criteria:**
    - Test that getObjectsForFile returns correct structure with register/schema metadata
    - Test that getObjectsForFile returns empty array for unreferenced file
    - Test that getExtractionStatus returns "none" status for unprocessed file
    - Test that getExtractionStatus returns complete data for processed file
    - Test that controller endpoints return correct HTTP status codes

- [ ] Task 11: Verify integration with Files app and test edge cases
  - **Spec ref:** All requirements
  - **Description:** Manual integration testing: enable OpenRegister, open Files app, verify tabs appear in sidebar, test with files that have and don't have OpenRegister references, test extraction status display, test "Extract Now" button, verify keyboard navigation and screen reader compatibility, test that tabs don't appear outside Files app.
  - **Files:** (none -- manual testing)
  - **Acceptance criteria:**
    - Tabs appear in Files sidebar when OpenRegister is enabled
    - Tabs do NOT appear in other app sidebars
    - Register Objects tab shows correct data for files with object references
    - Extraction tab shows correct data for extracted files
    - "Extract Now" button works for unextracted files
    - All interactive elements are keyboard accessible
