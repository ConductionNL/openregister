# Design: grants-that-follow-a-slot-a-relation-or-a-reason

## D-1: a slot grant resolves at evaluation, it is never copied

Copying the grant onto the new occupant when the role changes leaves a trail
of stale grants nobody removes, and an access review that cannot be
finished. So the grant names the role and the resolution happens when access
is evaluated. An empty slot grants nothing, which is the correct answer and
not an error.

## D-2: a relationship grant is bounded by the relationship, not by a copy

The same argument. The grant names the relationship type, and the period it
holds is the period of the relationship record. When the relationship ends,
the access ends, with nothing to clean up. That is also why the party
relationship has to exist as a record first, which is why this change
depends on the one that adds it.

## D-3: assign is a verb, not a role

Handing work to somebody is currently an administrator check. Making it a
role would repeat the mistake one level up: the coordinator role would then
be granted for its side effect. It is a verb, in the governed vocabulary
ADR-010 defines, published in the catalogue, and grantable on its own.

## D-4: break glass is loud, narrow and short

Four properties, and each of them exists because of a way break glass goes
wrong. Declared per schema, so it is not available everywhere by default.
Limited to declared verbs, so it is not a route to administration. Bounded
in time, so it expires without anyone remembering. Announced when it is
taken, because a notice next month is not a control. The reason is required
and stored on the chained trail, where it cannot be edited after the fact.

## D-5: not inheritable is a property of the grant, not a deny

A deny removes a verb inside its scope. Not inheritable says the grant does
not travel to descendants, while leaving it in force where it was written.
Modelling one as the other would mean writing a deny on every child, which
is the bookkeeping the inheritance rule exists to remove.

## D-6: everything resolves in one evaluator

The slot, the relationship, the break-glass grant, the inherited grant and
the deny are five inputs to one resolution, evaluated in the order
`permission-provenance-and-deny` sets, with deny winning. Adding a second
evaluation site is how two paths disagree, which this codebase has already
paid for once, as ADR-010 records.

## D-7: reuse analysis (ADR-012)

- The verb catalogue, the deny rule, the provenance and the expiring grants
  of `permission-provenance-and-deny`: reused.
- The ancestor resolution of `rbac-inherits-to-children`: reused, with the
  not-inheritable flag as one more condition in it.
- The party role of `party-roles-beyond-the-requester` and the party
  relationship of `relations-that-travel-and-what-they-expose`: reused as
  the subjects of the two derived grants.
- The chained audit trail: reused for the break-glass reason and use.
- No second access model, no copied grants, no parallel verb set.
