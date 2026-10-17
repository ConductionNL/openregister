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

## Extension, discovery wave 1 (2026-09-14)

This change is the openregister half of two clusters of
`procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), and it is extended rather
than duplicated because the build plan names it as the vehicle for both.

**Cluster 11, "Who may do what: roles, grants and their provenance".**
Owner openregister, size L, 23 candidates, fourteen of them `must`, five
matrix holes, ledger row 11.46: C-access-and-privacy-1,
C-access-and-privacy-10, C-access-and-privacy-26, C-access-and-privacy-27,
C-access-and-privacy-28, C-access-and-privacy-33, C-access-and-privacy-36,
C-access-and-privacy-39, C-access-and-privacy-45, C-access-and-privacy-46,
C-access-and-privacy-47, C-access-and-privacy-49, C-access-and-privacy-52,
C-access-and-privacy-59, C-access-and-privacy-64, C-access-and-privacy-69,
C-access-and-privacy-73, C-access-and-privacy-79, C-access-and-privacy-80,
C-access-and-privacy-81, C-access-and-privacy-86, C-configuration-45,
C-decisions-9.

**Cluster 54, "Access compiled into the query".** Owner openregister, size
L, one candidate, C-access-and-privacy-62, a `must` and a matrix hole.

**The decisions.** **D22, option 1, openregister, "and it is not close":**
access is a property of the object, the object lives here, and a filter
that runs after the query has already leaked the count. The decision names
this change by name and says why: "compiled into the query" and "checked
on the result" are the same sentence in English and different products in
practice. **D10** contributes the half that touches this change: the
destruction verb is a named right rather than an administrator check,
which `delete-window-and-recorded-destruction` consumes from the
catalogue.

### The proving passers

- **dimpact-zac, opencase and valtimo**, all driven,
  `access-and-privacy.tsv:15`: "dimpact-zac: Search authorisation
  (search-and-indexing/spec.md)". Permission conditions are compiled into
  the query or the search index, so the engine does the filtering. Three
  independent passers for one idea.
- **dimpact-zac and nextcloud-deck**, driven, with jira-data-center
  documented, `access-and-privacy.tsv:18`: "nextcloud-deck:
  board#getUserPermissions /boards/{id}/permissions". The record is
  returned with the actions the current user may take on it. The
  candidate's clause calls it the single most reusable idea in ZAC, and
  names the alternative: a UI that guesses and a 403 the user discovers by
  clicking.
- **forgejo, gitea, opencase and request-tracker**, driven,
  `access-and-privacy.tsv:17`: "forgejo:
  /repos/{owner}/{repo}/collaborators/{c}/permission,
  /orgs/{org}/permissions, /user/permission (api.go)". Ask the product who
  holds which right on a named object, and where each grant came from.
  dossiq: "zero hits for an effective-permission endpoint".
- **glpi and opencase**, driven, `access-and-privacy.tsv:19`: "glpi: Rules
  over rights (front/ruleright.php)". A user's roles and scope are derived
  at login from what the identity provider asserts. The clause: it is how
  a municipality of two thousand people is authorised without anyone
  maintaining a matrix.
- **opencase**, driven, `access-and-privacy.tsv:63`: "Document detail
  Workflow (DocumentDetail-Workflow.md)". The workflow shares the file
  read only with each step's user, expiring on its deadline. The clause is
  the argument: an access grant created and revoked as a side effect of
  the work is the safest kind there is.
- **opencase**, driven, `access-and-privacy.tsv:79`: "Permission model
  (code-census.md)". Derived access is recalculated for everyone after the
  rule that derives it changes.
- **atabix**, documented, `access-and-privacy.tsv:91`:
  "/gestandaardiseerde-modules (Autorisatiebeheer)". The access situation
  as it stands, and every change to it, can be shown and accounted for.

### What this change gains

- **The filter is compiled into the query.** A grant, an inheritance and a
  deny become predicates in the SQL and in the search index, so a page, a
  total and a facet count are all computed over what the caller may see.
- **The record says what you may do with it.** An object read carries the
  actions the current user may take on it, resolved from the same
  evaluation, so a client stops guessing.
- **Provenance runs in the other direction too.** Ask an object who holds
  which right on it, with the rule behind each grant, and read the history
  of that set: who could see what, when.
- **Authorisation is derived from what the identity provider asserts.** A
  rule maps claims to roles and scopes at login, and the mapping is the
  same declared shape as any other rule.
- **A grant may expire, and a derived grant is recalculated.** A grant may
  carry an end, including one bound to a workflow step's deadline, and a
  change to a rule that derives access recalculates the derived grants and
  reports how many changed.
- **`manage` is scopeable to an area.** Delegated administration of a
  named part of the instance is a scoped `manage`, not a second
  administrator.

### What other changes already cover, so this one does not

- **Deny by default** (C-access-and-privacy-27):
  `rbac-default-deny-on-configured-authorization`.
- **Case type rights per department and role**
  (C-access-and-privacy-46, C-access-and-privacy-59, in part):
  `rbac-department-role-matrix`.
- **Rights inherited to children** (C-access-and-privacy-47, in part):
  `rbac-inherits-to-children`, which this change already depends on.
- **A masked identifier revealed by an audited click**
  (C-access-and-privacy-1): `sensitive-field-reveal-audit`, opened in this
  programme for ledger row 5.6.
- **Action-level permissions** (C-access-and-privacy-64): the catalogue of
  REQ-PPD-001, which is what makes a verb beyond read and write nameable.

### One earlier line revised

The original "Out of scope" says a time-boxed grant is dossiq's rule over
this primitive, not a second kind of rule here. The discovery sweep
measured a driven passer for a grant that expires with the workflow step
that created it (opencase, `access-and-privacy.tsv:63`), and a mandate
with an end date is the same shape. An end on a grant is now in scope, as
a property of the grant. What stays out is any rule about when a mandate
should end, which remains the consumer's.

### Still out of scope

- **An external policy engine** (C-access-and-privacy-52). D22 rejected
  it: a network call inside a query is the reason nobody in the corpus
  does it that way.
- **Competence declared, evidenced and approved before a right is
  granted** (C-access-and-privacy-26, documented only). That is a
  mandate-matrix rule in dossiq over this layer's grants.
- **Acting as another user** (C-access-and-privacy-39). Impersonation
  needs an audit trail more than it needs the feature, and it is a change
  of its own.
- **The handling officer's name hidden from the requester**
  (C-access-and-privacy-73). That is field-level security plus a portal
  projection, not a grant.
- **An access request as its own object** (C-decisions-9), which is
  dossiq's `woo-case-type` question.
- **One authorisation surface across every application in the
  organisation** (C-access-and-privacy-86, documented only). A fleet
  surface, not a change to this layer.
- **The from, to and right of a transition on one screen**
  (C-configuration-45). `object-lifecycle` already carries the declarative
  per-transition authorization gate; the screen is dossiq's.
