---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

There is a purpose-built `RequestScopedCache` service that is **never used**, and
meanwhile several hot paths repeat the same expensive per-request lookups —
including an `information_schema` catalog scan re-run once per register in a list
response (ADR-009 Rule 3).

1. **`RequestScopedCache` is dead code (HIGH).** `lib/Service/RequestScopedCache.php`
   (148 lines: `get/set/has/getMultiple/clear`) has zero references anywhere in
   `lib/` or `appinfo/` — never injected, never `use`d. Its docblock describes it
   as the shared per-request cache for "schemas, registers, objects,
   organisations". Instead, three uncoordinated ad-hoc caches exist for the same
   entities: `SchemaMapper::$findCache`, `RegisterMapper::$findCache`, and
   `RenderObject::$registersCache`/`$schemasCache` (`:275-318`).

2. **`@self.stats` on the registers list = O(R×P) COUNT + catalog scan per
   register (CRITICAL).** `RegistersController::index()` (`lib/Controller/RegistersController.php:359-368`)
   loops every register in the page calling `objectEntityMapper->getStatistics()`
   and `auditTrailMapper->getStatistics()`. `MagicStatisticsHandler::getStatistics()`
   (`lib/Db/MagicMapper/MagicStatisticsHandler.php:225-291`) does `find()+find()+COUNT`
   per register/schema pair, and `getAllRegisterSchemaPairs()` →
   `getAllMagicMapperTables()` (`:146-183`) re-runs an **uncached**
   `information_schema.tables LIKE 'oc_openregister_table_%'` scan on *every call*
   — i.e. once per register in the list. 20 registers × 30 pairs ≈ 600 COUNTs +
   20 catalog scans for one page.

3. **`findBySchema()` re-runs the full catalog scan and filters in PHP (MED).**
   `MagicMapper::findBySchema()` (`lib/Db/MagicMapper.php:7351-7390`) calls
   `getAllRegisterSchemaPairs()` (the uncached catalog scan) then `continue`s past
   non-matching schemas in PHP.

4. **`warmupNameCache()` can trigger a full-table load inside a request (MED).**
   `CacheHandler::warmupNameCache()` (`lib/Service/Object/CacheHandler.php:1308-1387`)
   does `findAll()` (no limit) + per-magic-table scans, auto-triggered whenever
   `empty($this->nameCache)` (`:1263`) — not only from the background job.

## What Changes

- Wire `RequestScopedCache` into the mappers/handlers it was built for
  (consolidating the three parallel caches into one), OR delete it and standardize
  on one documented per-request memo — but not three uncoordinated ones.
- Memoize `getAllMagicMapperTables()`/`getAllRegisterSchemaPairs()` per request so
  the catalog scan runs once, not once per register/`findBySchema` call.
- Add a `getStatisticsGroupedByRegister()` (mirroring the existing
  `getStatisticsGroupedBySchema()`) so `RegistersController::index()` makes one
  grouped call instead of one per register.
- Gate `getAllObjectNames()`/`warmupNameCache()` so the full-table warmup runs
  only from the background job, never auto-triggered synchronously in a request.

## Impact

- Affected: `lib/Service/RequestScopedCache.php`, `lib/Db/SchemaMapper.php`,
  `lib/Db/RegisterMapper.php`, `lib/Service/Object/RenderObject.php`,
  `lib/Db/MagicMapper/MagicStatisticsHandler.php`, `lib/Db/MagicMapper.php`,
  `lib/Controller/RegistersController.php`, `lib/Service/Object/CacheHandler.php`.
- Pure performance; response shapes unchanged (stats values identical, just
  computed once).
- Risk: request-scoped memo must be cleared between requests (it is per-request by
  construction); ensure no cross-request leakage in long-running CLI processes.
