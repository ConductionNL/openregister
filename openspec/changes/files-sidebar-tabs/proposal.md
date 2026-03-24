## Why

When users browse files in Nextcloud, there is no way to see which OpenRegister entities (persons, organizations, IBANs) were detected in a file, which register objects reference the file, or what actions consuming apps (Procest, Pipelinq, DocuDesk) offer for that file. All this data already exists in OpenRegister but is invisible from the Files app. Surfacing it in the Files sidebar eliminates context-switching and makes OpenRegister data discoverable where users already work.

## What Changes

- **New sidebar tabs**: Three new tabs added to the Nextcloud Files app sidebar -- "Entities", "Objects", and "Actions" -- each showing OpenRegister data linked to the currently selected file
- **New event listener**: `LoadFileSidebarListener` listens to `OCA\Files\Event\LoadAdditionalScriptsEvent` to inject the sidebar JavaScript bundle
- **New webpack entry point**: `src/files-sidebar.js` registers the three sidebar tabs using the Nextcloud Files sidebar API
- **New API endpoints**: `GET /api/files/{fileId}/entities` and `GET /api/files/{fileId}/objects` with file access permission checks via `IRootFolder`
- **New mapper method**: `ObjectMapper::findObjectsByFileId()` to query objects linked to a file by folder node ID or `files` JSON field
- **Entities tab**: Shows entity cards with type icon, value, confidence score, and detection method badge; click-through to entity detail or search
- **Objects tab**: Shows object cards with title, schema/register subtitle, and click-through via the DeepLink registry
- **Actions tab**: Shows action cards from all registered apps (via `action-registry`), grouped by app, with URL navigation or callback POST execution; destructive actions get confirmation dialogs
- **Performance**: Lazy tab loading (fetch only when tab opened), 300ms debounce on file selection changes, APCu-cached action data via InitialState

## Capabilities

### New Capabilities
- `files-sidebar-tabs`: Files app sidebar integration -- tab registration, entity/object/action display, API endpoints, caching, and styling for the three sidebar tabs

### Modified Capabilities
- `deep-link-registry`: Click-through from Objects tab uses the DeepLink registry to generate URLs to consuming apps; no spec-level requirement change, only consumption

## Impact

- **PHP**: New `LoadFileSidebarListener`, new `FileController` (or extended) with two endpoints, new `ObjectMapper::findObjectsByFileId()` method
- **JavaScript/Vue**: New webpack entry point `files-sidebar.js`, three new Vue components in `src/components/FilesSidebar/`
- **Dependencies**: Actions tab depends on the `action-registry` change; Entities and Objects tabs are independent
- **Existing code**: Leverages `EntityRelationMapper::findEntitiesForFile()` (already exists), `FileMapper`, `ObjectEntity` fields (`files`, `folder`)
- **Build**: `webpack.config.js` needs a new entry point
- **Registration**: `Application.php` needs the new event listener registered
- **Accessibility**: All tabs must be WCAG AA compliant with keyboard navigation and screen reader labels
- **Theming**: Must use CSS variables for NL Design System compatibility (no hardcoded colors)
