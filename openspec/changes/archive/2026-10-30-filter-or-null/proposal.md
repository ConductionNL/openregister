---
kind: code
depends_on: []
---

# Proposal: filter-or-null

## Why

dossiq's dashboard widgets (overdue, stalled, deadline alerts) read their lists
through OpenRegister filters. A widget that wants the open cases asks for
`isFinalStatus: false` or `isFinalStatus: {ne: true}`. Both leave out every case
whose `isFinalStatus` was never written, because SQL treats a comparison with
NULL as unknown. A case that has not been recomputed yet silently drops off the
list.

The filter language has `isnull`, but every operator in a bag is ANDed, so
"false or not set" cannot be said. `boolean-filter-values` already ruled that a
null value matches neither `true` nor `false` and that a caller who means
"false or not set" asks for it explicitly. This change gives the caller the
words to ask.

## What changes

- A new modifier in the operator bag, `orNull`. When it is true, the field's
  other operators are ANDed as before and the result is ORed with
  `column IS NULL`.
- It works on schema properties and `@self` metadata, on the QueryBuilder path
  and the raw UNION path, and on paths inside object properties.
- Its value is read like `isnull`'s (`true`, `"true"`, `"1"`, ...).
- `ne`, `in`, `notIn` and the comparisons keep their meaning when `orNull` is
  absent.

## Out of scope

- Changing `ne` to keep NULL rows. That would widen every existing caller.
- The aggregation endpoint's own filter compiler (`AggregationRunner`). It
  ignores an operator it does not know, so `orNull` has no effect there yet.
- The facet handler's own filter path, which reads any operator bag as a plain
  value list today (`ne` and `in` included).
