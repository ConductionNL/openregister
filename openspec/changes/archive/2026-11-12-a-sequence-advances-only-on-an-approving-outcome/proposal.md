---
kind: code
depends_on: []
---
# Proposal: a-sequence-advances-only-on-an-approving-outcome

## Summary

A task sequence moves to its next position only when a position completes with an approving outcome. Today `TaskSequenceService::onTaskTerminal` advances on every completed outcome that is not a rejection. An empty outcome, a misspelled one or `resolved` therefore counts as an approval. In a signing chain that asks the next signer to sign after a step that never approved. Ruben decided on 3 Nov 2026: "OpenRegister task sequences advance only on an approving outcome".

## What is there

- `lib/Service/Task/TaskSequenceService.php` `onTaskTerminal()`: a completed task whose outcome is not in `TaskState::REJECTING_OUTCOMES` calls `advance()`. `advance()` enables the next position, or completes the sequence and dispatches `TaskSequenceCompletedEvent`.
- `TaskState` publishes a rejecting vocabulary, but no approving one.
- The generic task screens complete a task with outcome `done`: OpenRegister's task detail page, nextcloud-vue's `CnTasksWidget` checkbox and form, and the calendar write-back (`TaskVtodoWriteBackGate`). `TaskController::complete` also defaults to `done`. Approval positions declare no outcomes of their own, so `done` is what an approver in the inbox sends today.
- Migrated approval chains and filinq's signing listener use `approved`.

## What changes

- An approving outcome is an outcome in the sequence's approving set. The default set is `approved` and `done`, compared trimmed and case-insensitive.
- A chain in `x-openregister-approval-chains` may declare `approvingOutcomes`, a list of outcomes. The compiler copies it into the template, so it is frozen onto every sequence it opens. A declared list replaces the default.
- A rejecting outcome still rejects, also when a declared list names it.
- A completed position whose outcome neither approves nor rejects ends the sequence as `undecided`. Every task the sequence still owns is terminated with a reason naming the position and the outcome. `TaskSequenceUndecidedEvent` announces it. Nothing is enabled, and `TaskSequenceCompletedEvent` is not dispatched.
- A gated transition treats an `undecided` sequence like a rejected one: the next attempt opens a new sequence beside it.

## Who is affected

| App | How it uses sequences | Pass outcome today | Effect |
|---|---|---|---|
| openregister | Declarative approval chains, migrated chains | `done` from the inbox, `approved` from migration | None: both are in the default set |
| shillinq | Declares `x-openregister-approval-chains` on commitments | `done` from the inbox | None |
| buildiq | Compiles automation approvals into chains, listens to `TaskSequenceCompletedEvent` and to rejecting `TaskTerminalEvent` | `done` from the inbox | None. An undecided sequence fires neither `onApprove` nor `onReject`, and its approval state reads `none` |
| opencatalogi | Reads the newest sequence for a Woo batch and publishes only on status `completed` | (reads only) | None: `undecided` refuses, as `terminated` does |
| filinq | Bridges sequence events into signer events; requires `approved` per signer | `approved` | Empty and unknown outcomes no longer enable the next signer. A signing chain should declare `approvingOutcomes: ["approved"]` so `done` stops advancing too (follow-up in filinq) |
| decidiq | Projects its own approval stages onto plain tasks without a sequence | n/a | None |

## ADRs

- ADR-098: one task store; an approval is an ordered task sequence.
- ADR-022: apps declare, OpenRegister runs the approval.

## Impact

- Amends `flow-approval-consolidation`.
- Affected code: `lib/Service/Task/TaskSequenceService.php`, `lib/Service/Task/TaskState.php`, the new `lib/Service/Task/ApprovingOutcomeSet.php`, `lib/Db/TaskSequence.php`, `lib/Service/ApprovalChainAnnotationInstaller.php`, the new `lib/Event/TaskSequenceUndecidedEvent.php`, `docs/development/approval-events-migration.md`.
- Backwards compatible for every outcome the fleet sends today. A position completed with an empty or unknown outcome no longer advances.
- Size: S.
