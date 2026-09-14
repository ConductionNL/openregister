---
kind: code
depends_on: [rbac-inherits-to-children]
---

# Proposal: permission-provenance-and-deny

## Summary

An administrator can read the whole set of permissions a role may be
granted, and can take one away. Today the set is discovered screen by
screen and nothing subtracts: every rule in the chain adds, so a right a
broader grant hands out cannot be removed for one role, one group or one
object.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q13.25 | When an administrator defines a role, where do its permissions come from | no | M |

From the gap register at `procest/_gaps/` in
ConductionNL/market-intelligence (v3, 2026-09-14), owner openregister,
slug `permission-provenance-and-deny`. Batch 12 of round 4 proposed the
row and left dossiq unrated, saying it needed the permission resolver
read end to end rather than a grep. That reading was done for register
v3, against dossiq `development` at `9c478d810`, and the row rates `no`.

## Why

The best competitor, verbatim from the register's `best` column: "Huly
0.7.426: a role is defined on a master tag and draws on 52 permissions
declared in the model, nine of them Forbid counterparts, so a role
removes a right the space would otherwise grant, verified live
(`_round4/compare/proposed-rows-batch12.md`)". Two facts carry the row:
the set is declared and readable, and a third of it subtracts.

Tuleap rates `partial` for the reason worth copying down: an
administrator creates a group and grants it anything, but the permission
set is not published, so "what can this role do" is answered by walking
the project, the trackers, the fields and the docman tree.

**What exists here and does not close it.** `rbac-scopes` publishes six
permission types (`read`, `create`, `update`, `delete`, `list`, `manage`)
and supports named roles on a register whose `actions` array names CRUD
verbs. A custom verb is possible, but only as a vote at evaluation time:
`CustomScopeEvaluatingEvent` is dispatched, first vote wins, and nothing
enumerates the verbs an app has declared. So a role editor cannot offer
the set, because no endpoint can name it.

`GET /api/scopes` answers what the current user may do, by probing the
five canonical actions. It does not say where a grant came from, and the
scope audit answers per schema and per action rather than per rule. A
security officer asking "why can this person update this object" gets a
yes, not a reason.

And nothing subtracts. Every rule in the chain adds a grant, so the only
way to remove a right is to stop granting it somewhere else, which is the
opposite of what an administrator is looking at when they ask the
question. `rbac-inherits-to-children` put this out of scope in one
sentence: "A deny that stops the flow down is a second mechanism with its
own failure mode, and no competitor in the register has one." Batch 12
measured one. That sentence is why this change exists and why it depends
on that one.

**dossiq's half, for scale.** The consumer has no permission model to
map onto this yet. Read at `9c478d810`: `RolEditorDialog.vue` defines a
role with a name, a type, a parent, a department, a team and a level and
no permission selector at all; the mandate matrix scopes a mandate by
untyped `decisionTypes` and `caseTypes` strings; `permissions.js`
publishes two names, `user` and `admin`; and the one role and action
matrix in the tree is read only for `=== true`, so `false` is an absent
grant and never a deny. The layer has to publish the set before the
consumer can name one.

## What changes

- **The grantable set is published.** `GET /api/permissions` returns
  every permission that can be granted in this instance: the canonical
  verbs, `manage`, and every custom verb an app has declared, each with
  the app that declared it and a human sentence. A verb that is not in
  the catalogue cannot be granted.
- **A custom verb is declared, not only voted on.** An app registers its
  verbs the way it registers anything else. The voting event stays as the
  evaluator; the declaration is what makes the verb offerable and
  auditable.
- **A role carries a permission set drawn from the catalogue.** The
  `roles` key on a register keeps its shape and its `actions` array is
  validated against the catalogue, so a typo fails at save rather than
  denying silently for a year.
- **A rule may deny.** An authorization entry may name a verb as denied
  for a group, a role or an object. A deny removes the verb inside its
  scope and is not overridden by a broader grant, including one inherited
  from an ancestor object. Deny wins; that is the only rule that keeps
  the answer predictable.
- **A deny cannot lock administration out.** `manage` on the register
  cannot be denied to the last principal that holds it, and the refusal
  says so. An access model that can orphan itself is a support incident,
  not a feature.
- **Every answer carries its provenance.** `GET /api/scopes` gains, per
  action, the rule that decided it: the register default, the schema
  rule, the role, the per-object grant, the ancestor it was inherited
  from, or the deny that removed it. The scope audit reports the same
  shape, so "why can this person do this" is answered by naming a rule
  and pointing at it.

## Consumers

- **dossiq**: the mandate matrix names its grantable set instead of
  untyped `decisionTypes` and `caseTypes` strings, and the role editor
  gains the permission half a role has never had. The register's
  `dossiq_half`, verbatim: "the mandate matrix names its grantable set
  instead of untyped decisionTypes and caseTypes strings, and the role
  editor gains the permission half a role has never had". A `mandaat`
  that ends is a deny with an end date, which is the first real use of
  the negative rule in the fleet.
- **keepiq and integriq**: a scoped token cannot be narrower than its
  issuer today (Q13.20); a deny gives `scoped-api-tokens` the primitive
  to express the narrowing rather than to approximate it.
- **decidiq, humaniq, portaliq**: a reader who must not see one dossier
  in a register they otherwise read. Today that needs a second register.

## ADRs

- Company ADR-022: one permission model in the authorization layer. An
  app declares verbs; it does not build a second matrix, which is exactly
  what the consumer did.
- Company ADR-005: the resolution fails closed. An unknown verb is
  refused, and a deny that cannot be resolved is a refusal, never a
  grant.
- Company ADR-031: the verb and the deny are declared configuration, not
  a branch in a controller.
- openregister ADR-009: provenance is resolved in the same query as the
  grant, not by a second pass per object.
- openregister ADR-010: an inherited grant carries the ancestor's verbs
  and no others, which is the rule a deny now completes from the other
  side.

## Impact

- Extends: `rbac-scopes` (the permission types, the named roles, the
  resolution algorithm, the discovery API and the scope audit), and the
  per-object grants of `object-level-sharing-and-private-scope`.
- Affected code: `PermissionHandler` (the deny pass and the provenance
  it returns), `MagicRbacHandler` (the same deny term in the list filter,
  or a list disagrees with an object read), `ScopesController`, the
  authorization block validator, the RBAC settings API and its admin
  surface.
- Backwards compatible: an instance that declares no deny and no custom
  verb resolves exactly as today, and `GET /api/scopes` keeps its
  envelope with provenance added beside the actions.
- Size: M.

## Out of scope

- Rewriting dossiq's mandate matrix. That is the consumer's half and
  belongs in dossiq, against the catalogue this change publishes.
- Per-property deny. `row-field-level-security` owns the property axis
  and a deny there is a second change once this one has settled.
- A time-boxed grant. A mandate that expires is dossiq's rule over this
  primitive, not a second kind of rule here.
- Importing Huly's 52 verbs. The catalogue is what this instance
  declares; the competitor's number is evidence that a published set is
  possible, not a list to copy.
