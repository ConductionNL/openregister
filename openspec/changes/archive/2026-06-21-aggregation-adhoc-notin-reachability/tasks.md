# Tasks — aggregation-adhoc-notin-reachability

## 1. notIn on the aggregation ad-hoc path

- [x] 1.1 `AggregationRunner::tryNativeAggregation` — accept `notIn` in the
      translatable-operator allow-list and emit `"field" NOT IN (?, ?)` with one
      bound parameter per operand.
- [x] 1.2 Empty `notIn` list excludes nothing — emit an always-true predicate
      (`1 = 1`), never a malformed `NOT IN ()`.
- [x] 1.3 `AggregationRunner::checkOp` (PHP fallback) — `notIn` returns true when
      the value is absent from the exclusion list (or the operand is not an array).
- [x] 1.4 `AggregationQuery` class docblock — document `notIn` in the supported
      filter-operator vocabulary.

## 2. notIn + ne on the findAll/count config-filter path

- [x] 2.1 `MagicSearchHandler::applyObjectFilters` (QueryBuilder path) — recognise
      `notIn` / `ne` as comparison operators; emit `notIn` via `expr()->notIn(...)`
      and `ne` via `expr()->neq(...)`. Empty `notIn` emits no clause.
- [x] 2.2 `MagicSearchHandler::buildObjectFilterConditionsSql` (raw-SQL UNION path)
      — same `notIn` (`NOT IN (...)`) and `ne` (`<> value`) translation; empty
      `notIn` emits no condition.

## 3. Documentation

- [x] 3.1 `docs/technical/aggregation-api.md` — add an "In-process (PHP service)
      surface" section: `runAdhocByRef` call pattern, method signature, config
      shape, ungrouped + grouped return shape, RBAC/tenant guarantees.
- [x] 3.2 Same doc — add a filter-operator vocabulary table including `notIn`,
      and note the operators are shared with `ObjectService::findAll`/`count`.

## 4. Tests + quality

- [x] 4.1 Integration: native `notIn` named aggregation returns the correct count.
- [x] 4.2 Integration: consumer-style `runAdhocByRef` returns a correct grouped
      SUM, an AVG with a `notIn` filter, and MIN/MAX scalars.
- [x] 4.3 Integration: stamp the active-organisation tenant column on the seed
      fixture so the native path's `_organisation = ?` predicate matches (fixes
      the pre-existing baseline where every native assertion in the file failed).
- [x] 4.4 Unit: `notIn` emits `NOT IN (?, ?)` and binds both operands; empty
      `notIn` emits an always-true predicate.
- [x] 4.5 Unit: findAll path emits `NOT IN (...)` for `notIn`, no clause for empty
      `notIn`, and `<> value` for `ne`.
- [x] 4.6 `php -l` + `phpcs --standard=phpcs.xml --warning-severity=0` clean on
      changed `lib/` files; affected PHPUnit suites green.

## 5. Live verification

- [x] 5.1 On :8080, seed a scratch register/schema, run `runAdhocByRef` for
      grouped SUM, AVG-with-notIn, MIN, MAX, COUNT-notIn, and empty-notIn via an
      admin session — assert the returned numbers are correct.
