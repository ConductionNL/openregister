# Processes and cases: one engine

OpenRegister runs two kinds of work on one engine. A process (BPMN) is a fixed route: the flow engine walks it step by step. A case (CMMN) is adaptive work: a case plan of stages, tasks and milestones that open when their conditions are met. You can use either, or both on the same object.

## What both share

Processes and cases use the same building blocks. You learn them once.

| Building block | Used by processes | Used by cases |
|---|---|---|
| Tasks for people (inbox, assignees, mandates) | a user task step | a human task in the plan |
| Deadlines on the working calendar | a timer on a task or run | a `dueAt` or `expiresAt` on a plan item |
| Conditions | step conditions in JSONLogic | entry and exit criteria in JSONLogic |
| Events | flow triggers | on-parts of a sentry |
| The subject | the object a run is about | the object the case plan is on |

The only thing each keeps for itself is its own state: a run keeps its position in the route, a case keeps one row per plan item.

## How they call each other

- **A case starts a process.** A stage or a `processTask` plan item can name a flow. When the item becomes active, OpenRegister starts that flow on the case object. When the run ends, the item completes or ends with it.
- **A process opens or moves a case.** The step **Open a case** creates a case plan on the object the step receives. The step **Advance a case** moves one plan item, for example to reach a milestone. A move the plan does not allow stops the step with the reason.

## Deadlines on plan items

Give a plan item a `dueAt` and OpenRegister arms a timer when the item becomes active. The timer lands exactly on that moment and shows up where task deadlines show up. An `expiresAt` arms an expiry timer. It only ends the item when the plan lists the item under `statutory`; otherwise it reminds. Completing the item cancels its timers.

## For app developers

Code in your own app that runs without a signed-in user (a listener, an `occ` command, a repair step) uses three PHP methods on `CasePlanService`:

- `createPlanAsSystem(objectUuid, registerId, schemaId, definition, app)` starts a case plan.
- `getPlanAsSystem(objectUuid, app)` reads it.
- `ensureItems(objectUuid, registerId, schemaId, definition, history, app)` brings a plan over from another engine. It creates only the items the object does not have yet, keeps the state each item carries, and imports history marked as imported. Running it twice changes nothing the second time.

The audit names your app as `system:<app>`. These methods are not reachable over HTTP. Signed-in users keep using `/api/cases`.
