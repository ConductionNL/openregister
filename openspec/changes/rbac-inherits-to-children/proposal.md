---
kind: code
depends_on: [object-level-sharing-and-private-scope]
---

# Proposal: rbac-inherits-to-children

## Summary

A grant on a parent object reaches its descendants. A schema names the
property that points at the parent; a per-object grant on the root then
answers for every object below it, with the same verbs and no second
grant. Nobody has to remember to share a sub-case.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q13.23 | Does a right granted on a parent apply to its descendants without a second grant | no | M |

From the gap register at `procest/_gaps/` in
ConductionNL/market-intelligence (2026-09-13), owner openregister, slug
`rbac-inherits-to-children`, opened by the last sweep of the OpenSpec
phase.

## Why

The best competitor, verbatim from the register's `best` column: "Vikunja
2.6.0: project_access.go:48-71 resolves access through a recursive CTE
over parent_project_id; read on the root read the grandchild and was
refused a write, measured (`_round4/compare/proposed-rows-batch7.md`)".
Two facts in that sentence do the work: the resolution is one recursive
query, and the verb does not grow on the way down.

The register's `why`: "a grant that reaches an object's children without a
second grant is the authorization layer's, over the relation that names
the parent". Its note on dossiq: "row 2.10 records sub-cases and 13.3
records partner shares per case, and `deelzaak|parentCase|subCase` over
`lib/Service/CaseAccessGuard.php` returns 0, so nothing in the guard reads
the parent".

What exists and does not close it, from the register's `covered` column:
"rbac-scopes matches conditions on one object; object-level-sharing-and-private-scope
grants one object". `rbac-scopes` does cascade, but from register to
schema, not from object to object: "When a register defines default
authorization rules, those defaults SHALL cascade to all schemas that do
not define their own authorization". A hierarchy of objects is a different
axis and has no rule at all.

## What changes

- A schema declares its parent edge:
  `x-openregister-hierarchy: {"parent": "<property>", "maxDepth": <n>}`.
  The property must be a declared reference to the same schema.
- `PermissionHandler` resolves a per-object grant over that edge: a grant
  on an ancestor answers for a descendant, in one recursive query, with
  the same verbs the ancestor's grant carries.
- The inherited grant never widens. Read on the root is read on the child;
  it is not update. A grant written directly on the descendant is
  evaluated beside the inherited one under the existing most-specific-wins
  rule.
- `maxDepth` is bounded and a cycle fails closed: no answer, a logged
  refusal, never an accidental grant.
- `GET /api/scopes` and the scope audit name where an inherited grant came
  from, so "why can this person see it" has an answer that points at an
  object.
- Moving an object to a different parent re-resolves on the next read;
  nothing is copied onto the child.

## Consumers

- dossiq: deelzaken inherit the parent case's grants and
  `CaseAccessGuard` stops keeping its own answer. The register's
  `dossiq_half`: "deelzaken inherit the parent case's grants;
  CaseAccessGuard stops keeping its own answer". Specified in dossiq as
  `deelzaken-inherit-the-parent-grants`.
- opencatalogi and stackiq (a catalogue and its entries), decidiq (a
  dossier and its decisions), buildiq (a page tree): the same declaration
  and no code.

## ADRs

- Company ADR-022: one inheritance rule in the authorization layer, not a
  guard per app.
- Company ADR-005: the resolution fails closed on a cycle or past the
  depth cap.
- Company ADR-031: the parent edge is declared on the schema.
- openregister ADR-009: the resolution is one bounded query, not a walk
  per object in a list.
- openregister ADR-010: the inherited grant carries the ancestor's verbs
  and no others.

## Impact

- Extends: `rbac-scopes` (scope resolution and the discovery API) and the
  per-object grants of `object-level-sharing-and-private-scope`.
- Affected code: `PermissionHandler`, `MagicRbacHandler` (the list filter
  needs the same recursive term or a list read disagrees with an object
  read), the schema annotation validator, `ScopesController`.
- Backwards compatible: a schema with no `x-openregister-hierarchy`
  resolves exactly as today.
- Size: M.

## Out of scope

- Inheritance of schema-level group rules. Those already cascade from the
  register; this change is about per-object grants.
- Blocking inheritance on one child. A deny that stops the flow down is a
  second mechanism with its own failure mode, and no competitor in the
  register has one.
- A second parent. One declared parent property per schema; a graph is not
  a hierarchy.
