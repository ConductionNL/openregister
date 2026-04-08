# Design: Files Sidebar Tabs

## Approach
Implement the Nextcloud Files sidebar tab integration using two parallel tracks: backend (PHP event listener + API endpoints) and frontend (webpack entry point + Vue tab components). The design follows the established pattern used by core Nextcloud apps like `comments` and `files_versions` for sidebar tab registration.

## Architecture Overview

### Backend Components

1. **`FilesSidebarListener`** -- An `IEventListener` that listens for `LoadAdditionalScriptsEvent` (from the `files` app) and injects the sidebar JavaScript bundle via `\OCP\Util::addScript()`. This is the standard Nextcloud pattern for loading scripts into the Files app context.

2. **`FileSidebarService`** -- A new service class that provides two methods:
   - `getObjectsForFile(int $fileId): array` -- Queries across all registers/schemas to find objects referencing the given file ID. Uses `MagicMapper` to search JSON object data for file ID references, respecting RBAC via the existing `MagicRbacHandler`.
   - `getExtractionStatus(int $fileId): array` -- Aggregates extraction data from `ChunkMapper`, `GdprEntityMapper`/`EntityRelationMapper`, and `FileMapper` to build a complete extraction status response.

3. **`FileSidebarController`** -- A controller exposing two API endpoints:
   - `GET /api/files/{fileId}/objects` -- Delegates to `FileSidebarService::getObjectsForFile()`
   - `GET /api/files/{fileId}/extraction-status` -- Delegates to `FileSidebarService::getExtractionStatus()`

### Frontend Components

4. **`src/files-sidebar.js`** -- New webpack entry point. Imports Vue, registers both sidebar tabs via `OCA.Files.Sidebar.registerTab()` on `DOMContentLoaded`. Each tab uses the standard `mount/update/destroy` lifecycle pattern. Does NOT import the main app router or Pinia stores.

5. **`src/components/files-sidebar/RegisterObjectsTab.vue`** -- Vue component for the Register Objects tab. Fetches objects via axios, displays them in a semantic `<ul>` list with register/schema context, links to the OpenRegister app.

6. **`src/components/files-sidebar/ExtractionTab.vue`** -- Vue component for the Extraction tab. Fetches extraction status, displays status badges, entity breakdown (expandable), risk level with accessible color coding, anonymization status, and an "Extract Now" action button.

## Files Affected

### New Files
- `lib/Listener/FilesSidebarListener.php` -- Event listener for script injection
- `lib/Service/FileSidebarService.php` -- Service for file-to-object lookup and extraction status
- `lib/Controller/FileSidebarController.php` -- API controller for sidebar data endpoints
- `src/files-sidebar.js` -- Webpack entry point for sidebar tabs
- `src/components/files-sidebar/RegisterObjectsTab.vue` -- Register Objects tab component
- `src/components/files-sidebar/ExtractionTab.vue` -- Extraction & Metadata tab component

### Modified Files
- `lib/AppInfo/Application.php` -- Register `FilesSidebarListener` for `LoadAdditionalScriptsEvent`
- `appinfo/routes.php` -- Add routes for `/api/files/{fileId}/objects` and `/api/files/{fileId}/extraction-status`
- `webpack.config.js` -- Add `filesSidebar` entry point

## API Design

### GET /api/files/{fileId}/objects

**Response (200):**
```json
{
  "success": true,
  "data": [
    {
      "uuid": "a1b2c3d4-...",
      "title": "Besluit 2024-001",
      "register": { "id": 1, "title": "Besluiten Register" },
      "schema": { "id": 5, "title": "Besluit" }
    }
  ]
}
```

### GET /api/files/{fileId}/extraction-status

**Response (200):**
```json
{
  "success": true,
  "data": {
    "fileId": 42,
    "extractionStatus": "completed",
    "chunkCount": 15,
    "entityCount": 12,
    "riskLevel": "medium",
    "extractedAt": "2026-03-20T14:30:00Z",
    "entities": [
      { "type": "PERSON", "count": 3 },
      { "type": "EMAIL", "count": 5 },
      { "type": "PHONE_NUMBER", "count": 4 }
    ],
    "anonymized": false,
    "anonymizedAt": null,
    "anonymizedFileId": null
  }
}
```

## Key Design Decisions

1. **Separate webpack entry point** rather than loading the full OpenRegister app bundle. The Files sidebar tabs need minimal dependencies (Vue, axios, l10n, router) and should not bloat the Files app with the entire OpenRegister frontend.

2. **`LoadAdditionalScriptsEvent`** rather than `BeforeTemplateRenderedEvent`. The `LoadAdditionalScriptsEvent` from the files app is the idiomatic Nextcloud way to inject scripts into the Files app. This is the same event used by `files_sharing`, `files_versions`, and `comments`.

3. **File-to-object lookup via JSON search** rather than a dedicated mapping table. OpenRegister already stores file references as file IDs within object JSON properties (format: `file`). The `FileSidebarService` will query the `MagicMapper` with a JSON contains search. If performance becomes an issue, a dedicated `file_object_relations` index table can be added later as an optimization.

4. **Two separate tabs** rather than a single combined tab. Records managers primarily care about "which objects use this file?" while privacy officers primarily care about "what PII is in this file?". Separate tabs keep each concern focused and avoid a cluttered single-tab layout.

5. **Vanilla Vue instances** (not Pinia stores) for tab state. Each tab is a self-contained Vue component that manages its own state via `data()`. This follows the pattern used by core Nextcloud sidebar tabs (comments, versions) and avoids unnecessary Pinia overhead.
