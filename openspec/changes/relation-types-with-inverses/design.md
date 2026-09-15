# Design: relation-types-with-inverses

## D-1: the label lives on the property, the vocabulary on the schema

A relation is a property with a `$ref`. Its label belongs beside it. A
vocabulary is a convenience for schemas with many typed links, so it is an
optional indirection, not a new entity: no table, no endpoint, one
annotation the validator checks.

## D-2: enrichment at read time, from the referencing schema

`getUsedBy()` finds referencing rows through `_relations_contains` without
knowing which property held the reference. The enrichment resolves the
referencing schema, walks its `$ref` properties for the one whose stored
value holds the target uuid, and attaches that property's label pair. The
walk is per referencing schema and memoised per request (openregister
ADR-009).

## D-3: writeBack is unchanged

`inversedBy` and `writeBack` keep populating the inverse property where a
schema declares one. The inverse label is for display when no inverse
property exists, which is the common case.

## D-4: kind

Code, in OpenRegister. Consuming apps annotate properties.

## D-C61-1. A split records where it came from

Zammad splits a ticket and keeps no provenance column, and the lane says
so. Copying the act without copying the omission costs one relation row
and answers "why does this zaak exist" for the rest of its life.

## D-C61-2. Inheritance happens once, at creation, and is recorded

Live inheritance of a classification means changing the parent silently
reclassifies children, which is an access change nobody authorised. The
child takes the values at creation, the relation says it did, and a later
change to the parent is a decision somebody makes again.

## D-C61-3. An external link is a relation, not a field

A URL pasted into a description is invisible to the graph, the reverse
view and the export. As a relation row with a title and a type it is all
three, and it costs no new concept.

## D-C61-4. The graph is depth-bounded by declaration

An unbounded graph query over a municipal register is a query that never
returns. The depth is declared per request within an administered
maximum, and the answer says whether it was truncated.
