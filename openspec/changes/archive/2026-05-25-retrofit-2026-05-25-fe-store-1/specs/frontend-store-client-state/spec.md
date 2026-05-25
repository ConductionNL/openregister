---
retrofit: true
status: implemented
---

# Frontend Store Client State

## Purpose

Describes the client-side state-management contracts implemented by the
OpenRegister Pinia stores that are NOT visible in any backend capability: how the
Vue layer coordinates across stores, gates expensive preloads, optimistically
mutates a local cache around a successful write, invalidates that cache per
object key, falls back to an empty state when an optional Nextcloud app is
absent, and memoises remote data by data-source identity.

The REST contracts these stores call are specified by the corresponding backend
capabilities (`rapportage-bi-export`, `register-i18n`, `built-in-dashboards`,
`nextcloud-entity-relations`, `platform-administration-modals`). This spec covers
only the browser-side caching, coordination, and optimistic-update behavior layered
on top of those endpoints. Thin store actions that merely forward a request to one
of those endpoints, plain getters/setters, and local dialog-visibility toggles are
out of scope and carry `@spec exclude` annotations rather than requirements.

## ADDED Requirements

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

## Non-Functional Requirements

- **Resilience:** The HTTP 501 app-unavailable fallback (REQ-002) MUST render an empty state instead of an error so a missing optional Nextcloud app (Deck, Mail) never breaks the host view.
- **Cache correctness:** Per-key cache invalidation (REQ-002) and data-source memoisation (REQ-003) MUST be keyed so refreshing one object or widget never invalidates an unrelated one.
- **Internationalisation (ADR-007):** REQ-001..REQ-003 govern store-internal caching, coordination, and memoisation and carry no user-facing strings; locale does not affect their behaviour. Any user-facing copy lives in the consuming Vue components, which already meet the platform's Dutch + English requirement.

## Acceptance Criteria

- [ ] `@spec` annotations on the 20 in-scope store methods (`dashboard.js`, `deck.js`, `emails.js`, `translations.js`, `deleted.js`, `reports.js`) point at this change's `tasks.md` REQs.
- [ ] `openspec validate retrofit-2026-05-25-fe-store-1 --strict` passes.
- [ ] Coverage scan re-run after archive resolves the 20 annotated methods to REQ-001..REQ-003 and the 133 excluded methods to reasoned `@spec exclude` tags (no longer uncovered).
