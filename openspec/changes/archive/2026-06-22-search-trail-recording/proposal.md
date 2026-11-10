---
kind: code
---

## Why

The "Popular Search Terms" dashboard widget and the "Searches" KPI are permanently empty because search-trail *recording* was never wired up: `SearchQueryHandler::logSearchTrail()` exists but its body is a commented-out `// TODO` stub, `SearchTrailService` is not injected into the handler, and the method is never invoked from the search execution path. Reading infrastructure (`SearchTrailService`, `SearchTrailMapper`, popular-terms and stats aggregations) is already complete — only the write side is missing, so the analytics surface is dead.

## What Changes

- Inject `SearchTrailService` into `SearchQueryHandler` and implement `logSearchTrail()` to call `SearchTrailService::createSearchTrail(query, resultCount, totalResults, responseTime, executionType)` (replacing the TODO).
- Invoke recording from `ObjectService::searchObjectsPaginated()`, the controller-facing paginated search that covers both the SOLR/index path and the DB path, measuring response time from a `microtime(true)` captured at method entry.
- Add a configurable recording gate driven by a new `searchTrailRecordingMode` admin setting (`all` | `_search` | `none`, default `_search`): `none` records nothing, `_search` records only free-text searches (`_search` non-empty), `all` records every paginated search call. The existing `searchTrailsEnabled` boolean acts as a master switch — when it is `false`, the effective mode is `none`; otherwise the configured `searchTrailRecordingMode` applies.
- Persist `searchTrailRecordingMode` in the same `retention` appConfig blob: add it to the defaults in `ObjectRetentionHandler::getRetentionSettingsOnly()`, return it from the read path, and accept it on the update path so the admin GET/PATCH `/api/settings/retention` round-trips it.
- Expose a 3-option control for `searchTrailRecordingMode` in the retention admin UI so the mode can be changed without a code deploy.
- Keep recording failures non-fatal: a logging error logs a warning and the search still returns normally.

## Capabilities

### New Capabilities
- `search-trail-recording`: WHEN a paginated search executes and recording is enabled, the system records a search-trail entry (term, result count, total results, response time, execution type) according to the configurable `searchTrailRecordingMode` admin setting (`all` | `_search` | `none`, default `_search`): in `_search` mode only free-text searches are recorded, in `all` mode every paginated search is recorded, in `none` mode (or when the `searchTrailsEnabled` master switch is off) nothing is recorded; a recording failure never fails the search.

### Modified Capabilities
<!-- None. The read-side analytics (zoeken-filteren saved-searches-and-search-trails) and SearchTrailService/SearchTrailMapper contracts are unchanged; this change only supplies the missing write path that those already-specified surfaces consume. -->

## Impact

- `lib/Service/Object/SearchQueryHandler.php` — constructor (new `SearchTrailService` dependency), `logSearchTrail()` body, and effective-mode resolution.
- `lib/Service/ObjectService.php` — `searchObjectsPaginated()` gains entry-time timing and a mode-driven recording call before each return.
- `lib/Service/Settings/ObjectRetentionHandler.php` — adds the `searchTrailRecordingMode` default (`_search`) to the retention defaults block, returns it from `getRetentionSettingsOnly()`, and accepts it on the update path.
- `src/views/settings/sections/RetentionConfiguration.vue` — adds a 3-option control for `searchTrailRecordingMode`, wired through the existing retention GET/PATCH `/api/settings/retention`.
- No schema changes, no migrations, no seed data — `openregister_search_trails` table and `SearchTrail` entity already exist; the new setting persists in the existing `retention` appConfig blob.
- Restores the "Popular Search Terms" widget and "Searches" KPI on the OpenRegister dashboard (consumes existing `getPopularSearchTerms()` / `getSearchStatistics()`).
- Dependency-injection wiring resolves through Nextcloud's app container; `SearchTrailService` is already registered.
