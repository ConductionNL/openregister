---
kind: code
depends_on: [isnull-filter-operator]
---

# Proposal: search-value-or-empty-filter

## Summary

A game master who works in one campaign world sees, in every list, the
characters, items and skills of that world plus the shared ones that belong to
no world, in one list with correct paging and counts. OpenRegister's list
filter gains one operator that says "this value, or no value at all".

## Halves this closes

This is the OpenRegister half of larpinq's merged change
`events-world-scope-and-upcoming` (larpinq `development` f6a55a5), which covers
larpinq row `evt-world-scoping` (own rating partial; four competitors rate it
`yes`: LarpManager, MyLarp, Larp Portal and Kanka). It has no row in
OpenRegister's matrix; the owner moves pass of 28 Sep 2026 handed it here.
Larpinq writes, under Cross-project dependencies: "OpenRegister list filters:
'this world or no world' needs an `or` or `in` with empty in one list query. If
the installed OpenRegister cannot express it, the lens shows the world's own
objects plus a second query for shared ones on the dashboard only, and the gap
is reported for openregister."

Larpinq's design D2 adds `setting` (the active world) to the list query of
seven world-scoped schemas, and its spec requires the filtering to happen in
the list query, "not by trimming a fetched page".

## What changes

- A comparison operator `inOrEmpty`: `?setting_inOrEmpty[]=<uuid>` (or the
  nested form `setting[inOrEmpty][]=<uuid>`) matches objects whose `setting`
  is one of the listed values, or is null, missing, an empty string or an empty
  array.
- It works on scalar and array-valued properties, in both filter paths of the
  magic tables, and in counts and facets the same way.

## Out of scope

- A general OR between different properties. One property, one operator,
  covers the reported need.

## Impact

- `lib/Db/MagicMapper/MagicSearchHandler.php` (`COMPARISON_OPERATORS` at
  `:92`, the condition builders at `:1535-1556` and `:2560-2640`).
- `openspec/specs/zoeken-filteren/spec.md`.
