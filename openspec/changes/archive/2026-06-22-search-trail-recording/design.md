## Context

Search-trail *reading* is fully built: `SearchTrailMapper::getPopularSearchTerms()` (lib/Db/SearchTrailMapper.php:337) and `getSearchStatistics()` (~:285) back the dashboard's "Popular Search Terms" widget and "Searches" KPI, and `SearchTrailService::createSearchTrail()` (lib/Service/SearchTrailService.php:122) is a working wrapper over `SearchTrailMapper::createSearchTrail()` (lib/Db/SearchTrailMapper.php:240). The mapper extracts the term from the query's `_search` key into the `search_term` column via `extractSearchParameters()` (lib/Db/SearchTrailMapper.php:896), and the popular-terms query groups on that non-empty column.

The *writing* side is an unimplemented stub:
- `SearchQueryHandler::logSearchTrail()` (lib/Service/Object/SearchQueryHandler.php:561) has its `createSearchTrail` call commented out as `// TODO`.
- `SearchTrailService` is not a constructor dependency of `SearchQueryHandler` (current ctor at :70 takes `ViewMapper, SchemaMapper, SettingsService, LoggerInterface, IRequest`).
- `logSearchTrail()` is never called from any execution path.
- `SearchQueryHandler::isSearchTrailsEnabled()` (:593) already works (reads `getRetentionSettingsOnly()['searchTrailsEnabled']`, default true).

`ObjectService` already injects `SearchQueryHandler` as `$this->searchQueryHandler` (lib/Service/ObjectService.php:256) and exposes the controller-facing entry point `searchObjectsPaginated()` (lib/Service/ObjectService.php:2406).

## Goals / Non-Goals

**Goals:**
- Persist a search-trail entry for every genuine text search so the existing dashboard widget/KPI populate.
- Capture both backends from one place: SOLR/index path and DB path of `searchObjectsPaginated()`.
- Never let trail recording change search behavior or latency-visible failure modes.

- Make the recording gate configurable from the admin pages (which searches get recorded) without a code deploy.

**Non-Goals:**
- No schema, migration, or seed-data changes (`openregister_search_trails` and `SearchTrail` already exist; the new setting lives in the existing `retention` appConfig blob).
- No change to the read/aggregation side or to `SearchTrailService` / `SearchTrailMapper` contracts.
- No new endpoints — the new setting reuses the existing retention GET/PATCH `/api/settings/retention`.

## Decisions

**1. Inject `SearchTrailService` into `SearchQueryHandler`.** Add it to the constructor (lib/Service/Object/SearchQueryHandler.php:70) as a `private readonly SearchTrailService`. Nextcloud's app container autowires it (already registered). Then `logSearchTrail()` (:561) replaces the TODO with a real `$this->searchTrailService->createSearchTrail(query: $_query, resultCount: $_resultCount, totalResults: $_totalResults, responseTime: $_executionTime, executionType: $_executionType)` call, keeping the `isSearchTrailsEnabled()` guard and the try/catch.

**2. Hook recording in `ObjectService::searchObjectsPaginated()`, not deeper.** This method is the single controller-facing paginated search and handles BOTH backends. It has TWO `return $result;` statements — the SOLR/index path (lib/Service/ObjectService.php:2524) and the DB path (:2590). Rather than one return, recording is invoked before each return (or via a single helper called at both points) so both backends are captured. The `$result` shape carries `total` and `results[]`, plus `@self.source` already set to `'index'` (SOLR) or `'database'`/`'magic_mapper'` (DB).

