---
kind: code
depends_on: [a-sequence-advances-only-on-an-approving-outcome]
---
# Proposal: consume-follows-the-approving-rule

## Summary

The `consume` verb records that the work an approval authorized has run. Today it accepts any completed task whose outcome does not reject, so an empty, unknown or `resolved` outcome counts as an approval that work may rely on. Ruben decided on 3 Nov 2026 that `consume` follows the same approving rule as task sequences. Only an outcome in the approving set approves. Any other completion is refused as undecided.

## What is there

- `lib/Service/Task/TaskService.php` `consume()`: refuses a task that is not completed or whose outcome rejects, then records `consumedAt` and `consumedBy`.
- `a-sequence-advances-only-on-an-approving-outcome` defines the approving set for sequences: the chain's declared `approvingOutcomes`, or `approved` and `done`. The set is frozen on the sequence, not on its tasks.
- No production code calls `consume` yet. It is the home of openconnector's `consumedAt` semantic.

## What changes

- `consume` accepts a completed task only when its outcome is in the task's approving set: the task's own `templateSnapshot.approvingOutcomes`, or `approved` and `done` when it declares none.
- A completed task with a rejecting outcome is refused as before, as not an approving decision.
- A completed task with any other outcome is refused as undecided, naming the outcome.
- A sequence copies its chain's declared `approvingOutcomes` onto each position task's `templateSnapshot`, so a sequence task carries the same set the sequence advances on.

## ADRs

- ADR-098: one task store; an approval is a task with an outcome.

## Impact

- Amends `flow-approval-consolidation`.
- Affected code: `lib/Service/Task/TaskService.php`, `lib/Service/Task/TaskSequenceService.php`, `lib/Service/Task/TaskState.php`.
- No caller in the fleet consumes an approval yet, so no app changes.
- Size: S.
