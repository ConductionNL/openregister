---
kind: code
depends_on: []
---

# Proposal: list-query-condition-groups

## Summary

A list query takes a condition group. Today every property filter on
`GET /api/objects/{register}/{schema}` joins the others with AND, so "in
Zuiddrecht, and in horeca or retail" cannot be asked in one query. The caller
fetches every object and filters in PHP. This change adds one reserved
parameter, `_where`, that holds a tree of AND and OR groups over the filters
OpenRegister already understands.

## Why now

pipelinq's segment builder draws rule groups (board PqSegmentBouwer, pipelinq
change `segment-groups-its-rules`). pipelinq already evaluates nested AND/OR
segments, but it does so by loading every contact or client of the audience
with `ObjectService::findAll()` and no limit, then walking the tree per object
(`SegmentService::countMatchingEntities()`). That runs on every debounced
preview keystroke and on every send. The data stays in OpenRegister; the
question should go there too.

Any app that filters on more than one property with OR has the same gap:
saved views, report filters, flow conditions over a list. So the support goes
in OpenRegister, not in pipelinq.

## What changes

- A reserved query parameter `_where`, a JSON condition group:
  `{"type": "OR", "children": [ ... ]}`. A child is another group or a leaf
  `{"field": "<property>", "operator": "<op>", "value": ...}`.
- The leaf operators are the ones a flat filter already takes
  (`MagicSearchHandler::COMPARISON_OPERATORS`), plus `eq`. A leaf means
  exactly what the same flat filter means.
- `_where` is ANDed with the flat filters, RBAC and organisation scoping.
  Paging, the total, the count endpoint and facets use the same condition.
- PHP callers pass the same tree as `$query['_where']` to
  `searchObjectsPaginated()` and `countSearchObjects()`, or as
  `$config['where']` to `findAll()`.
- A tree OpenRegister cannot answer is refused with 400 and the path of the
  failing node. It never quietly returns zero rows or the whole table.

## Not changed

- Flat filters keep their meaning, spelling and results.
- No schema, table or index change.
- No NOT group. pipelinq keeps its NOT nodes in PHP (see its design).

## Rollback

Remove the parameter. Callers that feature-detect it fall back to their own
evaluation; pipelinq does.
