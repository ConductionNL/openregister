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
