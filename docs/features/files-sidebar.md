# Files Sidebar Integration

## Standards

- **Nextcloud Files API** -- Tab registration and script injection via `OCA\Files` events

## Overview

Two sidebar tabs injected into the Nextcloud Files app, providing visibility into register objects linked to a file and the file's text extraction status. When a user selects a file in the Files app, these tabs appear in the sidebar showing relevant OpenRegister data.

## Key Capabilities

- **RegisterObjectsTab** -- Displays all register objects that reference a given file. Searches across all registers and schemas for objects containing the file's ID in their file attachments. Returns object metadata including register, schema, title, and direct links.
- **ExtractionTab** -- Shows the text extraction and anonymization status of a file: extraction state (`none`, `pending`, `completed`, `failed`), chunk count, entity count (NER), risk level, timestamps, and anonymization details.
- **Script Injection Listener** -- A PHP event listener registers the frontend JavaScript into the Files app context. The scripts render the two tabs using Nextcloud's sidebar tab API.
- **File-to-Object Search** -- The backend controller queries all register objects to find those associated with a specific Nextcloud file ID, enabling reverse lookup from file to business objects.

## API Endpoints

| Method | URL | Controller | Description |
|--------|-----|------------|-------------|
| GET | `/api/files/{fileId}/objects` | `fileSidebar#getObjectsForFile` | Get register objects linked to a file |
| GET | `/api/files/{fileId}/extraction-status` | `fileSidebar#getExtractionStatus` | Get extraction/anonymization status |

Both endpoints are registered in `appinfo/routes.php` (lines 547-549) and operational.

## API Test Results

- **GET /api/files/1/objects** -- Returns `{"success":true,"data":[]}` (no objects linked to file ID 1, which is expected for a system file).
- **GET /api/files/1/extraction-status** -- Returns extraction metadata with `extractionStatus: "none"`, confirming the endpoint is functional and returns the expected schema.

## Related Files

- `/lib/Controller/FileSidebarController.php` -- Backend controller with `getObjectsForFile()` and `getExtractionStatus()`
- `/appinfo/routes.php` -- Route definitions (lines 547-549)
- `/src/` -- Frontend JavaScript for sidebar tab rendering (injected via Files app listener)
