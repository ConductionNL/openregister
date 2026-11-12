---
kind: code
---

# Proposal: query-referenced-schema-rows

## Summary

Filter objects by a field of the row their OWN property references:

    _referenced[subsidieAanvraag][subsidieaanvraag][case]=<case uuid>

returns the objects whose `subsidieaanvraag` property holds the uuid of a
`subsidieAanvraag` row whose `case` is that uuid. It is the forward twin of
`_related` (query-related-schema-rows), which only asks about rows pointing AT
the object. Same grammar, operators, numbered blocks and refusals; the
subquery carries the referenced schema's own read access predicate.

## Why

Dossiq's subsidy case page (board DqSubsidie) lists beschikkingen, voorschotten
and vaststellingen of the case. Those rows point at the subsidy application,
not at the case. Today a dotted key such as `subsidieaanvraag.case` on a uuid
property is silently read as `subsidieaanvraag IN ('<case uuid>')`: an empty
list and no error.

## Scope

- `RelatedRowFilter` gains a direction; `RelatedRowFilterParser` reads
  `_referenced` with the same grammar; `RelatedRowExistsClause` joins
  `referenced._uuid = object.<property>`; `RelatedRowQueryApplier` and
  `MagicSearchHandler` apply both keys.
- A property written in camel case reads its snake-case magic-table column,
  for both `_related` and `_referenced` (it was quoted as written, a column
  that does not exist).
- Not in scope: facets over `_referenced`, and the multi-schema raw UNION path
  (neither supports `_related` either).
