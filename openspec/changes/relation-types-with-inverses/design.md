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
