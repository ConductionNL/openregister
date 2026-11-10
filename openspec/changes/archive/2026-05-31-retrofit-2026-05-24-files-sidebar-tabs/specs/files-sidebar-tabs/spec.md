# files-sidebar-tabs — Spec Delta

## ADDED Requirements

### Requirement: Debounced search input emits update:search after 500ms

Filter sidebars that own a free-text search field (currently `EntitiesSidebar` and `WebhooksSidebar`) SHALL implement `handleSearchInput(value)` which debounces keystrokes through a single `searchTimeout` handle and emits `update:search` to the parent component 500ms after the last keystroke. Each invocation MUST clear the previous timeout so that only the final value is emitted.

#### Scenario: Single keystroke emits after 500ms

- **GIVEN** the user types one character `f` into the sidebar's search input
- **WHEN** `handleSearchInput("f")` fires
- **THEN** a `setTimeout` is registered with a 500ms delay
- **AND** after 500ms the parent receives `update:search` with payload `"f"`

#### Scenario: Rapid keystrokes only emit the final value

- **GIVEN** the user types `foo` quickly (within 500ms)
- **WHEN** `handleSearchInput` fires three times in succession (`f`, `fo`, `foo`)
- **THEN** the first two timeouts MUST be cleared by `clearTimeout(this.searchTimeout)` before the next is set
- **AND** only one `update:search` event MUST fire 500ms after the last keystroke, with payload `"foo"`

#### Scenario: Component owns a single search timeout handle

- **GIVEN** the sidebar component is mounted
- **WHEN** any debounced method runs
- **THEN** the timeout handle MUST be stored on `this.searchTimeout` (not a module-level variable)
- **AND** the same handle MUST be reused so that `clearTimeout` correctly cancels the pending emission

### Requirement: Register selection cascade resets dependent schema state

Filter sidebars that expose a register picker (currently `DashboardSideBar` and `DeletedSideBar`) SHALL implement `handleRegisterChange(register)` which sets the new register on `registerStore` via `setRegisterItem(option)` AND immediately clears the schema selection by calling `schemaStore.setSchemaItem(null)`. The cascade ensures stale schema state from a previous register does not leak into the new register's filter context.

#### Scenario: Switching register clears the active schema

- **GIVEN** the user has register `R1` and schema `S1` (which belongs to `R1`) selected
- **WHEN** the user picks register `R2` from the register picker and `handleRegisterChange(R2)` runs
- **THEN** `registerStore.setRegisterItem(R2)` MUST be called
- **AND** `schemaStore.setSchemaItem(null)` MUST be called in the same handler, before any other action

#### Scenario: Clearing the register also clears the schema

- **GIVEN** a register is currently selected
- **WHEN** the user clears the register selection (`handleRegisterChange(null)`)
- **THEN** `registerStore.setRegisterItem(null)` MUST be called
- **AND** `schemaStore.setSchemaItem(null)` MUST be called

#### Scenario: DeletedSideBar additionally re-applies filters after the cascade

- **GIVEN** the DeletedSideBar variant of `handleRegisterChange`
- **WHEN** the cascade completes
- **THEN** `this.applyFilters()` MUST be called so that the new register selection is immediately reflected in the route query string and the parent list

### Requirement: Deleted sidebar serialises filter state into the route query

`DeletedSideBar` SHALL implement `applyFilters()` as a thin wrapper that delegates to `updateRouteQueryFromState()`. The route-query function MUST:

1. No-op when `this.$route.path` is not `/deleted` (to avoid mutating unrelated routes during teardown or hot-reload).
2. Build a fresh query object from the current sidebar state via `buildQueryFromState()` (including `register`, `schema`, `deletedBy`, `dateFrom`, `dateTo`).
3. Compare the new query to the current `$route.query` via `queriesEqual()` and skip the navigation if they match (prevents redundant router pushes that would re-trigger watchers).
4. Otherwise call `this.$router.replace({ path: this.$route.path, query: nextQuery })`.

#### Scenario: applyFilters writes filter state to the URL

- **GIVEN** the user is on `/deleted` with no query string
- **AND** the sidebar has register `5`, schema `12`, and `dateFrom = 2026-05-01` selected
- **WHEN** any filter change triggers `applyFilters()`
- **THEN** `updateRouteQueryFromState()` MUST call `$router.replace` with query `{ register: "5", schema: "12", dateFrom: "2026-05-01T00:00:00.000Z" }`

#### Scenario: applyFilters is a no-op outside /deleted

- **GIVEN** the user has navigated away to `/dashboard` but the DeletedSideBar component is still mounted (or being torn down)
- **WHEN** a residual watcher fires `applyFilters()`
- **THEN** `updateRouteQueryFromState()` MUST detect `this.$route.path !== '/deleted'` and return immediately
- **AND** no `$router.replace` call MUST be issued

#### Scenario: applyFilters skips redundant navigation

- **GIVEN** the current `$route.query` already equals the query built from sidebar state
- **WHEN** `applyFilters()` runs (for example after `applyQueryParamsFromRoute` already synchronised both directions)
- **THEN** `queriesEqual` MUST return true
- **AND** `$router.replace` MUST NOT be called
