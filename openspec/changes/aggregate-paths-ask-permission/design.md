# Design

## One answer, asked in more places

`PropertyRbacHandler::canReadProperty()` already decides whether a caller may
read a property, and the render, export and OAS paths consult it. The whole
content of this change is that the aggregate paths consult it too.

`AggregateVisibility` exists to make that one line the same line everywhere: it
resolves the handler, asks, and fails closed. It deliberately holds no rule of
its own. The moment it did, there would be two answers to the same question,
they would drift, and the wider one would be the one that discloses.

## Why an empty object, not the row

A facet or an aggregate is not about one record. It asks whether this property is
readable AT ALL for this caller, not whether it is readable on some particular
row. So the check passes an empty object, which means a CONDITIONAL rule, one
that depends on a record's contents, does not admit the aggregate.

That is the safe direction and it is a real restriction: a property readable only
on rows the caller owns is not summarisable by them, because a summary spans rows
they do not own.

## Absent, not zero

The instruction "fail closed" has a trap in aggregates specifically. Returning
`0`, or an empty bucket list, is not withholding: it is asserting that the value
does not occur. A reader cannot tell it from a real zero, and a real zero is
information they were entitled to about a field they were not.

So a withheld aggregate is removed from the response entirely, and its name is
listed under `withheld`. A client can then say "you may not see this", which is
true, instead of "none", which is not.

## Dead paths are reported, not fixed

Four facet handlers matched the shape and are never instantiated anywhere:
`HyperFacetHandler`, `MariaDbFacetHandler`, `MetaDataFacetHandler` and
`OptimizedFacetHandler`. Adding a guard to them would raise the count of paths
"fixed" while protecting nothing, and would make them look maintained. They are
named in the PR body as dead instead.
