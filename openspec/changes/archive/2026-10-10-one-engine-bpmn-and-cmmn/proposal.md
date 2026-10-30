---
kind: code
depends_on: [flow-cmmn-case-semantics, flow-business-timers, flow-task-entity]
---

# Proposal: one-engine-bpmn-and-cmmn

## Summary

OpenRegister runs one engine with two model types. A BPMN process is walked
by the flow engine; a CMMN case plan is evaluated by the case layer. Both
stand on one shared core: the task service, the timer core and working
calendar, the event catalog and the one condition dialect, the subject
anchor, and the audit. This change names that boundary, closes the gaps that
still make the two feel like separate engines, and adds what dossiq's
`retire-cmmn-caseplanstate` needs to drain its own CMMN runtime into it.

## Why

Ruben (decision 146): "can we make the cmmn engine and the flow engine one
thing that supports both? feels like a lot of overlap."

An inventory of `development` (design.md section 1) shows most of the
overlap is already resolved, because `flow-cmmn-case-semantics` built the
case layer on the flow engine's primitives instead of beside them:

- if-parts are evaluated by `FlowExpression`, the flow engine's evaluator;
- on-parts are events from `EventCatalogService`, the flow engine's catalog;
- a human plan item is realised by a task from `TaskService`, the same row a
  BPMN user task creates, with the same six states;
- a stage may be realised by a flow run queued through `FlowRunService`;
- a plan and a run name their subject with the same anchor triple.

What is left is real, and it is what makes the two read as two engines:

1. **CMMN can only start BPMN from a stage.** CMMN 1.1's ProcessTask (a plan
   item whose work is a process) does not exist; a caseworker cannot draw
   "this step is the advice flow" without wrapping it in a stage.
2. **BPMN cannot touch a case.** No flow node opens a case plan or advances a
   plan item. A process that should reach a milestone has to call HTTP.
3. **Plan items have their own deadline fields and no clock.** `dueAt`,
   `expiresAt`, `doorlooptijd` and `servicenorm` are stored on the row and
   nothing arms a timer for them. Tasks and runs already run on
   `FlowTimerService`; plan items do not.
4. **The six states are declared twice.** `CaseItem::STATES` and
   `Task::STATES` are two literal lists that happen to agree.
5. **An app's own code cannot act on a case without a user.** Listeners,
   `occ` commands and repair steps run without a session. Every case-layer
   verb refuses a null identity, so dossiq's case-start projection
   (`CasePlanProjectionService`, merged in dossiq #2551) is refused on every
   call and only logs it. No caller can say "this is app X acting".
6. **A case plan cannot be brought over from another engine.** `createPlan`
   refuses an object that already has rows and starts every item in
   `available`. Draining an existing runtime needs the opposite: create only
   the missing items, keep their recorded states, and import their history
   marked as imported, so a crash mid-case resumes without duplicates.

## What changes

- **Shared core, named.** design.md section 2 fixes the boundary: a model
  evaluator owns its state shape (the BPMN marking, the plan-item rows) and
  nothing else. It SHALL NOT own a task store, a clock, a condition dialect,
  an event vocabulary or an authorization model.
- **ADD** plan-item type `processTask`, realised by a flow run, with the
  work-item lifecycle. CMMN starts BPMN from any work item, not only a stage.
- **ADD** two flow nodes: `openregister.case-open` creates a case plan on the
  run's subject; `openregister.case-advance` transitions a plan item of the
  run's subject by its key. BPMN opens and advances cases.
- **ADD** plan-item timers on the shared clock: an item with `dueAt` or
  `expiresAt` arms a `FlowTimer` (subject type `case-item`) when it becomes
  active; the timer is cancelled when the item ends; an enforcing expiry
  terminates the item. No case-local clock.
- **CHANGE** `CaseItem::STATES` and `TERMINAL_STATES` to reference the task's
  lists, so one declaration governs both.
- **ADD** in-process system access: `createPlanAsSystem`, `getPlanAsSystem`
  and `ensureItems` on `CasePlanService`, each naming the acting app and
  recorded in the audit as `system:<app>`. Never routed; a structural test
  holds that no controller reaches them.
- **ADD** `ensureItems`: convergent, keyed on (object uuid, item key), creates
  only missing items with their recorded states, appends audit entries only
  for rows it created, and imports supplied history as audit entries flagged
  `imported`. Re-running it changes nothing.
- **ADD** column `imported` (boolean, default false) on
  `openregister_case_item_audit`.

## Out of scope

- Merging the plan-item rows and the run marking into one table. Each
  evaluator keeps its state shape; design.md section 4 says why, and
  Q-openregister-C1 asks Ruben to confirm.
- dossiq's own projection, migration and removal: dossiq repoints its callers
  after this lands (dossiq `retire-cmmn-caseplanstate`).
- CMMN XML. `flow-cases` already refuses the notation; that stands.

## Impact

Additive only. One new column with a default, one new value in
two enum lists (`CaseItem::TYPES`, `FlowTimer::SUBJECT_TYPES`), two new flow
nodes, new service methods. No existing flow, run, task, timer, case-item or
audit row is rewritten, and no existing verb changes its answer.

## Affected projects

- [x] `openregister`: this change.
- [ ] `dossiq`: consumer. `retire-cmmn-caseplanstate` moves its calls to the
      system methods and `ensureItems` (sibling ask filed).
