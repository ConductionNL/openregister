# Files Sidebar Tabs

## Problem
When a user navigates to the Nextcloud Files app and selects a file, there is no way to see which OpenRegister objects reference that file, what metadata OpenRegister has extracted from it, or what its text extraction and entity recognition status is. Users must switch between the Files app and the OpenRegister app to correlate files with their register data. This context-switching breaks the workflow for records managers, privacy officers, and archivists who need to understand the relationship between a document and its structured data at a glance.

## Proposed Solution
Register custom sidebar tabs in the Nextcloud Files app sidebar that display OpenRegister-specific information for the selected file. The integration uses the standard `OCA.Files.Sidebar.Tab` API to add tabs that show:

1. **Register Objects Tab** -- Lists all OpenRegister objects that reference the selected file (via file properties in schemas), with links to navigate directly to those objects in OpenRegister.
2. **Extraction & Metadata Tab** -- Shows text extraction status, chunk count, detected entities (PII), risk level, and anonymization status for the selected file.

This gives users immediate context about how a file relates to OpenRegister data without leaving the Files app. The implementation requires a new webpack entry point that loads only in the Files app context (via `\OCP\Util::addScript`), a backend API endpoint to look up objects by file ID, and two Vue tab components.
