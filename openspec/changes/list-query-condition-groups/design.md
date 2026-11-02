# Design: list-query-condition-groups

Read at openregister `development` 118e2b58.

## Context

- `MagicSearchHandler::COMPARISON_OPERATORS` is
  `['gte', 'lte', 'gt', 'lt', 'in', 'notIn', 'ne', 'isnull', 'like']`
  (`lib/Db/MagicMapper/MagicSearchHandler.php:99`). The open change
  `search-value-or-empty-filter` adds `inOrEmpty`; this change takes whatever
  the list holds when it lands.
- `applyObjectFilters()` (`:3089`) turns the filter map into conditions and
  joins them with AND. Inside one property, a multi-value `in` already uses
  `orX()`. Across properties there is no OR.
- `ObjectService::findAll()` (`lib/Service/ObjectService.php:1544`) has no
  default limit, so a caller that filters in PHP reads the whole table.
- Underscore parameters are reserved and never filters (zoeken-filteren,
  "reserved parameters"). `_where` is unused today; `_filter` is taken by
  rendering.

## D-1: the tree

```json
{
  "type": "AND",
  "children": [
    { "field": "place", "operator": "eq", "value": "Zuiddrecht" },
    {
      "type": "OR",
      "children": [
        { "field": "industry", "operator": "in", "value": ["horeca"] },
        { "field": "industry", "operator": "in", "value": ["detailhandel"] }
      ]
    }
  ]
}
```

- `type` is `AND` or `OR`. A group needs at least one child.
- A leaf names a schema property or an `@self.<column>` metadata column, the
  same names a flat filter accepts.
- `eq` is the flat filter without an operator (`?place=Zuiddrecht`).
- On the query string `_where` is URL-encoded JSON. A POST search body may
  carry it as an object.

This is the shape pipelinq stores in a segment's `rules`, minus pipelinq's
operator names. Keeping one shape means a caller translates operators, not
structure.

## D-2: one condition builder

Each leaf is handed to the code that builds a flat filter for that property
today, so a leaf and the equivalent flat filter produce the same SQL. Groups
wrap the leaf expressions in `andX()` / `orX()`. The group tree is one
parenthesised expression added with `andWhere()`, after the flat filters and
before RBAC and organisation scoping, which stay outside it and cannot be
widened by an OR.

The encrypted-property guard of `applyObjectFilters()` runs on every leaf.

## D-3: refusals are loud

400 with `{"error": "...", "path": "$.children[1].children[0]"}` when:

- `_where` is not valid JSON or not a group;
- a group has no children, or `type` is neither `AND` nor `OR`;
- a leaf names a property the schema does not declare, or an encrypted one;
- an operator is not in the list;
- the tree is deeper than 5 groups or holds more than 200 leaves;
- the search backend for the schema is Solr or Elasticsearch. Those backends
  get their own translation later; until then they refuse instead of
  ignoring the parameter.

The path notation is the one pipelinq's validator already uses, so a caller
can point at the row that failed.

## D-4: counts and facets follow

`countSearchObjects()` and the facet handler read the same query array, so
they apply `_where` through the same builder. The test asserts list length,
total and one facet bucket on the same tree.

## D-5: feature detection

The capabilities endpoint lists `"listQuery.where": true`. A caller that
wants to support older OpenRegister versions checks it once and caches it.

## Risks

- A leaf on a property without an index makes an OR group a sequential scan.
  That is no worse than the PHP scan it replaces, and it stays inside the
  database.
- An OR across a reference column and a scalar column mixes two condition
  paths (raw SQL and QueryBuilder). Task 1.2 tests that mix.
