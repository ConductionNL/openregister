---
retrofit: true
---

# Saved Search Views — Spec Delta

## Purpose

Lets OpenRegister users save the configuration of an object search — selected registers and schemas, free-text search terms, facet filters, and enabled facets — as a reusable, named **view** backed by `/api/views`. Views can be marked public or default, favorited per user, and re-applied to the live search from the search sidebar. This capability describes the observed frontend contract of `src/sidebars/search/SearchSideBar.vue` and the `viewsStore` it drives. It was retrofitted under ADR-003 on 2026-05-25 (cluster `fe-sidebars`); requirements capture observed behavior rather than original intent.

## ADDED Requirements

### Requirement: REQ-001 — Saved view lifecycle through the views store and /api/views

The search sidebar (`SearchSideBar.vue`) MUST expose a saved-view surface backed by `viewsStore` and the `/api/views` endpoints. A "view" persists a reusable query configuration — `registers`, `schemas`, `searchTerms`, `facetFilters`, and `enabledFacets` — under a user-supplied `name` and optional `description`, with `isPublic` and `isDefault` flags. The sidebar MUST support: listing available views (`viewOptions` / `selectedViewValue` computeds drawn from `viewsStore.getAllViews`), creating a view (`saveView` → `viewsStore.createView`), updating the active view (`updateActiveView` → `viewsStore.updateView`), activating a view (`handleViewChange` / `loadView` → `viewsStore.fetchView` then `applyViewConfiguration`), and deleting a view (`confirmDeleteView` / `confirmDeleteActiveView` stage `viewToDelete`; `handleDeleteClose` refreshes the list and clears the active view if it was deleted). Applying a view (`applyViewConfiguration`) MUST read the stored config (supporting both the new `query` and legacy `configuration` key), repopulate the sidebar's selection state, set it as the active view via `viewsStore.setActiveView`, and re-run the search when `canSearch` is satisfied. Only query parameters MUST be persisted — never transient UI state such as pagination, sorting, or visible columns.

#### Scenario: Saving the current search as a new view persists only query parameters
- **GIVEN** the user has selected registers, schemas, search terms, and facet filters and entered a view name
- **WHEN** `saveView()` runs
- **THEN** `viewsStore.createView` MUST be called with `{ name, description, isPublic, isDefault, configuration: { registers, schemas, searchTerms, facetFilters, enabledFacets } }`
- **AND** the new view MUST become the active view and the save form MUST be reset
- **AND** pagination, sorting, and visible-column state MUST NOT be included in the persisted configuration

#### Scenario: Activating a view re-applies its configuration to the live search
- **GIVEN** a saved view with a stored `query` (or legacy `configuration`) block
- **WHEN** the user selects it and `handleViewChange(option)` resolves `viewsStore.fetchView`
- **THEN** `applyViewConfiguration(view)` MUST repopulate `selectedRegisters`, `selectedSchemas`, `searchTerms`, `facetFilters`, and `enabledFacets` from the stored config
- **AND** the view MUST be set active via `viewsStore.setActiveView`
- **AND** when `canSearch` is true the search MUST be re-run via `performSearchWithFacets()`

#### Scenario: Deleting the active view clears the active selection
- **GIVEN** the active view is staged for deletion via `confirmDeleteActiveView`
- **WHEN** the delete dialog closes through `handleDeleteClose()`
- **THEN** `viewsStore.fetchViews` MUST refresh the list
- **AND** because the deleted view was active, `viewsStore.activeView` MUST be cleared and `activeViewName` reset to an empty string

### Requirement: REQ-002 — View favoriting, default-view auto-apply, and list filtering

The search sidebar MUST let a signed-in user favorite a view, automatically apply the configured default view on mount, and filter/sort the view list. `isFavorited(view)` MUST return true when the current user's uid appears in `view.favoredBy`. `toggleFavorite(view)` MUST add or remove the current user's uid from `favoredBy` via a `PATCH /api/views/{id}` request carrying only the updated `favoredBy` array, then refresh the list; when no user is signed in it MUST surface a notification and make no request. On mount, when `viewsStore.getDefaultView` returns a view, the sidebar MUST apply it via `applyViewConfiguration`. `filteredViews` MUST filter the list by a case-insensitive substring match against name and description, then sort favorited views first and alphabetically by name within each group.

#### Scenario: Toggling favorite patches only the favoredBy array
- **GIVEN** a signed-in user viewing a view they have not favorited
- **WHEN** `toggleFavorite(view)` runs
- **THEN** a `PATCH /api/views/{id}` request MUST be sent with a body containing only `favoredBy` extended by the current user's uid
- **AND** after a successful response `viewsStore.fetchViews` MUST refresh the list and a confirmation notification MUST be shown

#### Scenario: Favoriting requires an authenticated user
- **GIVEN** no current user is available from `OC.getCurrentUser()`
- **WHEN** `toggleFavorite(view)` runs
- **THEN** a "must be logged in" notification MUST be shown
- **AND** no PATCH request MUST be issued

#### Scenario: The default view is applied on mount
- **GIVEN** `viewsStore.getDefaultView` returns a view
- **WHEN** the sidebar's `mounted()` hook runs
- **THEN** `applyViewConfiguration(defaultView)` MUST be invoked

#### Scenario: The view list is filtered and favorite-sorted
- **GIVEN** the user types a query into the view search box and some views are favorited
- **WHEN** `filteredViews` recomputes
- **THEN** only views whose name or description contains the query (case-insensitive) MUST be returned
- **AND** favorited views MUST sort before non-favorited views, alphabetically by name within each group

## Non-Functional Requirements

- **Internationalisation (ADR-007):** The saved-view surface is user-facing — view names/descriptions are user content, while the surrounding UI labels and the "must be logged in" notification (REQ-002) MUST be available in both Dutch and English per the platform's i18n requirement.
- **Authorisation:** Favoriting (REQ-002) MUST require an authenticated user; an unauthenticated favorite attempt MUST notify and make no request rather than mutating shared view state.
- **State hygiene:** Persisted view configuration (REQ-001) MUST exclude transient UI state (pagination, sorting, visible columns) so a re-applied view restores only the query context.

## Acceptance Criteria

- [ ] `@spec` annotations on the `SearchSideBar.vue` view-management and favorite/filter members point at this change's `tasks.md` REQs.
- [ ] `openspec validate retrofit-2026-05-25-fe-sidebars --strict` passes.
- [ ] Coverage scan re-run after archive resolves the annotated view methods to REQ-001/REQ-002 (no longer uncovered).
