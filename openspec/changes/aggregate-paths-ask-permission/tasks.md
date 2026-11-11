# Tasks: aggregate-paths-ask-permission

## 1. One shared answer

- [x] 1.1 `AggregateVisibility`, delegating to `PropertyRbacHandler`, failing closed.
  - It holds NO rule of its own. A second answer to "may this person see this
    field" disagrees with the first within a week, and the wider one discloses.
  - The check passes an EMPTY object on purpose: an aggregate is not about one
    record, so a conditional rule that depends on a record's contents does not
    admit it. A property readable only on rows the caller owns is not
    summarisable by them, because a summary spans rows they do not own.
- [x] 1.2 A withheld aggregate is absent from the response and named under `withheld`.
  - `partition()` returns both halves. Dropping the names silently would leave
    the caller unable to tell "this field has no values" from "this field is not
    yours", and the first is a claim about the data the system has no business
    making on the second's behalf.

## 2. The paths that did not ask

- [x] 2.1 `AggregationRunner` refuses an aggregate over a property the caller may not read.
  - Its existing gate is LIST permission on the schema, which is a different
    question. A SUM over a salary nobody may read IS the salary total, and a
    groupBy's keys are the distinct values of the column. Field, groupBy and
    metric fields are all checked.
  - REFUSED, not zeroed: an aggregation returns one number and there is nowhere
    in that number to say part of it was withheld.
  - `$bypassRbac` is honoured, because it is how internal callers compute
    figures for somebody else and those callers have already decided who sees
    the result.
- [x] 2.2 `ViewPresentationService` kanban columns do not reveal a governed grouping property.
  - A COLUMN HEADING IS A VALUE. The board is one column per distinct value of
    `groupByField`. The cards inside are stripped correctly by the render path,
    which is exactly what made this hard to notice: the board looks empty and
    correct while its headings are the leak.
- [x] 2.3 `FacetHandler` neither advertises nor computes facets over a property the caller may not read.
  - Withheld at the SOURCE, in the facetable-field list. Advertising the field
    is the first half of the leak and the easier half to miss: it tells a caller
    the field exists and invites them to ask for its buckets.

## 3. Keeping it true

- [x] 3.1 A derived architecture test: every live path that summarises a schema
      property asks, or carries a reason.
  - DERIVED FROM SOURCE. It walks `lib/`, finds everything that knows schema
    properties AND groups or counts or advertises facetable fields, and requires
    each to ask. 18 paths matched.
  - Its control is that it finds GUARDED paths too: a shape that matched only
    allowlisted files would report green while being blind to everything it
    polices.
  - Two staleness checks, because an allowlist rots quietly: every entry must
    carry a reason, and every entry must still be MATCHED BY THE SHAPE. The
    second was added after seven of my own first entries turned out to match
    nothing, which made them read as considered exceptions while being
    leftovers.
  - The four dead facet handlers became an ASSERTION rather than an allowlist
    entry: the suite checks they remain uninstantiated, so the day one is wired
    up it has to answer the question.
- [ ] 3.2 `tests/e2e/ci/aggregate-paths-ask-permission.spec.ts`.
  - NOT WRITTEN. Left for the same lane as the reference-options work rather
    than half-done here.
