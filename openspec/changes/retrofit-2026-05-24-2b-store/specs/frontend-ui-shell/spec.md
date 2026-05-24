---
retrofit: true
---

# Frontend UI Shell

## Purpose

Describes the frontend state-machine that governs how the OpenRegister admin UI coordinates navigation, modals, dialogs, sidebar visibility, and cross-section admin-settings panel state. This capability covers the transient UI-shell concerns that have no backend-domain counterpart but are required so that only one modal/dialog is active at a time, so that long-running settings fetches (SOLR/RBAC/multi-tenancy/retention/cache) share loading flags across the settings panels, and so that view-shell preferences persist across route transitions.

All backend-domain state (objects, schemas, registers, conversations, etc.) lives in the per-domain stores annotated against their respective capability specs (see `chat-ai`, `rapportage-bi-export`, `register-i18n`, `object-lifecycle`, etc.). The UI shell stores are intentionally backend-free.

## ADDED Requirements

### Requirement: The UI MUST coordinate modal, dialog, sidebar, and selected-section state through a single navigation store

A Pinia `navigation` store MUST hold the currently-selected menu item, the currently-active modal slug, the currently-active dialog slug, optional transient transfer-data for hand-off between modal contexts, and a per-section sidebar collapsed/expanded map. The store MUST guarantee single-active-modal and single-active-dialog semantics (opening a second modal MUST close the first) so that two overlay surfaces cannot stack and trap focus.

#### Scenario: Default selection routes to the dashboard
- **GIVEN** the app loads without an explicit route
- **WHEN** the `navigation` store is initialized
- **THEN** the `selected` state MUST default to `'dashboard'` so the dashboard view is rendered

#### Scenario: Opening a modal supersedes the previous one
- **GIVEN** modal `A` is currently active in the navigation store
- **WHEN** a caller invokes `setModal('B')`
- **THEN** the store state MUST reflect `'B'` as the single active modal
- **AND** modal `A` MUST no longer be rendered

#### Scenario: Sidebar collapsed state is preserved per section
- **GIVEN** sections `registers`, `register`, `organisations`, `search`, `deleted`, `logs`, `searchTrail`, `auditTrail`, `chat` each have an independent collapsed flag
- **WHEN** `setSidebarState('logs', false)` is invoked
- **THEN** only the `logs` flag MUST flip; the other section flags MUST remain unchanged

#### Scenario: Transient transfer data is set and retrieved
- **GIVEN** a modal needs to pass an object handle to a downstream dialog
- **WHEN** the caller invokes `setTransferData({ id: 'abc-123' })` and the downstream dialog later calls `getTransferData()`
- **THEN** the downstream dialog MUST receive `{ id: 'abc-123' }`

### Requirement: The admin settings panel MUST coordinate cross-section loading and result state through a unified settings store

A Pinia `settings` store MUST aggregate the transient loading/saving flags and result state of the admin settings sections (SOLR configuration, RBAC, multi-tenancy, retention, cache management, statistics extraction, vector index). The store MUST expose distinct loading flags per long-running async operation (test connection, warm-up, setup, field comparison, field creation/fix) so that one section's slow request does not block the panel as a whole, and MUST surface user-visible toasts via `@nextcloud/dialogs` `showError`/`showSuccess` on the operation outcome.

#### Scenario: SOLR connection test exposes a distinct loading flag
- **GIVEN** the SOLR settings section is open
- **WHEN** the admin clicks "Test connection"
- **THEN** the `settings.testingConnection` flag MUST be set to `true` for the duration of the request
- **AND** other loading flags (`warmingUpSolr`, `settingUpSolr`, `loadingFields`, `loadingStats`) MUST remain unchanged

#### Scenario: Stats fetches are cached on the store
- **GIVEN** statistics for extraction or vector index have been previously loaded
- **WHEN** another settings section accesses `settings.extractionStats` or `settings.vectorStats`
- **THEN** the cached value MUST be returned without a refetch unless an explicit refresh action is invoked

#### Scenario: User-facing toasts are emitted on success and failure
- **GIVEN** the SOLR setup completes
- **WHEN** the operation returns `2xx`
- **THEN** `showSuccess` MUST be invoked with a translated message
- **AND** on a non-`2xx` response, `showError` MUST be invoked with the server-reported error message
