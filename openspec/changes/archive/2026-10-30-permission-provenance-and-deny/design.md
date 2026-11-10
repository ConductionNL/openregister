# Design: permission-provenance-and-deny

## D-1. A catalogue, because a set nobody can read cannot be granted

The five canonical verbs are in the spec, `manage` is in the spec, and a
custom verb exists only as a vote at evaluation time. An administrator
opening a role editor has nothing to offer, which is why every consumer
in the fleet invents its own vocabulary. `GET /api/permissions` returns
one list: verb, the app that declared it, the scope levels it may be
granted at, and a sentence in plain language. A verb absent from the
catalogue cannot be named in an authorization block.

## D-2. Declaration and evaluation stay separate

`CustomScopeEvaluatingEvent` keeps deciding custom verbs, first vote
wins, unchanged. What is new is that an app declares the verb up front so
it can be listed and audited. Declaration without an evaluator refuses
the verb, fails closed, and says which app owes a listener. An evaluator
without a declaration is a configuration error surfaced in the RBAC
settings, not a silent grant.

## D-3. Deny wins, and that is the whole precedence rule

Most-specific-wins resolves between grants. A deny is not a grant with a
lower score: it removes the verb inside its scope, and no broader grant
puts it back. The alternative, letting a more specific grant override a
broader deny, gives an administrator two rules to reason about at once
and is how a deny quietly stops working.

Two consequences to write down rather than discover:

- An inherited grant from an ancestor object is subject to a deny on the
  descendant. That is the case `rbac-inherits-to-children` deliberately
  left open, and the reason it gave (no competitor has one) no longer
  holds.
- A deny and a grant written at the same level on the same principal is a
  configuration error, refused at save with both rules named. Resolving
  it silently either way teaches nobody.

## D-4. Administration cannot be denied away

`manage` on a register cannot be denied to the last principal holding it.
The check runs at save time, names what would be orphaned, and refuses.
An instance whose authorization cannot be edited any more is recoverable
only from the database, and the group-provisioning requirement in
`rbac-scopes` exists because silent lockouts already cost us once.

## D-5. Provenance rides the same resolution

The resolver already walks register default, schema rule, role, per-object
grant and, since `rbac-inherits-to-children`, the ancestor chain. It knows
which step answered; it simply does not say. Each granted action carries
the rule that produced it, and each absent action that a broader rule
would have granted carries the deny that removed it. The cost is a field
on a decision that already exists, not a second pass (ADR-009).

`GET /api/scopes` keeps its envelope. `actions` stays a list of strings
so existing feature gates keep working, and `provenance` is added beside
it, keyed by action.

## D-6. The list path denies too

Every deny term goes into `MagicRbacHandler`'s SQL filter as well as the
per-object check. A denied object that still appears in a list is the
worst of both answers: the caller sees the row, learns the identifier and
is refused on the read.

## D-7. What the consumer gets first

dossiq's half needs only the catalogue to start: the mandate matrix can
name its grantable set the day `GET /api/permissions` answers, before any
deny exists. Building the catalogue first therefore unblocks the consumer
halfway through this change rather than at the end of it.

## D-8. Compiled into the query, because a post-filter has already lied

A permission check that runs on the result set gives a correct page of
wrong data: the total is wrong, the facet counts are wrong, and page three
is missing rows that page two should have shown. The grant, the
inheritance and the deny become predicates, in the SQL and in the search
index, and the same evaluation produces both. This is D22's whole point,
and it is why the decision says the two readings are the same English
sentence and different products.

## D-9. The record answers what you may do with it

Returning the permitted actions with the object costs one resolution that
has already happened. Not returning them costs every client a guess, and
the user finds out by clicking into a 403. The list is the resolved verbs
for this caller on this object, from the same pass that decided the read.

## D-10. Provenance reads both ways

"What may I do here" and "who may do this here" are two directions of one
index. The second is the auditor's question and the one no product in the
corpus was asked. It returns principals with their verbs and the rule
behind each, and its history answers who could see what, when.

## D-11. An end on a grant, and a recalculation when a rule moves

A grant with an end date is a property of the grant, evaluated at
resolution time, so an expired grant needs no job to take it away. A
derived grant is different: when the rule that derives it changes, the
derivation is re-run and the number of changed grants is reported, because
an access change nobody is told about is the one that surprises an
auditor.

## D-12. Staging first: a deny is recorded before it refuses anything

Decision D15, 2026-09-14. The deny does not ship enforcing.

Everything above this section describes what a deny does once it bites. This
section says when it starts biting, and the answer is: not on the day it is
installed. A deny is the first rule in this layer that takes a right away.
Every other rule adds, so the worst a mistake could do was hand somebody a
right they should not have had, which an audit finds. A deny inverts that.
A mistake now locks a case worker out of the dossier they are paid to handle,
at nine in the morning, with no clue why, because the only visible evidence is
an absence.

So enforcement is a second switch, and an administrator reaches it on purpose.

### The three states

`openregister.deny_enforcement` takes one of three values.

| value | the deny pass | what a caller sees |
|---|---|---|
| `off` | skipped | nothing changes, at any cost |
| `staging` | evaluated, recorded, not applied | the grant stands, the provenance names the deny that would have removed it |
| `enforcing` | evaluated and applied | the verb is gone, and the provenance says which rule took it |

The default is `staging`. That is deliberate and it is free: an instance that
writes no deny has nothing to evaluate, so the pass costs one array lookup and
records nothing. An instance that does write one gets a week of reading what
its rules would do before a single user is refused.

`off` exists for the incident, not for the rollout. When a deny is refusing
people it should not refuse, an administrator needs one value to set that stops
it, without editing rules under pressure and without a deploy.

### What "recorded" means, and what it deliberately is not

A staged deny is recorded in three places, and none of them is a new table.

1. **The log.** One structured warning per staged denial, carrying the rule,
   the principal it names, the verb, the schema and the caller. It is greppable
   and it survives the request.
2. **The provenance.** The action stays in `actions`, and its provenance entry
   carries `stagedDeny` with the rule. So the same field that answers "why can
   this person do this" answers "and what is about to stop them".
3. **The preview.** `GET /api/permissions/deny-preview` reads the rules as
   written and reports what enforcement would refuse, for a named principal or
   for every principal a rule mentions. It is computed from the rules on the
   spot, not accumulated.

A table was the obvious alternative and it is the wrong one. Accumulated
observations answer "what did fire" and stop there: the denies nobody exercised
yet are missing, which is precisely the set that will surprise an administrator
on the day they flip the switch. The preview reads the rules instead, so a
deny that has never been hit is in the report on the day it is written.

### Where the modes are read, and why only there

The mode is read at the two enforcement points and nowhere else, so staging
and enforcing cannot drift apart:

- `PermissionHandler`, which decides one object.
- `MagicRbacHandler`, which compiles the list query.

Both call `DenyEnforcementMode`. In `staging`, the resolver still runs and
still names the denial, because the record is the whole point. What changes is
that the verdict is not used: the object read returns the row and the list query
gets no deny predicate. A staging mode that skipped the resolution would record
nothing, which is a dry run in name only.

The save-time refusals are not staged. A block that grants and denies one verb
at one level, and a deny that would orphan `manage`, are refused at save in
every mode, because they are contradictions in the rules rather than effects on
a user. Writing a broken rule and discovering it a month later at the switch is
the outcome staging exists to prevent.

### What to do next

Write your denies. Leave the instance on `staging`, read
`GET /api/permissions/deny-preview` and the provenance on the surfaces you care
about, and set `openregister.deny_enforcement` to `enforcing` when the report
holds no surprise.
