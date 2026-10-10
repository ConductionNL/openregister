# Design: sequential-cross-table-search-orders-like-union

## One source for the order

The UNION path already has a PHP twin of its SQL `ORDER BY`: when it runs
in several batches it merges them with `mergeUnionBatchRows()`, which sorts
on `buildUnionOrderKeys()` with `sortUnionRows()`. The sequential path reuses
exactly those two methods. It projects each `ObjectEntity` onto the row keys
the UNION statement would have produced:

- `_search_score`: the score the row carries (`_search_score` in its data,
  else its relevance as a fraction), 0 when it has none.
- a metadata column `_<name>`: the entity's own field (`_schema_version`
  reads `schemaVersion`); dates as `Y-m-d H:i:s.u` so they compare in time
  order.
- a property column: the object's value for the property whose sanitized
  column name is that key; arrays as JSON; missing as null, which sorts
  where the UNION's `NULL AS alias` arm puts it.

## The per-table cut

Paging once after merging (#4522) is only right when each table's first
`offset + limit` rows are the rows the merged order would pick from that
table. A table without `_order` falls back to `_id`, so its cut was the
oldest rows, not the first by uuid or by score. Each table is now asked for
the resolved keys in single-table syntax: `_search_score` as `_relevance`,
a metadata column as `@self.<name>`, a property by its own name, closed by
`@self.uuid ASC`.

## Risk

Where the single-table order and the UNION order disagree on text case, a
table's cut may hold a different row at the page boundary than the UNION
would. That is the pre-existing single-table vs UNION difference named in
the proposal, not a new one.
