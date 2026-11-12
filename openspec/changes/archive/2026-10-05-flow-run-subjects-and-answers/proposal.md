---
kind: code
depends_on: []
---

## Why

Two gaps, found by asking one question of a working flow: *what did the person answer, and what is this run working on?*

**A user-task form's answers never reach the run.** `PortalTaskNode` line 450 puts them there: `$bag['answers'] = $task->getResponses()`. `FlowTaskBridge::outcomeBagFor()` — the bag `UserTaskNode` uses — has no `answers` key at all. It carries the outcome, the comment, the result text and the performer fields, and nothing the performer filled in.

So a step may declare a form (`formKind: fields`, `formFields`, `formAction`), the performer may fill it in, the values are validated against the subject schema and written to `task.responses`, and then they stop. No later step can route on them, read them, or write them anywhere. The feature exists end to end except for the last hop, and the portal node proves the hop is one line.

**A run records one object and touches many.** `FlowRun` carries `subjectUuid`, `subjectRegister`, `subjectSchema` — the thing that triggered it. `Task` carries one `objectUuid`. `GET /api/flow-runs/{uuid}/objects` exists but is derived from the audit trail: it answers *what did this run write*, which is a different question from *what is this run working on*.

The difference matters as soon as a step wants to attach something. A case flow creates a case, locks it, asks somebody for a go, and records the decision. The decision belongs to the person, to the run, **and to the case** — and there is no declared set for a node to point at. A node cannot say "attach this task to the case" because nothing names the case except the trigger, and by step nine the trigger may not be the object anyone means.

## What Changes

- **The outcome bag carries the answers.** `outcomeBagFor()` gains `answers` from `task.responses`, the way the portal node already does, so a Switch can route on what somebody filled in. **This is a bug fix, not a feature**: the values were being collected and discarded.
- **A run keeps a declared set of subjects.** `FlowRun` gains `subjects`: an ordered set of `{register, schema, uuid, role}` entries, where `role` is a short author-chosen name (`case`, `decision`, `document`). The trigger's subject is the first entry, with the role `trigger`, so a run with no declared subjects behaves exactly as it does now.
- **Nodes add to it, by declaration.** `openregister.object-write` and `openregister.lock-object` accept `subjectRole`; naming one records the object they acted on under that role. Nothing is added implicitly — an implicit set would be the audit-derived list again, and that already exists.
- **A task can attach to a subject.** `UserTaskNode` accepts `attachTo: <role>`. The task's existing `objectUuid`/`registerId`/`schemaId` are filled from that entry, so the attachment rides fields the task row and the inbox already have. Naming a role the run has not recorded fails the step, rather than creating a task attached to nothing.
- **`GET /api/flow-runs/{uuid}` serves the declared subjects**, distinct from `/objects`, which keeps its audit-derived meaning. Two questions, two answers, neither pretending to be the other.

## Capabilities

### New Capabilities
- `flow-run-subjects`: what a run's declared subject set is, how a node adds to it, how a role is addressed, and how it differs from the audit-derived objects a run touched.

### Modified Capabilities
- `flow-user-task-node`: the outcome bag carries the performer's answers; a step may attach its task to a declared subject.

## Impact

| Area | Change |
|---|---|
| `lib/Service/Flow/FlowTaskBridge.php` | `outcomeBagFor()` gains `answers` |
| `lib/Db/FlowRun.php` | `subjects` json column |
| `lib/Migration/` | the column, plus a repair seeding the trigger entry on open runs |
| `lib/Service/Flow/FlowRunSubjects.php` | new — reading, adding, addressing by role |
| `lib/Service/Flow/Nodes/ObjectWriteNode.php`, `LockObjectNode.php` | optional `subjectRole` |
| `lib/Service/Flow/Nodes/UserTaskNode.php` | optional `attachTo` |
| `lib/Controller/FlowRunController.php` | subjects on the run read |

## Out of scope, deliberately

- **Typed performers and the assignee picker** — `flow-typed-principals`, a different surface.
- **Changing what `/objects` means.** It is audit-derived and should stay so. A run's declared subjects and the objects it wrote are different facts, and collapsing them would lose the one an auditor needs.
