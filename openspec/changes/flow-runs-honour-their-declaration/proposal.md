---
kind: code
depends_on: [object-level-sharing-and-private-scope, flow-engine-unification]
---

# Proposal: flow-runs-honour-their-declaration

## Summary

`lib/Settings/flow_register.json` declares `scope: private` on the `flow`
schema. Nothing that runs a flow reads that store. The declaration therefore
governs a store the run path never touches, and a reader of it would believe
running a flow answers to its owner when it answers to an organisation and a
right seeded `@authenticated`.

## Where this came from

Task 9.1 of `object-level-sharing-and-private-scope` gave flows read
authorization by declaring `scope: private` plus explicit verbs for
`authenticated` on the `flow` schema, and recorded that as done. It is done,
for the store it names. Measured 2026-09-18 while re-measuring that change's
remaining tasks (openregister#3932):

- flows live in the native `openregister_flows` table, behind `FlowMapper`,
  a `QBMapper`;
- `MigrateRegisterFlowsToTable` exists precisely because "OpenRegister kept
  TWO stores for a flow ... Every subsystem reads the table; nothing reads
  the register", and it drains the register into the table;
- every run path resolves through `FlowService::find()`, whose only per-flow
  check is `Flow::belongsTo($activeOrganisation)`.

So the control was declared on the store that was deliberately emptied.

## What a reader of that declaration would have had

Somebody opening `flow_register.json` reads `"scope": "private"` and takes
from it what `ObjectScopeResolver` means by it: **owner, administrators and
invited principals only**. They would conclude that a colleague cannot run a
flow they do not own, and that narrowing access to a flow narrows who can
execute it.

Neither is true today. `FlowController::run()` requires `flow.run`, which
`lib/actions.seed.json` seeds `@authenticated`; the flow is then resolved by
organisation. On the single-organisation instance that is the common case,
**any signed-in user can run any flow**, including one they neither own nor
may edit. `FlowRunController`'s own docblock states that exposure in those
words (or#3643) and answers it for `test()` alone, by requiring the global
`flow.update` right instead. `retry()` and `FlowMcpToolProvider::runFlow()`
require neither.

## The row this serves

This closes no ledger row of its own. It is the correction of a control that
row 13.3's change (`object-level-sharing-and-private-scope`, task 9.1)
reported as delivered, and it is filed as its own change rather than as an
edit to that one because a control that was believed to exist and did not is
worth a proposal somebody can read.

## What changes

- One resolver answers "may this principal run this flow", and every run
  path consults it: `FlowService::run()` (which `FlowController::run()`
  calls), `FlowRunController::test()`, `FlowRunController::retry()` and
  `FlowMcpToolProvider::runFlow()`.
- The rule: an administrator may; the flow's owner may; a caller holding the
  narrowable `flow.update` right may; nobody else may. A flow with **no
  owner** may not be run by anyone, which agrees with `Flow::canDispatch()`,
  the engine's own refusal to dispatch an unowned flow.
- The declaration in `flow_register.json` says what it governs, so the next
  reader is not told something untrue by a file.
- The stale sentence in `object-level-sharing-and-private-scope`'s task 9.2
  — "all three run a flow with zero ownership checks today" — is corrected,
  because a task file that says something untrue is the same class of
  failure as a comment claiming coverage elsewhere.

## What this does NOT claim

On the shipped seed, `flow.update` is also `@authenticated`. So on a fresh
instance the *default* posture does not change, and this must not be sold as
though it did. What changes is that the control an administrator already
believes they hold starts working: before this, narrowing `flow.update` left
`run()`, `retry()` and the MCP tool wide open; after it, narrowing that right
governs every run path. The seeded default stays open deliberately, for the
reason `specs/flow-engine` already gives about not locking out existing
authors on upgrade — a breaking change wearing a feature's clothes.

The genuine tightening with no legitimate loser is the unowned flow, which
every path now refuses.

## ADRs

- ADR-005 (security): a control that cannot be evaluated is a refusal. A
  control that is declared where nothing reads it is worse, because it
  reports success.
- ADR-022: the decision lives in the platform, in one place, and every door
  consults it.
- ADR-010 rule 4: running is an extension verb, enforced at the endpoint
  that performs the action rather than by widening the RBAC vocabulary.

## Size

S. One resolver, four call sites, one descriptor correction.
