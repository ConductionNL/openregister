## 1. Add the configurable recording-mode setting

- [x] 1.1 Add the default `'searchTrailRecordingMode' => '_search'` to the retention defaults block in `ObjectRetentionHandler::getRetentionSettingsOnly()` (lib/Service/Settings/ObjectRetentionHandler.php, ~line 187-202) so the read path returns it.
- [x] 1.2 Accept `searchTrailRecordingMode` (validate as one of `all` | `_search` | `none`) on the retention update path in `ObjectRetentionHandler` so GET/PATCH `/api/settings/retention` round-trips it.
- [x] 1.3 Add a 3-option control for `searchTrailRecordingMode` to `src/views/settings/sections/RetentionConfiguration.vue`, wired through the existing retention GET/PATCH; use NcSelect with a proper `inputLabel` (ADR-004) if a select is used.

## 2. Wire SearchTrailService and effective-mode resolution into the handler

- [x] 2.1 Add `private readonly SearchTrailService $searchTrailService` to the `SearchQueryHandler` constructor (lib/Service/Object/SearchQueryHandler.php:70) and import the class.
- [x] 2.2 Add an effective-mode resolver in `SearchQueryHandler` (next to `isSearchTrailsEnabled()` at :593) that reads `searchTrailsEnabled` + `searchTrailRecordingMode` from `getRetentionSettingsOnly()` and returns `none` when `searchTrailsEnabled` is false, else the configured mode (default `_search`).
- [x] 2.3 Replace the `// TODO` body of `logSearchTrail()` (lib/Service/Object/SearchQueryHandler.php:561) with a real `$this->searchTrailService->createSearchTrail(...)` call passing `_query`, `_resultCount`, `_totalResults`, `_executionTime`, `_executionType`.
- [x] 2.4 In the catch block of `logSearchTrail()`, log a warning via `$this->logger` (do not rethrow) so a recording failure never propagates.

## 3. Invoke mode-driven recording from the search path

- [x] 3.1 Capture `$startTime = microtime(true)` at the top of `ObjectService::searchObjectsPaginated()` (lib/Service/ObjectService.php:2406).
- [x] 3.2 Add a private helper on `ObjectService` that computes response time in ms, derives `executionType` from `$result['@self']['source']` (`'index'` vs `'database'`), and calls `$this->searchQueryHandler->logSearchTrail(...)` with the page result count and `$result['total']`.
- [x] 3.3 Gate the helper on the resolved effective mode: `none` → skip; `_search` → record only when `_search` is non-empty in the post-view-merge `$query`; `all` → record every call.
- [x] 3.4 Invoke the helper before the SOLR/index return (lib/Service/ObjectService.php:2524) and before the DB return (:2590) so both backends are captured exactly once per search.

## 4. Verify

- [x] 4.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix any new violations.
- [x] 4.2 In default `_search` mode, run real text searches via the objects search API (with `_search=<term>`) and confirm rows land in `openregister_search_trails` with the correct term, totals, response time, and execution type; run a plain list/pagination call and confirm NO new trail row.
- [x] 4.3 Switch `searchTrailRecordingMode` to `all` in the retention admin page and confirm a plain list/pagination call now records a row; switch to `none` and confirm nothing records.
- [x] 4.4 Load the OpenRegister dashboard and confirm the "Popular Search Terms" widget and "Searches" KPI populate from the recorded searches.

## Acceptance criteria

- `searchTrailRecordingMode` (`all` | `_search` | `none`, default `_search`) persists in the retention settings and round-trips through GET/PATCH `/api/settings/retention`.
- Switching the mode in the retention admin page changes which searches are recorded: `_search` records only text searches, `all` records every paginated call, `none` records nothing.
- In the default `_search` mode, a text search with a non-empty `_search` creates exactly one trail entry; the recorded term equals `_search`, total-results equals the search total, and execution type reflects index vs database; list/filter/pagination calls without `_search` create zero trail entries.
- The `searchTrailsEnabled` master switch overrides the mode: when false, the effective mode is `none` and nothing is recorded; an unreadable enabled setting defaults to enabled.
- A trail write error logs a warning and the search response is returned unchanged.
- The "Popular Search Terms" widget and "Searches" KPI populate after searches are recorded.

## Quality reminders

- No schema, migration, or seed-data changes — table and entity already exist; the new setting lives in the existing `retention` appConfig blob.
- Reuse existing infrastructure (SearchTrailService, SearchTrailMapper, ObjectRetentionHandler, the retention GET/PATCH endpoint, RetentionConfiguration.vue, isSearchTrailsEnabled) per ADR-011; do not duplicate or add a new endpoint.
- Verify no regressions for opencatalogi and softwarecatalog, which consume ObjectService search.
- Keep SPDX/license headers and `@spec` annotations on changed methods.
