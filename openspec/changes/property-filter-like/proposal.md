---
kind: code
depends_on: [zoeken-filteren]
---

# Proposal: property-filter-like

## Summary

Add a `like` operator to the property filter: `?title[like]=foo` (or
`?title_like=foo`) returns the objects whose `title` contains `foo`, ignoring
case. The nextcloud-vue table header filters need it for text columns; today
they can only ask for an exact value.

## Why

The table header filters in nextcloud-vue (pipelinq review item G2, 2026-10-06)
send one filter per column. For a text column the only thing OpenRegister
offers is equality, so typing "demo" in the client name filter finds nothing
unless the client is called exactly "demo".

Worse, an unknown operator does not fail. `?name[like]=demo` reaches the
condition builders as `name => ['like' => 'demo']`. `like` is not in
`MagicSearchHandler::COMPARISON_OPERATORS`, so the bag reads as a bare IN list
and becomes `name IN ('demo')`. Measured on a local pipelinq instance with 18
clients, one of them "Gemeente Demo ...": `?name[like]=demo` returned 0. The
DBAL object source skipped the bag entirely and returned every row.
`ObjectMetricSource` already emits `['like' => ...]` for metric descriptors
and has been getting the exact-match answer.

## What changes

1. `like` joins `COMPARISON_OPERATORS` and all four condition builders in
   `MagicSearchHandler` (object fields and `@self` metadata, QueryBuilder path
   and raw UNION path), and the listing filter of `DbalObjectSourceProvider`.
2. One class, `LikeOperator`, writes the SQL for every path:
   - PostgreSQL: `CAST(col AS TEXT) ILIKE :p`
   - MySQL / MariaDB: `LOWER(CAST(col AS CHAR)) LIKE LOWER(:p)`
   - SQLite: `LOWER(CAST(col AS TEXT)) LIKE LOWER(:p) ESCAPE '\'`
3. The term is escaped (`%`, `_` and `\` get a backslash) and wrapped in `%`,
   so it matches anywhere and its metacharacters match themselves. The pattern
   is a bound parameter on every QueryBuilder path. The raw UNION path joins
   SQL text and has no parameters, so there it goes through the platform's
   `quote()`, as every other condition on that path already does.
4. The column is cast to text, so `like` also works on numeric, date and JSON
   columns, where it matches the stored text.
5. A list of terms (`title[like][]=a&title[like][]=b`) matches any of them. An
   empty term adds no condition, so a cleared header filter shows everything.

## Impact

- `lib/Db/LikeOperator.php` (new), `lib/Db/MagicMapper/MagicSearchHandler.php`,
  `lib/Service/ObjectSource/DbalObjectSourceProvider.php`
- `docs/Features/search.md`
- No migration. `like` used to be read as equality, so a caller that relied on
  that now gets substring matches; `ObjectMetricSource` is the only caller in
  this repository and it asked for `like` on purpose.
- Not covered: the aggregation endpoints (`AggregationRunner`) keep their own
  operator set.
