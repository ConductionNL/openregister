# Aggregation API

OpenRegister's aggregation API runs time-bucket and scalar aggregation queries
against the per-schema magic tables.

## Backend matrix

| Backend       | Trigger                                        | Annotation            |
|---------------|------------------------------------------------|-----------------------|
| `postgres`    | Active database is PostgreSQL                  | `backend: "postgres"` |
| `mysql`       | Active database is MySQL or MariaDB            | `backend: "mysql"`    |
| `sqlite`      | Active database is SQLite                      | `backend: "sqlite"`   |
| `php-fallback`| Database is none of the above                  | `backend: "php-fallback"` |

All native paths produce the same ISO-8601-UTC bucket key format
(`Y-m-d\TH:i:s\Z`) that `coerceBucketKey()` normalises defensively.
The PHP fallback produces the same format via `DateTimeImmutable::format()`.

### PostgreSQL

Uses `date_trunc('gap', "field")::text` with double-quoted identifiers.

### MySQL / MariaDB

Uses `DATE_FORMAT(\`field\`, '<format>')` with backtick-quoted identifiers.

Format string mapping:

| Gap     | MySQL DATE_FORMAT string              |
|---------|---------------------------------------|
| minute  | `%Y-%m-%dT%H:%i:00Z` (note: `%i` not `%M`) |
| hour    | `%Y-%m-%dT%H:00:00Z`                 |
| day     | `%Y-%m-%dT00:00:00Z`                 |
| month   | `%Y-%m-01T00:00:00Z`                 |
| year    | `%Y-01-01T00:00:00Z`                 |
| week    | `DATE_FORMAT(field - INTERVAL ((DAYOFWEEK(field) + 5) %% 7) DAY, ...)` |
| quarter | `CONCAT(YEAR(field), '-', LPAD(((QUARTER(field) - 1) * 3 + 1), 2, '0'), '-01T00:00:00Z')` |

> **Note:** MySQL `%i` is the minute placeholder; `%M` is the full month name.
> Using `%M` where `%i` is intended is a common porting error from strftime.

### SQLite

Uses `strftime('<format>', "field")` with double-quoted identifiers.

Format string mapping:

| Gap     | SQLite strftime string               |
|---------|--------------------------------------|
| minute  | `%Y-%m-%dT%H:%M:00Z` (note: `%M` not `%i`) |
| hour    | `%Y-%m-%dT%H:00:00Z`                |
| day     | `%Y-%m-%dT00:00:00Z`                |
| month   | `%Y-%m-01T00:00:00Z`                |
| year    | `%Y-01-01T00:00:00Z`                |
| week    | `strftime('...', field, 'weekday 0', '-6 days')` |
| quarter | `CASE WHEN strftime('%m', field) IN ('01','02','03') THEN ... END` |

> **Note:** SQLite `%M` is the minute placeholder (opposite of MySQL's `%i`).

## Cache

The aggregation cache uses Nextcloud's distributed cache factory under the
`openregister_aggregations` namespace.

### TTL

60 seconds (`AggregationCache::TTL`).  All entries — named and ad-hoc — share
this TTL.

### Cache key derivation

**Named aggregations** (annotation-based):
```
agg:{registerSlug}:{schemaSlug}:{annotationName}:{filterHash}:{rbacHash}
```

**Ad-hoc aggregations** (runtime queries):
```
agg:{registerSlug}:{schemaSlug}:adhoc:{sha1(query.toArray())}:{filterHash}:{rbacHash}
```

The `adhoc:` prefix keeps ad-hoc entries visually distinct from named entries
in cache dumps.

`AggregationQuery::toArray()` ksort-sorts filter sub-arrays recursively so two
structurally-equivalent filters (`{a,b}` vs `{b,a}`) produce the same hash.

`rbacHash` is `sha1($user->getUID())` (or `sha1('anonymous')` when no user
is logged in).

### Read-through pattern

`AggregationRunner::runAdhoc()` implements a read-through cache:

1. On entry: check `AggregationCache::getAdhoc()`.
2. Cache hit → return the stored envelope with `cached: true`.  No SQL executed.
3. Cache miss → execute the aggregation, write the envelope via
   `AggregationCache::setAdhoc()`, return it with `cached: false`.

The first call always returns `cached: false`.  Subsequent identical calls
within 60 s return `cached: true`.

### Invalidation

`AggregationCacheInvalidationListener` listens for `ObjectCreatedEvent`,
`ObjectUpdatedEvent`, and `ObjectDeletedEvent`.  On any of these, it calls
`AggregationCache::evictForSchema()`, which executes `ICache::clear()` on
the `openregister_aggregations` namespace.

This is a coarse eviction: it flushes **all** entries (both named and ad-hoc)
regardless of which `(register, schema)` pair triggered the event.  The 60 s
TTL ceiling bounds residual staleness.

A precise prefix-scan eviction would require either prefix-scan support on
`ICache` (available on Redis but not APCu/memcache) or a secondary key index.
The coarse approach is documented as a known trade-off; revisit if prefix-scan
support lands in Nextcloud's cache layer.

### Graceful degradation

If the distributed cache backend is unavailable at construction time,
`AggregationCache` degrades silently: `getAdhoc()` always returns null,
`setAdhoc()` is a no-op.  The aggregation continues to work correctly, just
without caching.

### Stampede behaviour

With the 60 s TTL and no distributed locking, simultaneous requests on a
cold cache each execute the underlying SQL query and each write the result.
The last write wins (idempotent: same query → same result) and subsequent
requests hit the cache.  For dashboards with many concurrent tiles, the
worst-case duplicate-query count equals the number of concurrently in-flight
requests to the same `(register, schema, query)` triple — bounded by the TTL.

## Performance notes

- Native SQL paths (PostgreSQL, MySQL, SQLite) execute the aggregation inside
  the database engine in a single query.  The database handles bucketing and
  aggregation without hydrating rows into PHP.

- The PHP fallback path (`php-fallback`) fetches up to `PHP_FALLBACK_ROW_CAP`
  (50 000) rows and buckets them in memory.  It is correct but slow for large
  row sets.  Migrate to a supported database for native-path performance.

- The cache read-through pattern short-circuits the SQL query for identical
  requests within the 60 s window, reducing load on both the database and PHP.
