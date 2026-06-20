---
status: done
---

# admin-list-views Specification

## Purpose
Provides consistent interaction behaviour across the OpenRegister admin index views (agents, applications, configurations, entities, sources, templates, webhooks). Gives each list a select-all bulk-selection action, an optional detail-sidebar toggle, and a soft refresh of its data on mount so views render instantly from already-loaded store state.
## Requirements
### Requirement: Admin index views MUST expose a `toggleSelectAll(checked)` bulk-selection action @e2e exclude isolated Vue component contract (toggleSelectAll populates/clears the selection array keyed off store ids, no API/refresh) — covered by Vitest component unit test. The index views themselves render via manifest-shell.spec.ts

Every admin index view (`AgentsIndex`, `ApplicationsIndex`, `ConfigurationsIndex`, `EntitiesIndex`, `SourcesIndex`) MUST expose a `toggleSelectAll(checked: boolean)` method bound to the column-header checkbox. When invoked with `checked === true`, the method MUST populate the view's `selected*` array with every record `id` currently in the corresponding store's list (e.g. `selectedAgents = agentStore.agentList.map(a => a.id)`). When invoked with `checked === false`, the method MUST replace the array with an empty array. The method MUST NOT mutate the underlying store list, MUST NOT trigger a refresh, and MUST NOT call the API.

#### Scenario: Selecting all rows populates the selection set from the store list
- **GIVEN** the AgentsIndex view is mounted and `agentStore.agentList` contains 12 agents
- **AND** `selectedAgents` is currently `[]`
- **WHEN** the column-header checkbox is checked and emits `toggleSelectAll(true)`
- **THEN** `selectedAgents` MUST equal the 12 agent ids drawn from `agentStore.agentList.map(a => a.id)` in list order
- **AND** `agentStore.agentList` MUST be unchanged
- **AND** no API call MUST be made

#### Scenario: Clearing the header checkbox empties the selection set
- **GIVEN** `selectedConfigurations` contains 5 ids
- **WHEN** the column-header checkbox emits `toggleSelectAll(false)`
- **THEN** `selectedConfigurations` MUST be `[]`
- **AND** the underlying `configurationStore.configurationList` MUST be unchanged

#### Scenario: Selection set is keyed off the same id property the per-row toggle uses
- **GIVEN** `EntitiesIndex` exposes both `toggleSelectAll(checked)` and `toggleEntitySelection(entityId, checked)`
- **WHEN** the header checkbox emits `toggleSelectAll(true)` then a single per-row checkbox emits `toggleEntitySelection(7, false)`
- **THEN** the resulting `selectedEntities` array MUST contain every entity id from the list except `7`

### Requirement: Admin index views with a detail sidebar MUST expose a `toggleSidebar()` method bound to `NcAppContent.show-details` @e2e exclude isolated Vue component contract (toggleSidebar flips visibility, routes NcAppContent close event, independent of bulk selection) — covered by Vitest component unit test

Admin index views that include a detail sidebar (`EntitiesIndex`, `TemplatesIndex`, `WebhooksIndex`) MUST expose a `toggleSidebar()` method that flips the local `sidebarOpen` boolean. The view's `<NcAppContent>` element MUST bind `:show-details="sidebarOpen"` and `@update:showDetails="toggleSidebar"`, so that both an explicit user click on the sidebar-toggle button and Nextcloud's internal `update:showDetails` event route through the same method. `toggleSidebar()` MUST NOT fetch data and MUST NOT clear or mutate the selection set.

#### Scenario: Click on the sidebar-toggle button flips visibility
- **GIVEN** the `EntitiesIndex` view is mounted with `sidebarOpen = false`
- **WHEN** the user clicks the sidebar-toggle button bound to `@click="toggleSidebar"`
- **THEN** `sidebarOpen` MUST become `true`
- **AND** `<NcAppContent>` MUST receive `show-details="true"` and render the detail pane

#### Scenario: NcAppContent's internal close event routes back through toggleSidebar
- **GIVEN** the `WebhooksIndex` view has `sidebarOpen = true` and the detail pane visible
- **WHEN** `<NcAppContent>` emits `update:show-details` (e.g. user closes via the built-in close affordance)
- **THEN** the `@update:showDetails="toggleSidebar"` handler MUST fire
- **AND** `sidebarOpen` MUST become `false`

#### Scenario: Toggling the sidebar does not affect bulk selection
- **GIVEN** the `EntitiesIndex` view has `selectedEntities = [1, 2, 3]` and `sidebarOpen = false`
- **WHEN** the user toggles the sidebar twice (`toggleSidebar()` then `toggleSidebar()`)
- **THEN** `sidebarOpen` MUST be `false` again
- **AND** `selectedEntities` MUST still be `[1, 2, 3]`

### Requirement: Admin index views MUST soft-refresh their list on mount via the owning store @e2e exclude isolated Vue component contract (on-mount store soft-refresh dispatch, render-before-resolve) — covered by Vitest component unit test with mocked store

On `mounted()`, each admin index view MUST invoke its owning store's `refresh*List(null, true)` action with the soft-reload flag set to `true`. The soft-reload flag MUST suppress the loading spinner because the data is already hot-loaded at app startup; the second arg `true` is the contract for "do not toggle the loading UI." The mount-time refresh MUST NOT block render — it is a fire-and-forget async call, and the view MUST render with whatever list state the store currently holds.

#### Scenario: AgentsIndex triggers a soft refresh on mount
- **GIVEN** the AgentsIndex view is being mounted
- **WHEN** the `mounted()` lifecycle hook runs
- **THEN** `agentStore.refreshAgentList(null, true)` MUST be invoked exactly once
- **AND** the second argument `true` MUST suppress the loading spinner

#### Scenario: SourcesIndex renders before the store refresh resolves
- **GIVEN** the SourcesIndex view is mounted with `sourceStore.sourceList` containing prior cached data
- **WHEN** `sourceStore.refreshSourceList(null, true)` is in flight and has not yet resolved
- **THEN** the view MUST render the existing list without a loading overlay
- **AND** the list MUST update reactively once the refresh resolves

