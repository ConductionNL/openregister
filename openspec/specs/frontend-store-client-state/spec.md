---
status: done
---

# frontend-store-client-state Specification

## Purpose

@e2e exclude Pinia store unit patterns — covered by unit tests, not Playwright
TBD - created by archiving change retrofit-2026-05-25-fe-store-1. Update Purpose after archive.
## Requirements
### Requirement: Coordination stores SHALL gate expensive preloads and refresh derived data when their dependency stores change

The dashboard store MUST set up Vue `watch`ers across the register and schema
stores so that selecting a different register or schema triggers a refresh of the
dashboard's registers, chart data, and statistics. It MUST expose a `preload()`
action that runs the parallel fetch set at most once, gated behind an
`isInitialized` flag plus an in-flight `loading` guard, and a `reset()` action
that returns all cached chart/statistic/register state to its initial shape.

#### Scenario: Selecting a register refreshes the dashboard

- **GIVEN** `init()` (or `setupDashboardStoreWatchers()`) has registered the watchers
- **WHEN** the active register or schema id changes
- **THEN** the store MUST re-run `fetchRegisters()`, `fetchAllChartData()`, and `fetchAllStatistics()`

#### Scenario: Preload runs at most once

- **GIVEN** `isInitialized` is `false` and no load is in flight
- **WHEN** `preload()` is called twice in succession
- **THEN** the parallel fetch set MUST execute only on the first call
- **AND** `isInitialized` MUST be set to `true` after the first successful run

#### Scenario: Reset returns cached state to its initial shape

- **WHEN** `reset()` is called
- **THEN** `registers`, `chartData`, `chartLoading`, `statisticsData`, `statisticsLoading`, `dateRange`, and `isInitialized` MUST all be returned to their initial values

### Requirement: Relation and translation stores SHALL optimistically mutate a per-key local cache and fall back to an empty state when an optional app is unavailable

The deck, emails, translations, and deleted stores MUST key their local cache so a
refresh of one object does not invalidate another. They MUST apply the mutation to
the cached entry immediately on a successful write (append on link, filter-out on
unlink/delete, patch-in-place on status change) rather than forcing a full refetch.
They MUST flip an "app-unavailable" flag and return an empty list on HTTP 501 so the
UI renders an empty state instead of an error.

#### Scenario: Unlink prunes the cached entry in place

- **GIVEN** the deck (or emails) cache for `register:schema:id` holds N links
- **WHEN** `unlink()` resolves for one of those links
- **THEN** the store MUST remove only that link from the cached array for that key
- **AND** MUST NOT refetch the list from the server

#### Scenario: Optional app absent yields an empty state

- **GIVEN** the Deck (or Mail) app is not installed
- **WHEN** `fetch()` receives an HTTP 501 response
- **THEN** the store MUST set its `deckUnavailable` / `mailUnavailable` flag to `true`
- **AND** MUST cache an empty array for that key and return it without throwing

#### Scenario: Status change patches the cached slot in place

- **GIVEN** the translations cache holds slots for an object uuid
- **WHEN** `setStatus(uuid, property, language, status)` resolves
- **THEN** only the matching `{property, language}` slot's `status` MUST be updated in the cache
- **AND** the remaining slots MUST be left untouched

#### Scenario: Deleting from a list prunes the cached list optimistically

- **GIVEN** the deleted-objects list holds an item with id `X`
- **WHEN** `restoreDeleted(X)` or `permanentlyDelete(X)` resolves
- **THEN** the store MUST remove item `X` from `deletedList` without refetching

### Requirement: The reports store SHALL memoise widget data by data-source identity

The reports store MUST build a stable cache key from a widget's data-source
descriptor (aggregation register/schema/name, graphql query prefix, or statistics
register/schema) and memoise the fetched data under that key, so that a dashboard
with two widgets sharing the same data source dispatches a single network call.
It MUST support a `forceRefresh` flag that bypasses the cache and a
`clearWidgetCache()` action that drops all memoised entries.

#### Scenario: Two widgets sharing a data source fetch once

- **GIVEN** a dashboard with two widgets whose data sources produce the same cache key
- **WHEN** `fetchDashboardData(dashboard)` runs without `forceRefresh`
- **THEN** the underlying data fetch for that source MUST execute only once
- **AND** both widgets MUST receive the memoised result

#### Scenario: Force refresh bypasses the cache

- **GIVEN** a widget's data is already memoised
- **WHEN** `fetchWidgetData(widget, true)` is called
- **THEN** the store MUST re-fetch from the server and overwrite the cached entry

### Requirement: Long register imports MUST be kept alive by a client-side heartbeat

The register store (`src/store/modules/register.js`) MUST provide a client-side
heartbeat that, while an import is in flight, periodically issues a lightweight
`GET /index.php/apps/openregister/api/heartbeat` request to keep the session warm,
because register imports can run far longer than a typical HTTP request and risk
gateway and session timeouts. The heartbeat MUST run on a fixed interval (default 15 seconds), MUST
track consecutive failures, MUST consider itself unhealthy only after 3 consecutive
failures, MUST NOT stop polling on transient failure, and MUST surface health
transitions through an optional status callback. The heartbeat MUST return a handle
exposing a `stop()` method that clears the interval, and `importRegister` MUST always
call `stop()` when the import settles (success or error).

#### Scenario: Heartbeat pings on its interval during an import

