# Approval events: the replacement mapping

The four `ApprovalStep*Event` classes are removed by
`flow-approval-consolidation`. No event is re-emitted, aliased or wrapped. A
consumer that has not migrated stops receiving events at deploy, visibly,
instead of receiving prompts it can no longer answer.

This page is the normative mapping for consumers. filinq's
`migrate-signing-to-or-tasks` change migrates against it.

## Per event

### ApprovalStepInitiatedEvent

A step became `pending`. Replacement: a task of the sequence becoming
`enabled`. Subscribe to the task lifecycle and filter on `sequenceUuid`, or
read the enabled position through the task inbox.

| Retired field | Replacement |
|---|---|
| `chain` | The sequence: `templateId`, `chainKey`, `schemaId` and the frozen `templateSnapshot`. |
| `step` | The enabled task. Ordinal in `sequencePosition`, role in `candidateGroups[0]`. |
| `objectUuid` | `task.objectUuid`, also `sequence.anchorObjectUuid`. |

Ordering: the next position is enabled in the same request as the approving
decision, before that request returns.

### ApprovalStepApprovedEvent

Replacement: a sequence task completing with an approving outcome
(`TaskTerminalEvent` with `isCommitted() === true`, state `completed`,
outcome not in the rejecting vocabulary).

| Retired field | Replacement |
|---|---|
| `chain` | The sequence, as above. |
| `step` | The completed task: `completedBy`, `completedAt`, `comment`. |
| `userId` | `task.completedBy`. The audit entry also carries `onBehalfOf` and `mandate`, which the retired event never had. |
| `statusOnApprove` | Resolved from the frozen snapshot; carried on `TaskSequenceCompletedEvent` for the final position. Mid-sequence it is readable from the position's `templateSnapshot`. |
| `nextStep` | The newly enabled task, readable from the sequence after the same request. No replacement carries it inline; named as such. |

### ApprovalStepRejectedEvent

Replacement: a sequence task completing with a rejecting outcome. The
comment is mandatory and is on `task.comment`.

| Retired field | Replacement |
|---|---|
| `chain`, `step`, `userId` | As above. |
| `statusOnReject` | `sequence.outcome` after the rejection closed the sequence. |

Ordering: the sequence closes and every remaining task is terminated in the
same request as the rejecting decision.

### ApprovalStepCompletedEvent

Replacement: `TaskSequenceCompletedEvent`, dispatched at exactly the same
moment: the final position completing with an approving outcome.

| Retired field | Replacement |
|---|---|
| `chain` | `event.getSequence()`. |
| `finalStep` | `event.getFinalTask()`. |
| `userId` | `event.getDecider()`. |
| `statusOnApprove` | `event.getStatusOnApprove()`. |

## The reply path

The retired listener contract was a loop: filinq consumed the events and
called `ApprovalService::approveStep` or `rejectStep` back. The reply path
is now `TaskService::complete()` with an approving or rejecting outcome, or
the task routes over HTTP. Deciding an already terminal task is refused with
a reason naming its terminal state.

## What to do

Register a listener for `TaskTerminalEvent` and `TaskSequenceCompletedEvent`,
filter on your own sequences, and reply through the task verbs. The
retirement inventory in
`tests/fixtures/approval-consolidation/retired-approval-surface.json` lists
every removed route and class your app must no longer touch.
