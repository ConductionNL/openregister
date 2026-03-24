## Why

Currently, if multiple consuming apps (Procest, Pipelinq, DocuDesk, OpenCatalogi, ZaakAfhandelApp) want to offer contextual actions on files, mail messages, contacts, or other Nextcloud items, each app would need to independently implement Nextcloud integration points (sidebar tabs, file actions, etc.), leading to code duplication and inconsistent UX. We need a centralized action registry in OpenRegister -- following the same pattern as the existing `DeepLinkRegistrationEvent` -- where apps register their actions once, and OpenRegister handles rendering them across all Nextcloud integration surfaces.

## What Changes

- Create an `Action` value object with fields: `id`, `appId`, `label`, `description`, `icon`, `url` (template with placeholders like `{fileId}`, `{entityId}`, `{contactId}`), `callback` (API endpoint for inline actions), `contexts` (array: "file", "mail", "contact", "calendar", "profile", "global"), `filters` (mimeTypes, entityTypes, schemaIds, permissions), `order`, `destructive` flag
- Create `ActionRegistrationEvent` (emitted during boot in `Application.php`) with methods: `registerAction()`, `getActions()`, `getActionsForContext()`, `getActionsForContextWithFilters()`
- Create `ActionRegistryService` singleton that collects actions from the event and caches them in APCu (`ICacheFactory`) with TTL 300s; cache invalidation on `AppEnableEvent`/`AppDisableEvent`; public methods: `getActions()`, `getActionsForContext()`, `getActionsForFile()`, `getActionsForEntity()`, `invalidateCache()`
- Add API endpoints: `GET /api/actions`, `GET /api/actions/{context}`, `GET /api/actions/file/{fileId}`, `GET /api/actions/entity/{entityId}`
- Register an `InitialStateProvider` that injects the action registry into the frontend (key: "actions") so JS sidebar tabs access actions without API calls
- OpenRegister registers one example action ("View in Register") for contexts file, mail, contact

## Capabilities

### New Capabilities
- `action-registry`: Centralized event-based action registration system with APCu caching, API endpoints, InitialState injection, and filter evaluation for contextual actions across Nextcloud integration surfaces

### Modified Capabilities
- `event-driven-architecture`: Adds new `ActionRegistrationEvent` to the existing event dispatch system in `Application.php` boot phase

## Impact

- **New PHP classes**: `lib/Event/ActionRegistrationEvent.php`, `lib/Service/ActionRegistryService.php`, `lib/Controller/ActionsController.php`, `lib/Model/Action.php`
- **Modified**: `lib/AppInfo/Application.php` (dispatch `ActionRegistrationEvent` in boot, register cache invalidation listeners for `AppEnableEvent`/`AppDisableEvent`, register `InitialStateProvider`)
- **New routes**: 4 API endpoints in `appinfo/routes.php`
- **Caching**: Uses `ICacheFactory` (APCu) with prefix `openregister_actions`, TTL 300s
- **Pattern reference**: Follows existing `DeepLinkRegistrationEvent` pattern
- **No UI**: This change provides infrastructure only; all UI surfaces (sidebar tabs, file actions, etc.) are separate changes that depend on this one
- **No breaking changes**: Purely additive
- **Dependent changes**: `files-sidebar-tabs`, `file-actions`, `mail-sidebar`, `contacts-actions`, `workflow-operations`, `profile-actions` all consume this registry