**3. Timing from method entry.** Capture `$startTime = microtime(true)` at the top of `searchObjectsPaginated()`; compute `responseTime = (microtime(true) - $startTime) * 1000` (milliseconds, matching `SearchTrailMapper::createSearchTrail`'s `responseTime` rounding to int) at each recording point.

**4. Configurable recording gate via a `searchTrailRecordingMode` admin setting.** The gate is no longer hardcoded to "record only when `_search` is non-empty"; it is driven by a new retention setting `searchTrailRecordingMode`, a string enum with three values and default `_search`:

- `none` — record nothing.
- `_search` — record only genuine free-text searches (`_search` present and non-empty in the post-view-merge `$query`). Views can inject `_search` (lib/Service/Object/SearchQueryHandler.php:356), so the gate is evaluated after `applyViewsToQuery`.
- `all` — record every paginated search call that flows through `searchObjectsPaginated()`, including ordinary list/pagination/filter calls.

*Persistence.* `searchTrailRecordingMode` is stored in the same `retention` appConfig JSON blob as the other retention keys. `ObjectRetentionHandler::getRetentionSettingsOnly()` (lib/Service/Settings/ObjectRetentionHandler.php, defaults block ~line 187-202) gains the default `'searchTrailRecordingMode' => '_search'`, returns the persisted value, and the update counterpart accepts it. No new endpoint — the admin GET/PATCH `/api/settings/retention` (`Settings\ConfigurationSettings#getRetentionSettings` / `updateRetentionSettings`) round-trips it.

*Reconciliation with the existing `searchTrailsEnabled` boolean (master switch, back-compat).* The two settings combine into one **effective mode**: when `searchTrailsEnabled === false`, the effective mode is `none` regardless of `searchTrailRecordingMode`; otherwise the effective mode is the configured `searchTrailRecordingMode` (default `_search`). This preserves the existing "master off → record nothing" behavior and makes the default — text-only recording — identical to the previously hardcoded gate.

*Where the resolution lives.* The effective-mode resolution lives in `SearchQueryHandler`, alongside the existing `isSearchTrailsEnabled()` read (lib/Service/Object/SearchQueryHandler.php:593): a method resolves `searchTrailsEnabled` + `searchTrailRecordingMode` from `getRetentionSettingsOnly()` into the effective mode. The recording helper in `ObjectService::searchObjectsPaginated()` consults this resolved mode: `none` → skip; `_search` → record only when `_search` is non-empty; `all` → record every call. This keeps the trail's `search_term` column meaningful (empty for non-search rows recorded under `all`, populated for `_search` rows) and keeps a single source of truth for the gate.

*Admin UI.* `src/views/settings/sections/RetentionConfiguration.vue` gains a 3-option control (NcSelect or radio group) for `searchTrailRecordingMode`, wired through the existing retention GET/PATCH so the choice persists. If NcSelect is used it carries a proper `inputLabel` per ADR-004 accessibility.

**5. `executionType` reflects the backend.** Pass `'index'` from the SOLR/index return path and `'database'` from the DB return path (derived from `$result['@self']['source']`), so the trail records which engine served the search. This is more informative than a constant `'sync'` and costs nothing. (Provisional — see DEFERRED.)

**6. Failures are swallowed.** `logSearchTrail()` keeps its try/catch and, on failure, logs a warning via the injected `LoggerInterface` and returns — the search result is unaffected. The recording call in `searchObjectsPaginated()` is itself best-effort (the handler already swallows), so no extra guard is needed at the call site.

**7. Result counts.** `resultCount` = count of `$result['results']` on the current page; `totalResults` = `$result['total']`. The whole `$query` array (system params included) is passed to `createSearchTrail`; `SearchTrailMapper` strips `_`-prefixed system params except those it explicitly reads (`_search`, `_page`, `_limit`, `_offset`, `_facets`).

## Risks / Trade-offs

- **Write per search.** Each gated search now does one INSERT. Acceptable under the default `_search` mode (only text searches record, not list/pagination), and `selfClearingEnabled` is false by default so no cascading cleanup runs on the hot path. Choosing `all` mode trades higher write volume (one INSERT per paginated call) for complete coverage — an explicit, admin-controlled decision.
- **Scope of capture.** Hooking `searchObjectsPaginated()` records searches that flow through the global paginated path. Searches that bypass it (if any per-register endpoint does) are out of scope for this change (see DEFERRED).
- **Double-counting risk.** Recording at both return points (rather than once after the branch) means care is needed so a single call records exactly once — mitigated by a single private helper invoked on each return, or guarded so the SOLR `return` is the only exit on that branch (it already is).