- **GIVEN** an import started via `importRegister(file)` for the selected register
- **WHEN** `startImportHeartbeat(15000, onStatusChange)` is invoked
- **THEN** the store SHALL issue `GET …/api/heartbeat` every 15 seconds with `Cache-Control: no-cache` and a 10-second per-request abort timeout
- **AND** the returned handle SHALL expose `stop()` and `getStatus()`

#### Scenario: Transient failures do not stop the heartbeat

- **GIVEN** a running heartbeat whose first two pings fail
- **WHEN** the failures occur
- **THEN** the heartbeat SHALL keep polling (the interval is not cleared)
- **AND** it SHALL remain "healthy" until a third consecutive failure flips it to unhealthy
- **AND** a recovered ping SHALL reset the failure count back to zero and report `healthy: true` via the status callback

#### Scenario: Import settlement stops the heartbeat

- **GIVEN** an in-flight import with an active heartbeat
- **WHEN** the import resolves or rejects
- **THEN** `importRegister` SHALL call the handle's `stop()` in its `finally` block
- **AND** `stop()` SHALL clear the polling interval so no further heartbeat requests are issued

### Requirement: A saved view's configuration MUST be applied onto the live search store

The views store (`src/store/modules/views.js`) MUST be able to project a saved view's
persisted `configuration` onto a live search-store instance so that selecting a saved
view restores the full search context. `applyView(view, searchStore)` MUST apply each
present configuration facet — selected registers, schemas, source, search terms, facet
filters, enabled facets, advanced filters, pagination, sorting, and columns — to the
provided search store via that store's setters, skip facets that are absent from the
configuration, and mark the applied view as active. A view without a `configuration`
MUST be rejected as a no-op (with a warning) rather than partially applied.

#### Scenario: Applying a complete saved view

- **GIVEN** a saved view whose `configuration` contains registers, schemas, source, searchTerms, facetFilters, enabledFacets, advancedFilters, pagination, sorting, and columns
- **WHEN** `applyView(view, searchStore)` is called
- **THEN** each corresponding `searchStore.set*` setter SHALL be invoked with the configuration value
- **AND** `setActiveView(view)` SHALL be called so the view becomes the active view

#### Scenario: Partial configuration applies only present facets

- **GIVEN** a saved view whose `configuration` only contains `registers` and `searchTerms`
- **WHEN** `applyView` is called
- **THEN** only `setSelectedRegisters` and `setSearchTerms` SHALL be invoked
- **AND** the other search-store facets SHALL be left untouched

#### Scenario: View without configuration is a guarded no-op

- **GIVEN** a `view` that is null or lacks a `configuration` object
- **WHEN** `applyView(view, searchStore)` is called
- **THEN** the method SHALL warn and return without mutating the search store or the active view

### Requirement: The live search-store state MUST be capturable into a saveable view configuration

Conversely, the views store MUST be able to snapshot the current search context into a
view object suitable for persistence. `createViewFromSearchState(searchStore, name,
description, isDefault, isPublic)` MUST return a plain object carrying the supplied
metadata (name, description, default/public flags) and a `configuration` block built
from the search store's current selectors — registers, schemas, source, search terms,
facet filters, enabled facets, advanced filters, pagination, sorting, and columns —
applying sensible defaults for any selector that is empty or unset (e.g. `source`
defaults to `'auto'`, pagination to `{ page: 1, limit: 20 }`).

#### Scenario: Capturing the current search state

- **GIVEN** a search store with selected registers, a source of `'graphql'`, and custom pagination
- **WHEN** `createViewFromSearchState(searchStore, 'My view', 'desc', false, true)` is called
- **THEN** the returned object SHALL carry `name: 'My view'`, `description: 'desc'`, `isDefault: false`, `isPublic: true`
- **AND** its `configuration` SHALL mirror the search store's current registers, schemas, source, searchTerms, facetFilters, enabledFacets, advancedFilters, pagination, sorting, and columns

#### Scenario: Empty selectors fall back to defaults

- **GIVEN** a freshly-initialised search store with no source and no pagination set
- **WHEN** `createViewFromSearchState(searchStore, 'Blank', '', false, false)` is called
- **THEN** the returned `configuration.source` SHALL be `'auto'`
- **AND** `configuration.pagination` SHALL be `{ page: 1, limit: 20 }`
- **AND** array selectors SHALL default to `[]` and object selectors to `{}`

### Requirement: Views are loaded via route-level code splitting

Application views SHALL be registered as async components so that a page load
only downloads and evaluates the code for the routes it uses. The initial bundle
SHALL NOT eagerly include every view and its heavy dependencies.

#### Scenario: Heavy view code loads on demand

- **WHEN** a user opens the default landing page
- **THEN** the code for unrelated heavy views (charts, editors, chat) is not part
  of the initial chunk
- **AND** it is fetched only when its route is visited

### Requirement: List and detail views avoid N+1 fetches

Views SHALL resolve collections of related resources via bulk endpoints or the
store cache, not one request per item in a loop.

#### Scenario: Opening a detail view issues bounded requests

- **WHEN** a user opens a view that needs many related resources (users, schemas,
  webhook stats)
- **THEN** the resources are fetched in a bounded number of requests, not one per
  item

### Requirement: Lists are server-paginated and mutations patch local state

List views SHALL request a bounded page from the server rather than fetching the
whole collection and slicing client-side. A single-row create/update/delete SHALL
patch local store state rather than refetching the entire list.

#### Scenario: Source list pages from the server

- **WHEN** the Sources list is opened
- **THEN** it requests a bounded page via `_limit`/`_page`
- **AND** deleting one source updates local state without refetching the whole list

