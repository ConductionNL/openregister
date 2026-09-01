## 1. One request-scoped cache

- [ ] 1.1 Wire `RequestScopedCache` into `SchemaMapper`, `RegisterMapper`, and `RenderObject`, replacing `$findCache`/`$registersCache`/`$schemasCache` (`RenderObject.php:275-318`). OR delete `RequestScopedCache` and document the mapper `findCache` as the single per-request memo.

## 2. Memoize the catalog scan

- [ ] 2.1 Cache `getAllMagicMapperTables()`/`getAllRegisterSchemaPairs()` (`MagicStatisticsHandler.php:146-183`) per request so the `information_schema` scan runs once.
- [ ] 2.2 In `MagicMapper::findBySchema()` (`:7351-7390`), reuse the memoized pairs (and ideally filter candidate tables in the catalog query itself, e.g. by schemaId-suffixed name pattern).

## 3. Grouped register statistics

- [ ] 3.1 Add `getStatisticsGroupedByRegister()` to `MagicStatisticsHandler` (mirroring `getStatisticsGroupedBySchema()`).
- [ ] 3.2 In `RegistersController::index()` (`:359-368`), replace the per-register `getStatistics()` loop with one grouped call for objects and one for audit logs.

## 4. Background-only name warmup

- [ ] 4.1 Gate `getAllObjectNames()`/`warmupNameCache()` (`CacheHandler.php:1263,1308-1387`) so the full-table warmup runs only from `NameCacheWarmupJob`, never auto-triggered in a request.

## 5. Verification

- [ ] 5.1 Query-count test: `GET /api/registers` with `@self.stats` runs one catalog scan and O(1) grouped COUNT queries, not O(registers × pairs).
- [ ] 5.2 Test: stats values are identical to pre-change.
- [ ] 5.3 Test: `warmupNameCache` is never reached synchronously from a controller.
- [ ] 5.4 CLI/long-process: memo does not leak across requests.
- [ ] 5.5 `composer check:strict` passes.

## Acceptance criteria

- Exactly one request-scoped entity cache (or the dead one removed).
- The `information_schema` catalog scan runs once per request, not per register.
- The registers-list stats use one grouped query set, not per-register loops.
- Full-table name warmup runs only in the background job.
