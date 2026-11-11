# Design

## Why existence, not only values

The argument against is that a schema is a contract and a contract should be
stable. The argument for is that a name is information, and the names that are
governed are the names worth protecting.

What settles it is that the two are not in tension the way they appear to be. The
API never returns a property the caller may not read. So a document that lists it
is not a stabler contract, it is a WRONGER one: it describes a response shape the
caller will never receive. Removing it removes a promise that was never going to
be kept.

## A count, not a list

`x-openregister-withheld-properties: 3` is deliberate and the shape matters.

Naming them would be the leak with an audit trail attached. Omitting the notice
entirely would be worse in a different way: an integrator reading a schema with
four properties cannot tell whether that is the whole schema or the part they are
allowed to see, and would build as though it were complete. The count says "there
is more here and it is not yours" without saying what, which is the only honest
thing this document can say.

## Reusing the one evaluator

Both paths ask `PropertyRbacHandler::canReadProperty()` through
`AggregateVisibility`, which #3938 introduced for exactly this: one answer to
"may this person see this field", asked in more places. Neither path holds a rule
of its own.

The check passes an empty object, as the aggregate paths do. A schema description
is not about one record, so a CONDITIONAL rule that depends on a record's
contents does not admit the description. That is the safe direction, and it is
consistent with the facet decision rather than a new judgement.

## Required, enum and example

`required` is filtered to what survives. A required list naming a property that is
not in the document is not a contract anyone can satisfy, and a generated client
would fail validation on a field it cannot even see.

`enum` and `example` need no separate rule: they live inside the property
definition and leave with it. Saying so here because "we only hid the property,
the example was elsewhere" is exactly the sort of gap that ships.
