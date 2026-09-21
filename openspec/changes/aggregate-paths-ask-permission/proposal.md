---
kind: code
---

## Why

A facet returns the DISTINCT VALUES of a column with counts. openregister#3934
found that `MagicFacetHandler` offered every property marked `facetable` to every
caller who could see the rows, and never asked whether that caller could read the
property. So for any property carrying an `authorization` block, or the newer
`scope` shorthand, everybody who could list the register could read the whole set
of answers without ever being allowed to read one of them.

Nothing on screen suggested it. The render path strips a governed property from
every object body correctly, so the field was invisible where people looked for
it and legible where nobody did.

That fix closed one path. It did not answer the question the path raised: **how
many other places turn a column into a summary, and do any of them ask?** An
aggregate is a read of the column for everybody it is shown to, so every one of
them owes the same question, and a path that was written before property-level
authorization existed has no reason to have asked it.

The paths in this change were derived from the source, by finding everything that
takes a `Schema` and groups, counts, or discovers distinct values, rather than
from a remembered list. Four candidates turned out to be dead code and are
reported as dead rather than counted as leaks.

## What Changes

- One shared answer, `AggregateVisibility`, delegating to `PropertyRbacHandler`.
  It is not a second evaluator: a second answer to "may this person see this
  field" disagrees with the first within a week, and the wider one is the one
  that discloses.
- **`AggregationRunner`**: gates aggregation on LIST permission for the schema
  today, which is a different question from whether the caller may read the
  property being summed. A `SUM` over a salary nobody may read is the salary
  total. Now refused.
- **`ViewPresentationService`** (kanban): discovers the distinct values of
  `groupByField` to build its columns, so a governed grouping property becomes a
  row of column headings naming every value.
- **`FacetHandler`**: advertises facetable fields and computes facets over them
  without asking.
- **A count that cannot be shown is ABSENT, not zero.** Zero is an answer, and a
  wrong one: it says the value does not occur. The aggregate is omitted and the
  response says which fields were withheld, so a client can tell "no data" from
  "not yours".
- A derived architecture test: every live path that summarises a schema property
  asks the question, or carries a reason.

## Capabilities

### Modified Capabilities

- `rbac-scopes`: property-level read authorization is extended from object bodies
  and exports to every aggregate over a property.
