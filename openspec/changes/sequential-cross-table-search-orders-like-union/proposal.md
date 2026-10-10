---
kind: code
depends_on: []
---

# Proposal: sequential-cross-table-search-orders-like-union

## Summary

A search over more than one schema runs on one of two paths. The UNION path
orders the merged rows in SQL on the requested `_order` (or on the search
score, or on the uuid when nothing is asked), with the uuid as the last
tiebreaker. The sequential path, taken when the query carries
`_aggregations`, asked every table for its own first rows and joined the
tables one after the other. #4522 made it page once, after merging, but the
merged rows still came back table by table. The same question therefore got
a different order, and a different page 2, depending on which path ran.

This change makes the sequential path order the merged rows on the same keys
as the UNION path before it cuts the page, and asks every table for its rows
in that same order so each table's over-fetch holds the rows the merged page
needs.

## What changes

- The sequential path resolves its order keys with the UNION path's own key
  builder (`buildUnionOrderKeys`) and sorts with the UNION path's own
  comparator (`sortUnionRows`), so the two cannot drift.
- Each table is asked for its first `offset + limit` rows in that order,
  with `@self.uuid` as the closing tiebreaker, instead of its own default
  (`_id`).
- An unknown `@self.*` order key is dropped, as the UNION path drops it.
- The recent lens is unchanged: it already orders the whole result above
  both paths (`RecentLensOrder`).
- The UNION path is not changed.

## Out of scope

The single-table search orders text case-insensitively and a translatable
property by the value a person sees; the UNION path orders on the raw
column. That difference lives in the UNION path and is not closed here.
