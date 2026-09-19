# Design: relations-that-travel-and-what-they-expose

## D-1: one traversal, two answers

`relation-types-with-inverses` already specifies a bounded graph walk that
returns objects and typed, directed edges. The affected set is not a second
traversal: it is the same walk with a type filter, a party projection and a
prune list, and it reports truncation the same way. Two walkers would drift,
and the second one would be the one nobody bounded.

## D-2: the prune is an input and an output

A caller names the relation types to exclude. The answer lists what was cut,
by type and at which node. A notification list that silently omits a branch
is worse than one that omits it loudly, because the omission is invisible
precisely when it matters: somebody was not told.

## D-3: a party relationship is a record, not two fields

Guardian and ward is one fact with two readings. Storing it as a property on
each party gives two facts that can disagree. So it is an object of its own:
two party references, a type, a period, and a provenance. The reciprocal
label comes from the type, exactly as `relation-types-with-inverses` does it
for record relations, so there is one vocabulary and not two.

## D-4: exposure narrows, it never widens

The field set a link type declares is the intersection of what the link
offers and what the reader could see through any other path. A link can
therefore only ever show less than the far record, never more, and a reader
who already has access is unaffected. The alternative, a link as a grant,
turns every relation into an access-control decision made by whoever
declared the schema.

## D-5: withheld, not absent

A property outside the declared set reads as withheld. `objects-as-the-hinge-between-cases`
made the same choice for its lens (REQ-OHC-003), for the same reason: empty
reads as "there is no besluit" and withheld reads as "you may not see it".

## D-6: reuse analysis (ADR-012)

- The graph walk, the depth bound, the truncation report and the relation
  vocabulary: reused from `relation-types-with-inverses`.
- The party record and its typed role: reused from
  `party-roles-beyond-the-requester`.
- The property-level filter of `row-field-level-security`: reused as the
  evaluation point for the exposed set.
- No second traversal, no second permission evaluator, no second label
  vocabulary.
