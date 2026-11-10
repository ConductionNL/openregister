# Design: fields-a-user-adds-and-choices-a-record-narrows

## D-1: a scoped property is a property, not a bag of extras

The cheap way to let users add fields is a free-form key-value blob. It works
until somebody wants to search it, group by it, export it or validate it,
which is the same week. So a scoped property is a real property in the
schema with a scope attribute. Everything that works for a schema property
works for it, because it is one.

## D-2: the scope is evaluated on read and on write

A property outside the caller's scope is not returned and not writable. That
is the same evaluation field-level security already performs, with the scope
as the condition, so there is one filter and not two.

## D-3: a ceiling, because the failure mode is accumulation

Every system that lets users add fields ends up with hundreds of them, most
used twice in 2019. The ceiling is per scope and administered, the unused
ones are reported, and removal is an ordinary schema act. Naming the problem
in the spec is cheaper than a cleanup project later.

## D-4: promotion keeps the values

A scoped property that becomes a schema property keeps the values already
stored, because the alternative is that everybody waits for the change
request anyway rather than starting with a scoped field.

## D-5: a filtered reference is a rule, not a hint to the form

If the filter lives only in the options endpoint, any client that posts
directly ignores it. So the filter is evaluated on write as well, and a
value outside it is refused with the filter named. The form and the API then
agree, which is the same principle the rules engine applies to every other
declared rule.

## D-6: an unresolved dependency returns nothing, and says why

When the operand property is empty, returning every object is worse than
returning none: it invites the wrong choice. The options read reports that
the filter has no value to resolve and names the property it needs. The form
can then say "choose an organisation first" instead of showing four thousand
contacts.

## D-7: reuse analysis (ADR-012)

- The property vocabulary and its validator from
  `property-vocabulary-published`: reused, extended with the scope.
- The field-level filter of `specs/row-field-level-security`: reused as the
  scope evaluator.
- The object query, its paging and its access compilation: reused for the
  options read.
- The declared-action authorization of ADR-023: reused for who may add a
  scoped property.
- No second property model, no key-value side table, no second options
  endpoint.
