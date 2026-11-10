# Tasks — mdm-surface-api

## 1. QualityStatisticsService

- [x] 1.1 Add `lib/Service/Quality/QualityStatisticsService.php` computing, for `(register, schema)`: average `qualityScore`, per-status bucket counts (`good`/`fair`/`poor`), a 10-bucket score histogram over `[0,1]`, and total; read materialised fields via `ObjectService::findAll` (RBAC + tenant scoped) and reuse `QualityScorer::status()` with the schema's `x-openregister-quality` thresholds.
- [x] 1.2 Add a lowest-quality query path (ascending `qualityScore` sort, optional `qualityStatus` filter, pagination) served through `ObjectService::findAll`, returning at least object id, `qualityScore`, `qualityStatus`.
- [x] 1.3 Only if the aggregation cannot be expressed with existing `findAll`/`count` at acceptable cost, add a narrow scoped helper (e.g. `countByStatus`) to `ObjectService`; otherwise add nothing (ADR-011).

## 2. Controllers

- [x] 2.1 Add `lib/Controller/QualityController.php` with `stats()` (schema statistics) and an index/list method (lowest-quality list with filter/sort/pagination), delegating to `QualityStatisticsService`; `@NoAdminRequired` + `@NoCSRFRequired`, RBAC via the service.
- [x] 2.2 Add `lib/Controller/DuplicateController.php` with a read-only index method returning paginated candidate pairs from `DuplicateDetectionService::findDuplicates(register, schema, matchRules?, threshold?)`; pass through optional `threshold`; no merge/write; `@NoAdminRequired` + `@NoCSRFRequired`.

## 3. Routes

- [x] 3.1 Register all three controller methods in `appinfo/routes.php` under register/schema-scoped GET URLs (mirroring the aggregation route shape), each with the correct auth annotation; verify route↔method reachability (ADR-029) — no orphan methods, no orphan routes.

## 4. Tests

- [x] 4.1 Add PHPUnit tests for `QualityStatisticsService` (average, bucket counts summing to total, histogram, empty-set, threshold-driven bucketing) run the CI way (php:8.3-cli + OCP stubs, no live NC) against `ObjectService` doubles.
- [x] 4.2 Add controller tests asserting auth annotations, RBAC pass-through, pagination/filter/sort behaviour, and that the duplicate endpoint is side-effect-free.

## 5. Verification

- [x] 5.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the Hydra mechanical gates; fix any pre-existing issues touched.

## Acceptance Criteria

- Quality statistics for a scored `(register, schema)` return average, per-status counts summing to total, a histogram summing to total, and total count.
- Status buckets match `QualityScorer::status()` using the schema's declared thresholds.
- Lowest-quality listing returns objects ascending by `qualityScore`, supports `qualityStatus` filter, `qualityScore`/`qualityStatus` sort, and pagination.
- Duplicate endpoint returns candidate pairs (both ids, score, matched fields) descending by score, honours an optional `threshold`, paginates, and performs no merge or mutation.
- All object reads flow through `ObjectService` RBAC + multitenancy; anonymous access is rejected (endpoints are not `@PublicPage`).
- Every controller method has a matching `appinfo/routes.php` entry and an explicit auth annotation.
- No new schemas are added; no re-scoring occurs.

## Quality Checklist

- Reused `QualityScorer::status()` and `DuplicateDetectionService::findDuplicates` rather than reimplementing scoring/bucketing/dedup (ADR-011).
- Object reads go through `ObjectService::findAll`/`count` with RBAC + multitenancy on; scoping is never bypassed (ADR-022, ADR-045).
- Read-only surface: no writes, no merge, no survivorship, no sync — those are follow-on ADR-045 changes.
- SPDX + `@license`/`@copyright` docblock headers on every new PHP file (EUPL-1.2).
- Example UUIDs/tokens in any test fixture use obvious placeholders (nil UUID `00000000-0000-0000-0000-000000000000`, `YOUR_API_KEY_HERE`).
