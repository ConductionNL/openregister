---
status: done
retrofit: true
---

# Filter Sidebar Tabs

## Purpose

Provide a consistent filter-sidebar UX across OpenRegister's main list views (Entities, Webhooks, Dashboard, Deleted). Each filter sidebar is a Vue single-file component that owns a small set of filter controls (search input, register/schema pickers, status pickers, date ranges) and communicates its state either through `update:*` events to a parent list view (Entities, Webhooks) or through the global Pinia register/schema/deleted stores plus the router query string (Dashboard, Deleted).

This spec documents the observed behavior of the four sidebar components as retroactively introduced by the `retrofit-2026-05-24-files-sidebar-tabs` ghost change. Code already exists — requirements describe what the code does, not what it should do.

**Standards**: Vue 2 single-file components, Pinia stores, Vue Router, Nextcloud `@nextcloud/l10n` for i18n
**Cross-references**: list views consuming the sidebars live under `src/views/`
## Requirements
### Requirement: Debounced search input emits update:search after 500ms

Filter sidebars that own a free-text search field (currently `EntitiesSidebar` and `WebhooksSidebar`) SHALL implement `handleSearchInput(value)` which debounces keystrokes through a single `searchTimeout` handle and emits `update:search` to the parent component 500ms after the last keystroke. Each invocation MUST clear the previous timeout so that only the final value is emitted.

#### Rationale

Without debouncing every keystroke would trigger a network round-trip on the parent list view. 500ms is the project's standard search-input debounce interval (also used by the mail sidebar's link dialog).

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
@e2e exclude internal Vue component state — covered by unit tests
- **GIVEN** the sidebar component is mounted
- **WHEN** any debounced method runs
- **THEN** the timeout handle MUST be stored on `this.searchTimeout` (not a module-level variable)
- **AND** the same handle MUST be reused so that `clearTimeout` correctly cancels the pending emission

---

### Requirement: Register selection cascade resets dependent schema state

Filter sidebars that expose a register picker (currently `DashboardSideBar` and `DeletedSideBar`) SHALL implement `handleRegisterChange(register)` which sets the new register on `registerStore` via `setRegisterItem(option)` AND immediately clears the schema selection by calling `schemaStore.setSchemaItem(null)`. The cascade ensures stale schema state from a previous register does not leak into the new register's filter context.

#### Rationale

Schemas belong to a register; selecting a different register makes any previously selected schema meaningless. Clearing the schema in the same handler keeps the two stores consistent without requiring the parent list view to coordinate the reset.

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

---

### Requirement: Deleted sidebar serialises filter state into the route query

`DeletedSideBar` SHALL implement `applyFilters()` as a thin wrapper that delegates to `updateRouteQueryFromState()`. The route-query function MUST:

1. No-op when `this.$route.path` is not `/deleted` (to avoid mutating unrelated routes during teardown or hot-reload).
2. Build a fresh query object from the current sidebar state via `buildQueryFromState()` (including `register`, `schema`, `deletedBy`, `dateFrom`, `dateTo`).
3. Compare the new query to the current `$route.query` via `queriesEqual()` and skip the navigation if they match (prevents redundant router pushes that would re-trigger watchers).
4. Otherwise call `this.$router.replace({ path: this.$route.path, query: nextQuery })`.

#### Rationale

Using the route query as the canonical filter store makes the filter state shareable (URLs can be bookmarked / sent to colleagues) and survives page reloads. Using `replace` (not `push`) avoids polluting browser history with every filter tweak. The path guard and equality guard prevent reactive loops between the sidebar watchers and the route-watcher that re-applies query params on navigation.

#### Scenario: applyFilters writes filter state to the URL

- **GIVEN** the user is on `/deleted` with no query string
- **AND** the sidebar has register `5`, schema `12`, and `dateFrom = 2026-05-01` selected
- **WHEN** any filter change triggers `applyFilters()`
- **THEN** `updateRouteQueryFromState()` MUST call `$router.replace` with query `{ register: "5", schema: "12", dateFrom: "2026-05-01T00:00:00.000Z" }`

#### Scenario: applyFilters is a no-op outside /deleted
@e2e exclude internal component teardown guard — covered by unit tests
- **GIVEN** the user has navigated away to `/dashboard` but the DeletedSideBar component is still mounted (or being torn down)
- **WHEN** a residual watcher fires `applyFilters()`
- **THEN** `updateRouteQueryFromState()` MUST detect `this.$route.path !== '/deleted'` and return immediately
- **AND** no `$router.replace` call MUST be issued

#### Scenario: applyFilters skips redundant navigation
@e2e exclude internal router guard — covered by unit tests
- **GIVEN** the current `$route.query` already equals the query built from sidebar state
- **WHEN** `applyFilters()` runs (for example after `applyQueryParamsFromRoute` already synchronised both directions)
- **THEN** `queriesEqual` MUST return true
- **AND** `$router.replace` MUST NOT be called

---

### Requirement: Backend File Reverse-Lookup and Extraction Status @e2e exclude backend service — covered by PHPUnit

The system MUST provide a `FileSidebarService` that backs the OpenRegister tab in the
Nextcloud Files app sidebar. `getObjectsForFile(int $fileId): array` MUST find every
OpenRegister object that references a given Nextcloud file id by scanning the per-schema
magic tables of every register the current user can access (RBAC-respecting), returning
each match with its `uuid`, a derived `title`, and its register/schema identity. The scan
MUST skip registers with no schemas and magic tables that do not exist, and MUST tolerate
per-register/per-schema errors by continuing rather than failing the whole lookup.

`getExtractionStatus(int $fileId): array` MUST report the document-processing state for a
file: when no extraction chunks exist it MUST return a `none` status with zeroed counts;
otherwise it MUST return the chunk count, the extracted-at timestamp, the linked GDPR
entity count aggregated by entity type, an overall risk level, and the anonymization
status (whether anonymized, when, and the anonymized file id).

#### Scenario: Reverse-lookup finds referencing objects across accessible registers
- **GIVEN** a Nextcloud file referenced by objects in two schemas the user can access
- **WHEN** `getObjectsForFile()` is called
- **THEN** it MUST return both objects with their uuid, title, register, and schema
- **AND** registers the user cannot access MUST be excluded
- **AND** missing magic tables and per-schema errors MUST be skipped without failing the lookup

#### Scenario: Extraction status for an unprocessed file
- **GIVEN** a file with no extraction chunks
- **WHEN** `getExtractionStatus()` is called
- **THEN** it MUST return `extractionStatus: 'none'` with zeroed chunk, entity, and risk values

#### Scenario: Extraction status aggregates entities and anonymization
- **GIVEN** a file with extraction chunks and linked GDPR entity relations
- **WHEN** `getExtractionStatus()` is called
- **THEN** it MUST return the chunk count, extracted-at timestamp, entity counts grouped by type, and the anonymization status

