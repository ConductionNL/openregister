---
kind: code
---

# Proposal: aggregation-null-filters

## Summary

The aggregation endpoints (`/api/objects/aggregations/{register}/{schema}`,
`.../grouped`, named and timeseries aggregations) accept the list endpoint's
"empty" / "filled" filter syntax: `field=IS NULL`, `field=IS NOT NULL`,
`field[isnull]=true|false`. The meaning is read in one place,
`NullFilter::nullCheck()` / `NullFilter::isNullOperatorAsksForNull()` (`lib/Support/NullFilter.php`),
which the list search (`MagicSearchHandler`, `NestedPathFilter`) and the
aggregation runner now share.

## Why

Dossiq's Queue page filters `assignee: IS NULL`. nextcloud-vue counts its
quick-filter chips through the grouped aggregation with the page filter, and
there `IS NULL` was compared as the literal text (empty groups) while
`[isnull]=true` was refused as an unsupported operator. The list showed rows;
the chips showed nothing.

## Scope

- PHP fallback (`applyFilter` / `checkOn`) and the native SQL path
  (`tryNativeAggregation`) both honour the three spellings; the native path no
  longer bails on `isnull`.
- Row RBAC and organisation scoping are unchanged (the native path keeps its
  row predicate; the PHP path keeps its hydration).
- "Empty" means SQL NULL / a missing value, exactly as on the list endpoint;
  an empty string is a value.
