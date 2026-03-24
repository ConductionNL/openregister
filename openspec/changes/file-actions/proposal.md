## Why

Users must navigate away from the Nextcloud Files app to perform app-specific actions on files — there is no way to right-click a file and trigger workflows from consuming apps like Procest, Pipelinq, or DocuDesk. The `action-registry` change collects these actions centrally, but they are not yet surfaced where users work with files. Registering contextual file actions in the Files app context menu eliminates unnecessary navigation and makes cross-app workflows instantly accessible.

## What Changes

- **New webpack entry point** (`src/files-actions.js`) that registers Nextcloud File Actions from the action registry
- **File action registration** using the NC 28+ `registerFileAction()` API from `@nextcloud/files`, dynamically populated from InitialState
- **Action execution engine** supporting two modes: URL-based navigation (with placeholder substitution for `{fileId}`, `{fileName}`, `{filePath}`, `{mimeType}`) and callback-based API calls (POST with file metadata)
- **Confirmation dialogs** for destructive actions before execution
- **Client-side filtering** by MIME type and user permissions to show only relevant actions per file
- **Submenu grouping** when more than 3 actions are registered — groups them under a single "Register Actions" menu entry
- **Event listener extension** on `LoadAdditionalScriptsEvent` to inject the actions bundle and InitialState data into the Files app
- **Toast notifications** for callback action success/error feedback via `@nextcloud/dialogs`

## Capabilities

### New Capabilities
- `file-actions`: Contextual file action registration in the Nextcloud Files app — covers action rendering, MIME/permission filtering, URL and callback execution, destructive action confirmation, and submenu grouping

### Modified Capabilities
<!-- No existing spec-level requirements are changing. The action-registry and files-sidebar-tabs changes are dependencies/complements but their specs remain unchanged. -->

## Impact

- **Frontend**: New webpack entry point `src/files-actions.js`; depends on `@nextcloud/files` (already a project dependency for files-sidebar-tabs)
- **Backend**: Extends the existing `LoadAdditionalScriptsEvent` listener to also load the file-actions bundle and inject action InitialState
- **Dependencies**: Requires the `action-registry` change to be implemented first (provides `ActionRegistryService` and InitialState injection)
- **Complements**: `files-sidebar-tabs` change — sidebar shows detailed action panels, context menu provides quick one-click access
- **Consuming apps**: Procest, Pipelinq, and DocuDesk register their actions via the action registry; those actions automatically appear in the file context menu without any changes to those apps
- **Performance**: Zero additional API calls — actions loaded from APCu-cached InitialState; all filtering is client-side
