# Object writes under 500 ms

## Why

Creating one object through `POST /api/objects/{register}/{schema}` currently
takes **13–99 seconds** on the development instance. Measured 2026-07-28 on
`larpingapp/character`, six consecutive runs: 13.6 s, 17.8 s, 20.4 s, 41.0 s,
62.8 s, 99.1 s. The spread tracks cache warmth, not payload — the payload was
two fields every time.

This is not the CloudEvent storm. That was a separate defect (the recursion
guard compared numeric ids against slugs and never fired — openconnector#1086)
and it is fixed: a create now emits **1** event, not 255. The remaining 13–99 s
is OpenRegister's own write path.

### What one create actually costs

Counter deltas across a single `HTTP 201` create (41.0 s run), read from
`pg_stat_user_tables` / `pg_stat_database`:

| Metric | Per create |
|---|---|
| Sequential scans of `oc_openregister_schemas` | **5,135** |
| Sequential scans of `oc_openregister_registers` | 6 |
| Transactions committed | **12,541** |

Four structural costs, each measured rather than inferred:

**1. Schema resolution runs thousands of times and misses its cache.**
`SchemaMapper::find()` ([lib/Db/SchemaMapper.php:249](../../../lib/Db/SchemaMapper.php#L249))
has a request-scoped `findCache`, and `SchemaMapper` is a shared service, so
repeats of the same key are free. Yet a single create produces 5,135 scans of a
1,917-row table. Either the call sites vary the `(id, _rbac, _multitenancy)`
key enough to miss (4 flag combinations × 1,917 schemas = 7,668 distinct keys,
which comfortably accommodates 5,135), or a call path re-enters through an
uncached sibling (`findAll`, `findMultiple`, `findBySlug`, `findBySlugInIds`
have no cache at all). **Attributing these 5,135 calls precisely is task 1** —
the fix differs per path.

The query itself is a seq scan by construction:

```
Seq Scan on oc_openregister_schemas (actual time=1.640..3.960 rows=1)
  Filter: uuid = 'x' OR lower(slug) = 'character' OR id = 18
  Rows Removed by Filter: 1916
```

`SELECT *` hydrates a ~2 KB-wide row (the `properties` JSON blob) and
`LOWER(slug)` defeats any plain index on `slug`. At ~4 ms each, 5,135 calls is
**~20 seconds of pure schema re-resolution** — about half the 41 s run on its
own, before PHP-side `Schema::fromRow()` + JSON decode + `resolveSchemaExtension()`.

**2. Resolving an object reference fans out across every magic table.**
Looking up an object by `_id`/`_uuid`/`_slug`/`_uri` when the target table is
not known emits a `UNION ALL` with **one branch per magic table**. The instance
has **2,728** of them, so the statement is **690 KB of SQL**. Timed directly:

```
Planning Time:  3404.926 ms
Execution Time:  546.145 ms
```

**86 % of that 4 s is Postgres parsing and planning**, not reading data — the
Parallel Append returns 0 rows. Indexing cannot help; the only fix is to stop
emitting the query. And it is usually unnecessary: `character`'s relation
properties each already declare their target schema (`ocName`→19, `setting`→26,
`conditions`→23, `events`→1470, `items`→1469, `skills`→5441), so a typed
relation could be resolved against **one** table.

**3. The write is not one transaction.** 12,541 commits for one create means
essentially every statement autocommits. Each commit is an fsync the request
waits on.

**4. Post-write work runs inside the request.** CloudEvent fan-out, audit-trail
hash sealing (`oc_openregister_audit_trails` is at 228,932 rows), notification
history and `oc_activity` writes all happen before the response is returned.
None of it is something the caller needs in order to receive its 201.

### Why it matters beyond latency

Every app in the fleet writes through this path. At 13–99 s per create, e2e
suites time out, imports are unusable, and any UI that creates an object
appears hung. The e2e-coverage programme (ADR-074) cannot produce trustworthy
green until a create fits inside a normal Playwright action timeout.

## What Changes

Target: **p95 < 500 ms** for a single-object create on a warm instance, with
the 2,728-magic-table shape unchanged (fixing the write path, not shrinking the
dataset).

Budget, against the measured 41 s baseline:

| Cost | Now | Target | Mechanism |
|---|---|---|---|
| Schema resolution | ~20 s (5,135 scans) | < 25 ms (≤ 5 reads) | attribute the call sites, then a single request-scoped identity map in front of every read path + an APCu layer keyed on schema `updated` |
| Reference resolution | ~4 s per untyped lookup | < 5 ms | resolve typed relations against the declared `$ref` table; replace the global fan-out with a `uuid → (register, schema)` index table |
| Commits | 12,541 | 1 | wrap the write in one transaction |
| Post-write work | in-request | 0 in-request | move fan-out, audit sealing and activity writes behind the commit into a `QueuedJob` |

Non-goals: no change to the magic-table storage model, no change to the
public write contract, no reduction of what is eventually persisted — only
*when* and *how many times*.

## Impact

- **Affected specs**: new `object-write-performance` capability (budget +
  invariants); no existing requirement changes behaviour.
- **Affected code**: `lib/Db/SchemaMapper.php`, `lib/Db/ObjectEntityMapper.php`
  and the magic-mapper resolution path, `lib/Service/ObjectService.php` +
  `lib/Service/ObjectHandlers/SaveObject.php`, plus a new post-commit
  `BackgroundJob`.
- **Affected apps**: every app in the fleet writes through this path, so this
  is a fleet-wide latency change. Behaviour is unchanged; only timing moves.
- **Risk**: moving post-write work out of the request means a caller can
  observe a 201 before its CloudEvent/audit row exists. Task 5 pins that as an
  explicit, specified contract rather than an accident.
