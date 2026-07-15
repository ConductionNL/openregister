---
kind: code
depends_on: []
---

# Multi-field (cross-tab) groupBy in the aggregation engine

## Why

OpenRegister's aggregation engine (`AggregationRunner` + `AggregationQuery`) honours only a **single-field** `groupBy`. Cross-tab (multi-field) grouping — supplier × month, category × cost-centre — is impossible, and declarations that request it fail **silently**: a `groupBy` given as a list of fields has no `'field'` key, so both the native-SQL path and the PHP fallback skip grouping entirely and no error is raised (the phantom-capability pattern).

Concrete downstream impact (shillinq, tracked in openregister#432):
`custom_apps/shillinq/lib/Settings/register.d/bookkeeping-accounts-payable-core.json` declares `x-openregister-aggregations` entries `agedPayablesDetail` / `agedPayablesSummary` with `"groupBy": ["vendorId", "dueDateBucket"]`. These are **inert** today — the second dimension is silently dropped, so a leaf cannot deliver spend-by-supplier × period or category × cost-centre cross-tabs through the aggregation-api.

Per ADR-022 (apps consume OR abstractions), cross-tab aggregation is an OR-owned data-layer capability. Re-implementing GROUP-BY-over-two-fields inside a leaf (folding hydrated rows in PHP) is exactly the anti-pattern that rule forbids.

## What Changes

- **`AggregationQuery`**: accept a multi-field `groupBy` in three shapes — the existing single-field `{field: 'x'}` (unchanged), a cross-tab `{fields: ['a', 'b']}`, and a plain ordered list `['a', 'b']` (the shape shillinq already declares). New accessors `getGroupByFields(): array` and `isMultiFieldGroupBy(): bool`; `getGroupByField()` retained (returns the FIRST field) for backward compatibility. A shared static `normaliseGroupByFields()` canonicalises every shape. Validation **rejects** empty members, empty lists, and duplicate fields with a clear `InvalidArgumentException` — never silently partial.
- **`AggregationRunner` native-SQL path**: `tryNativeAggregation()` emits `GROUP BY a, b` over the sanitised magic-table columns and returns one row per distinct tuple. Runs natively on Postgres, MySQL and SQLite (reusing the already platform-branched aggregate SQL + identifier quoting); the categorical-groupBy platform gate is relaxed so non-Postgres engines no longer fall through to PHP for grouped queries.
- **`AggregationRunner` PHP fallback**: `computeGrouped()` buckets on the field tuple (first-seen order) with identical semantics to the SQL path.
- **Result shape**: single-field groups keep the backward-compatible `{key, value}` shape; multi-field groups expose a composite `{keys: {fieldA: ..., fieldB: ...}, value}` so a consumer can pivot into a cross-tab.
- **Filters, RBAC, multi-tenancy, cache**: unchanged — grouping happens after the same `_organisation = ?` predicate and the same operator-filter vocabulary on both paths.

**Non-breaking.** The single-field `{field: <name>}` shape, its `{key, value}` result rows, and every existing caller continue to work unchanged.

## Capabilities

### Modified Capabilities
- `aggregation-api`: the ad-hoc + named aggregation primitive gains multi-field (cross-tab) categorical `groupBy` — an ordered list of scalar fields → `GROUP BY a, b` → one bucket per distinct tuple with a composite `keys` map. Single-field behaviour and result shape unchanged.

## Impact

**Code touched:**
- `lib/Service/Aggregation/AggregationQuery.php` — accept multi-field/list groupBy shapes; add `getGroupByFields()`, `isMultiFieldGroupBy()`, static `normaliseGroupByFields()`; strict validation.
- `lib/Service/Aggregation/AggregationRunner.php` — multi-column native `GROUP BY`; relaxed categorical platform gate; tuple-bucketing `computeGrouped()`; shared `resolveGroupFields()` helper across the named / ad-hoc / cross-schema paths.

**Tests:**
- `tests/Unit/Service/Aggregation/AggregationRunnerMultiFieldGroupByTest.php` — real in-memory SQLite proving the native `GROUP BY a, b` output, single-field backward-compat, and native ⇄ PHP-fallback agreement on a known dataset.
- `tests/Unit/Service/Aggregation/AggregationQueryTest.php` — new value-object shape + validation cases.

**Downstream follow-up (separate PR, shillinq):** un-inert `agedPayablesDetail` / `agedPayablesSummary` now that the multi-field `groupBy` is honoured.
