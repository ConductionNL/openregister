# Design: flow-runs-honour-their-declaration

## D-1: move the control to where the read happens, not the read to the control

Three ways to close this were available: make the run path read through the
object store the declaration governs, move the declaration to the store the
run path reads, or have both stores consult one resolver.

The first is refused outright. `MigrateRegisterFlowsToTable` drained the
register into the table *because* nothing read the register, with measured
controls — a flow authored there never fired and never bundled. Pointing the
run path back at it would re-create the store that change removed.

So: one resolver, consulted by every run path, expressing the semantics the
declaration promised, over the store the run path actually reads.

## D-2: one method, four callers, and the seam is the service

`FlowService::run()` is the seam `FlowController::run()` already uses, and
`find()` is the seam the other three already use. The resolver goes beside
them, so a run path that forgets to ask is a run path that also forgot to
resolve the flow — which is not a thing any of them can do.

## D-3: an unowned flow is refused, and that is not new policy

`Flow::canDispatch()` already returns false for a flow with no owner: an
imported flow arrives inert on purpose, and adoption is the deliberate act
that makes it somebody's. A run request against an unowned flow can therefore
only fail — except on `test()`, which executes synchronously and would run
it. Refusing it at the door makes every path agree with the engine.

## D-4: `flow.update` stays the bar for running somebody else's flow

Not `flow.run`: that right is seeded `@authenticated` and says only that a
caller may trigger flows at all. `flow.update` is the right already required
for every other editing verb on a flow, it is narrowable by an administrator,
and `FlowRunController::test()` already picked it for exactly this reason.
Using a different bar in the new resolver would give two answers to one
question.

## D-5: the descriptor stops promising what it does not govern

The `authorization` block on the `flow` schema is left in place — the schema
still exists and a flow object could still be written — but it is annotated
with what it does and does not govern. Deleting it would be the second
mistake: a reader would then find no declaration at all and conclude the
store is open.

## D-6: reuse analysis (ADR-012)

- `FlowAccess::may()` and `callerIsAdmin()`: reused, unchanged.
- `Flow::belongsTo()`: reused as the organisation half.
- `Flow::canDispatch()`'s owner rule: reused as the unowned rule, so the
  door and the engine cannot disagree.
- No second right, no second organisation check, no second store.
