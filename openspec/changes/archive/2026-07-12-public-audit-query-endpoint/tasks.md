## 1. Query service

- [x] 1.1 Create `lib/Service/AuditQueryService.php`: `query(array $filters, int $limit, int $offset): array` — queries the audit trail (all recorded audit entry objects from all apps/schemas) via ObjectService with filters (registerId, schemaId, objectId, app, timestampStart/End) and paging. Returns `['entries' => [...], 'total' => ?, 'limit' => ..., 'offset' => ...]`.
- [x] 1.2 Clamping: limit (1-200, default 50), offset, graceful handling of unconfigured audit schema.

## 2. Controller + routes

- [x] 2.1 Create `lib/Controller/AuditQueryController.php`: `query()` and `export()` actions (admin-only via the repo's established convention — no `@NoAdminRequired`/`#[NoAdminRequired]` attribute plus a defence-in-depth `requireAdmin()` body check, mirroring `AuditTrailController`; a literal `#[AdminRequired]` attribute does not exist in `nextcloud/ocp`); read filters from request; call AuditQueryService; return JSON (query) or CSV/JSON download (export).
- [x] 2.2 Routes: `GET /api/v2/audit` and `GET /api/v2/audit/export` (added a new "Audit Query (v2)" block in appinfo/routes.php next to the existing Audit Trails block; no other `/api/v2/*` routes exist yet in this app, so this establishes the prefix).

## 3. Export formatting

- [x] 3.1 `buildCsvFromAuditEntries(array $entries): string` — flatten each entry to a CSV row (id, registerId, schemaId, objectId, data→JSON, created, userId).
- [x] 3.2 Support `?format=json` returning raw entries array.

## 4. Tests

- [x] 4.1 PHPUnit `tests/Unit/Service/AuditQueryServiceTest.php`: query with filters, paging, clamping, unconfigured audit schema fallback.
- [x] 4.2 PHPUnit `tests/Unit/Controller/AuditQueryControllerTest.php`: admin allowed, non-admin 403, CSV content-type, JSON export shape.
- [ ] 4.3 e2e Playwright: deferred — out of scope for this implementation pass (PHPUnit coverage only was requested). Queries to cover when picked up: `GET /api/v2/audit?registerId=<app>&schemaId=<schema>&objectId=<uuid>` returns filtered/paged entries newest-first; non-admin session gets 403; `GET /api/v2/audit/export?format=csv` streams `text/csv` with `Content-Disposition: attachment` and the documented column order; `?format=json` returns the same entries as JSON.

## 5. Verify

- [x] 5.1 `vendor/bin/phpunit -c phpunit-unit-local.xml --filter AuditQuery` green (20/20; the one co-loaded failure, `SqlTypeMapperTest`, is a pre-existing Doctrine-DBAL-stub gap unrelated to this change). Full suite run same config: identical pre-existing baseline (14090 tests, 1223 errors/12 failures, all pre-existing environment gaps — missing `ext-zip`/`ext-xsl`-style stubs, AppHost DI factories, etc.), zero occurrences of `AuditQuery` anywhere in the failure/error output — no regressions. Note: `phpunit-unit.xml` (the full-NC-runtime bootstrap) requires the real Nextcloud container per `api-test-coverage.yml`'s documented recipe; `phpunit-unit-local.xml` is the repo's documented bootstrap for pure-mockist service/controller tests like these (see its own docblock) and was used instead after confirming the in-container path hits a pre-existing bootstrap ordering bug (`tests/bootstrap(-unit).php` stubs `class OC` unconditionally before conditionally requiring the real `lib/base.php`, which then fails to redeclare it) unrelated to this change.
- [ ] 5.2 `npx playwright test tests/e2e/audit-query.spec.js` — deferred with 4.3.
- [x] 5.3 PHPCS/PHPStan clean on new files (`lib/Service/AuditQueryService.php`, `lib/Controller/AuditQueryController.php`).
- [x] 5.4 `openspec validate public-audit-query-endpoint --type change --strict` passes (fixed a pre-existing "must contain SHALL or MUST" wording nit in the Query isolation requirement).

## Acceptance Criteria

- `GET /api/v2/audit` returns filtered audit entries (registerId, schemaId, objectId, app, timestampStart/End filters); paged; NC admin only.
- `GET /api/v2/audit/export` streams CSV (or JSON if `?format=json`).
- Clamping: limit 1-200.
- Query isolation: endpoint returns audit entries as-is, no data enrichment/leakage.
- Full test coverage (unit + e2e); no new failures.

## Quality Checklist

- SPDX + @spec tags on new PHP files.
- i18n keys English (if any UI messages).
- No new dependencies (use existing ObjectService API).
- Audit entry schema(s) documented (each app defines its own audit structure; OR index is schema-agnostic).
