# Tasks: one-engine-bpmn-and-cmmn

Small PRs, in this order. PR 1 is what dossiq's drain waits on.

## 1. System access and convergent ensure (PR 1)

- [ ] 1.1 Migration `Version1Date20261010120000`: column `imported` (boolean, not null, default false) on `openregister_case_item_audit`; `CaseItemAudit` gains `imported`; `CaseItemAuditMapper` keeps a supplied `created` for imported rows. Bump `appinfo/info.xml`.
- [ ] 1.2 `CasePlanService::createPlanAsSystem()` and `getPlanAsSystem()`: app id validated, actor `system:<app>`, no authorization; unit tests for the main path and a refused app id.
- [ ] 1.3 `CasePlanService::ensureItems()` per design section 3.6, in a new collaborator `CasePlanEnsurer` with its own unit test: missing-only insert, recorded states, lifecycle check of each state, anchor mismatch refused, audit only for created rows, history imported with `imported: true`, no realisation, no cascade, double run idempotent.
- [ ] 1.4 Structural test: no file under `lib/Controller/` references `AsSystem` or `ensureItems`.

## 2. One state list and the process task (PR 2)

- [ ] 2.1 `CaseItem::STATES` / `TERMINAL_STATES` reference `Task::STATES` / `TERMINAL_STATES`; unit test asserts identity.
- [ ] 2.2 `CaseItem::TYPE_PROCESS_TASK`; `CasePlanTransitions` work-item edges; `CasePlanDefinition` accepts it, refuses one without a flow or with children.
- [ ] 2.3 `CaseRealisationService::realise()` queues the run for a process task; `CaseRunTerminalListener` drives it as for a stage; unit tests.

## 3. Plan-item timers on the shared clock (PR 3)

- [ ] 3.1 `FlowTimer::SUBJECT_TYPES` gains `case-item`.
- [ ] 3.2 `CasePlanItemTimers` (new, own unit test): arm on entering active with `dueAt` / `expiresAt`; cancel on terminal; arming failure logged, never thrown.
- [ ] 3.3 Wire it into `CasePlanStateMachine` transitions; listener for `FlowTimerFiredEvent` (expiry, subject `case-item`) terminates the item; unit tests.

## 4. Flow nodes that open and advance a case (PR 4)

- [ ] 4.1 `CaseOpenNode` (`openregister.case-open`), own unit test: creates on the run's subject as the run's identity; existing plan reported on output.
- [ ] 4.2 `CaseAdvanceNode` (`openregister.case-advance`), own unit test: transitions by key; refusal fails the step with the engine's message.
- [ ] 4.3 Register both in `FlowNodeRegistry`; node catalogue labels in every shipped locale.

## 5. Close

- [ ] 5.1 Engine docs: one page on the shared core and the two model types (docs/).
- [ ] 5.2 Sibling ask for dossiq: move `CasePlanProjectionService` / `CasePlanRollbackService` to `createPlanAsSystem` / `getPlanAsSystem`, and the drain to `ensureItems`.
- [ ] 5.3 opsx-verify, then archive into `openspec/specs/flow-cases/spec.md`.
