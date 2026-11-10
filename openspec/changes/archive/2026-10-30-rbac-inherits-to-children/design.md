# Design: rbac-inherits-to-children

## D-1. The hierarchy is declared, never inferred

`x-openregister-hierarchy: {"parent": "parentCase", "maxDepth": 5}`.
Inferring the parent edge from the relation map would make every
self-referencing property an access path, and `tighten-relation-detection-heuristic`
is on record about what the loose version of that heuristic cost. One
named property, validated at schema save to be a reference to the same
schema.

## D-2. The verb does not grow on the way down

The inherited grant carries the ancestor's verbs unchanged. This is the
half of Vikunja's measurement that is easy to drop: "read on the root read
the grandchild and was refused a write". A child is not more open than its
parent and never more open than the grant.

## D-3. One recursive query, on the list path too

The object read and the object list must agree, so the recursive ancestor
term goes into `MagicRbacHandler`'s SQL filter, not only into the
per-object check. A per-object walk inside a list of 500 rows is 500
queries and a page that times out (openregister ADR-009).

## D-4. A cycle answers no

A cycle in the parent chain, or a chain longer than `maxDepth`, terminates
the resolution with no grant and a logged refusal. Fail closed: an
authorization resolver that answers "probably yes" when it runs out of
budget is the failure this ADR-005 clause exists to prevent.

## D-5. Provenance, because "why" is the question people actually ask

`GET /api/scopes` and the scope audit report an inherited grant with the
ancestor it came from. Without that, an administrator looking at a
descendant sees access they cannot explain and cannot remove, because the
grant is not on the object in front of them.

## D-6. Kind

Code, in OpenRegister. A consuming app adds one schema annotation and
deletes its own parent-aware guard.

## Risks

- **Re-parenting changes who can see what, silently.** Resolution is at
  read time, so moving a sub-case under a different parent changes its
  audience with no write on the sub-case. The audit entry for the parent
  change is what makes it traceable, and the tasks name it.
- **A deep tree on a hot list.** The depth cap plus the existing scope
  cache bound the cost; the tasks require the list path to be measured,
  not assumed.
- **An app that already has its own guard.** Two answers is worse than
  one. The consuming change must delete the app-side guard in the same
  step, not run both.
