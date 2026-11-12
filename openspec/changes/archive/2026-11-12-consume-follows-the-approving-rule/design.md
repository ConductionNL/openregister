# Design: consume-follows-the-approving-rule

No screen.

## D-1: the task carries its approving set

`consume` reads one task. Looking up the task's sequence for its set would give `consume` a second source of truth and a second failure mode. So the set travels with the task: a sequence copies its chain's declared `approvingOutcomes` onto each position's `templateSnapshot` when it provisions the position. A task outside a sequence carries the key only when a trusted in-process caller imports it; the task routes refuse it (`sequence-fields-are-set-by-the-sequence-service-only`). A task that declares nothing uses the default set, `approved` and `done`, the same default sequences use.

## D-2: three refusals, told apart

- Not completed, or completed with a rejecting outcome: "is not an approving decision", as today.
- Completed with an outcome that neither approves nor rejects: "is undecided", naming the outcome.
- Already consumed: refused as before.

A rejecting outcome is never reported as undecided, also when a declared set names it.

## D-3: one normaliser

The set is normalised by `ApprovingOutcomeSet`, the same value object the chain compiler and the sequence use. `TaskState::approvingSetOrDefault()` adds the default fallback once, for both `consume` and the sequence.
