# Tasks — Time-bucket aggregation enhancements

## 1. Backend — MySQL / SQLite native bucketing (#1609)

- [x] 1.1 Detect the active database platform inside `AggregationRunner::tryNativeAggregation()`. Existing code branches on `PostgreSQL`; extend the branch to also match `MySQL` and `SQLite` platform class names from `OCP\IDBConnection::getDatabasePlatform()`. Non-matching platforms still fall through to the PHP path.
- [x] 1.2 Extract the per-gap format-string lookup tables for MySQL (`DATE_FORMAT`) and SQLite (`strftime`) into private static helper methods (`mysqlBucketExpression()`, `sqliteBucketExpression()`). Each returns the full SQL expression `<func>(<col>, '<format>') AS bucket` or the `CASE` form for `quarter` / week-Monday.
- [x] 1.3 Wire the helpers into the `if ($dateBucket !== null)` branch of `tryNativeAggregation()`. The `whereSql` / bindings construction stays unchanged; only the bucket expression and the identifier-quoting (backticks on MySQL vs double-quotes on Postgres/SQLite) differ.
- [x] 1.4 Surface the platform name in the response `backend` field. The dispatch site already wraps `tryNativeAggregation()` return with `'backend' => 'postgres'`; extend it to return `'mysql'` or `'sqlite'` when the matching platform served the query. Use a small `detectDatabasePlatform()` private helper that returns the lowercased platform short name.
- [x] 1.5 The `coerceBucketKey()` helper continues to normalize keys defensively. MySQL/SQLite emit the canonical `Y-m-d\TH:i:s\Z` directly, so `coerceBucketKey()` is a no-op on those keys — verified by passing the emitted format strings through `strtotime` round-trip in the unit test.

## 2. Backend — Ad-hoc cache (#1610)

- [x] 2.1 Add `AggregationQuery::toArray(): array` returning `['metric' => $metric, 'field' => $field, 'filter' => <ksorted>, 'groupBy' => $groupBy, 'dateBucket' => $dateBucket]`. Filter sub-arrays are ksort-sorted recursively so two structurally-equivalent filters hash to the same value.
- [x] 2.2 Add `AggregationCache::getAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query): ?array` and the matching `setAdhoc(...)` writer. Both wrap the existing `get()`/`set()` with the name slot computed as `'adhoc:'.sha1(json_encode($query->toArray()))`. Filter content goes through the existing `filter` parameter for the inner key derivation.
- [x] 2.3 Wire `AggregationRunner::runAdhoc()` to call `cache->getAdhoc()` on entry: cache hit returns the stored envelope with `cached: true`. Cache miss falls through to the existing native-or-fallback dispatch, then writes the envelope back via `setAdhoc()` (with `cached: false`, which is rewritten to `cached: true` on the next hit).
- [x] 2.4 No changes to `AggregationCacheInvalidationListener` — the existing global `ICache::clear()` on object lifecycle events covers ad-hoc entries because they share the cache instance. Document this in the design doc and the cache class doc-block.

## 3. Tests

- [x] 3.1 `tests/Unit/Service/Aggregation/AggregationQueryTest.php` — add cases: `toArray()` round-trips through every field; `toArray()` is stable under filter-key reordering (`{a, b}` and `{b, a}` produce identical SHA-1 hashes); `toArray()` includes `dateBucket` when set; `toArray()` returns null for missing optional fields.
- [x] 3.2 `tests/Unit/Service/Aggregation/AggregationCacheTest.php` — add cases: `getAdhoc()` returns null on miss; `setAdhoc()` then `getAdhoc()` round-trips a result envelope; ad-hoc and named cache entries don't collide (a `set()` with `name='foo'` and a `setAdhoc()` with a query whose hash equals `'foo'` are independent because of the `'adhoc:'` prefix); `evictForSchema()` removes both kinds of entries (via the underlying `ICache::clear()`).
- [x] 3.3 `tests/Unit/Service/Aggregation/AggregationRunnerNativeBucketTest.php` — new file. Use `MySQLPlatform` / `SqlitePlatform` / `PostgreSQLPlatform` mocks for the `IDBConnection::getDatabasePlatform()` return value; assert the emitted SQL contains `DATE_FORMAT(\`<field>\`, '%Y-%m-%dT00:00:00Z')` for MySQL `gap=day`; verify backtick identifier quoting; verify backend annotation flips per platform; pin the `%i` vs `%M` minute placeholder divergence between MySQL and SQLite.
- [x] 3.4 (merged into 3.3 — single file covers all three native platforms + unknown-platform fallthrough)
- [x] 3.5 `tests/Unit/Service/Aggregation/AggregationRunnerAdhocCacheTest.php` — new file. Test: cache hit flips `cached` flag and skips the underlying dispatch; cache miss populates the cache with the same envelope; the resolved query reaches both `getAdhoc()` and `setAdhoc()` callbacks unchanged.
- [x] 3.6 Run the full `Unit Tests` suite — stays green (113 aggregation tests pass).

## 4. Quality gates

- [x] 4.1 PHPCS strict — clean on all touched files (`./vendor/bin/phpcs --standard=phpcs.xml lib/Service/Aggregation/`).
- [x] 4.2 PHPMD strict — clean on all touched files (targeted `@SuppressWarnings` on `TooManyMethods` at class level, `ExcessiveMethodLength`/`StaticAccess` on `runAdhoc()`, `ElseExpression` on `tryNativeAggregation()` to cover platform branches).
- [x] 4.3 Psalm strict — `No errors found!` on touched files.
- [x] 4.4 PHPStan level 8 — clean on touched files.
- [x] 4.5 PHPUnit — full `Unit Tests` suite green inside the dev container.

## 5. Documentation

- [x] 5.1 `docs/technical/aggregation-api.md` — backend matrix rewritten as a per-engine table (`postgres` / `mysql` / `sqlite` / `php-fallback`). Added a "Cache" section documenting the 60 s TTL, the read-through pattern, the key-stability semantics, the RBAC scoping, the lifecycle-event invalidation, and the stampede-tolerance position. Performance-notes section updated to drop the "Postgres-only native" caveat.
- [x] 5.2 `openspec/platform-capabilities.md` — aggregations row updated: native fast path now covers Postgres/MySQL/SQLite; ad-hoc cache + invalidation listener documented.
- [x] 5.3 `design.md` cross-linked from the proposal so the next pickup of #1606/#1607/#1608 finds the deferred-design notes via the breadcrumb trail.
