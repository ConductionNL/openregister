# zoeken-filteren — Spec Delta

## ADDED Requirements

### Requirement: Search-trail analytics dashboard

The search-trail sidebar (`SearchTrailSideBar.vue`) MUST render a read-only analytics dashboard over persisted `SearchTrail` data, sourced from `searchTrailStore`. The canonical `zoeken-filteren` spec covers search-trail *persistence*; this requirement covers the *analytics reporting* surface that the persistence-spec Notes section flags as unspecified. On mount the sidebar MUST load the trail list (`loadSearchTrailData`), aggregate statistics (`loadStatistics` → totals, averages, success rate, unique terms/users/organisations, per-session averages, and a `queryComplexity` distribution), popular terms (`loadPopularTerms`), register/schema usage (`loadRegisterSchemaStats`), user-agent usage (`loadUserAgentStats`), and period-bucketed activity (`loadActivityData` for the selected `hourly`/`daily`/`weekly`/`monthly` period). Each loader MUST degrade gracefully to safe empty/zero defaults on error. Display helpers MUST format the aggregates for the panel: `getComplexityPercentage(type)` returns the share of a complexity bucket, `formatActivityPeriod(period)` localises a bucket label per the selected period, `getRegisterSchemaName(stat)` resolves register/schema ids to titles, `getBrowserName(agent)` derives a browser label from the user-agent record, and `updateFilteredCount()` reflects the current list length. Changing the activity period MUST reload activity data and reflect the period in the route query.

#### Scenario: Mounting the sidebar loads every analytics dataset
- **GIVEN** the search-trail sidebar is mounted
- **WHEN** the `mounted()` hook runs
- **THEN** `loadSearchTrailData`, `loadStatistics`, `loadPopularTerms`, `loadRegisterSchemaStats`, `loadUserAgentStats`, and `loadActivityData` MUST each be invoked
- **AND** the filtered count MUST be initialised from the store list length

#### Scenario: A failing analytics loader degrades to safe defaults
- **GIVEN** `searchTrailStore.getStatistics()` rejects
- **WHEN** `loadStatistics()` handles the error
- **THEN** all statistic fields MUST be reset to zero defaults (totals `0`, `queryComplexity = { simple: 0, medium: 0, complex: 0 }`)
- **AND** no exception MUST propagate to the caller

#### Scenario: Changing the activity period reloads bucketed activity
- **GIVEN** the user selects a different activity period (e.g. `monthly`)
- **WHEN** `loadActivityData()` runs
- **THEN** `searchTrailStore.getActivity(period)` MUST be called with the selected period
- **AND** the selected period MUST be reflected in the `/search-trails` route query via `updateRouteQueryFromState()`

#### Scenario: Complexity percentage is computed against the bucket total
- **GIVEN** `queryComplexity = { simple: 3, medium: 1, complex: 0 }`
- **WHEN** `getComplexityPercentage('simple')` runs
- **THEN** it MUST return `75`
- **AND** when the total is `0` it MUST return `0` without dividing by zero
