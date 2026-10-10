# Tasks: one-engine-bpmn-and-cmmn

Small PRs, in this order. PR 1 is what dossiq's drain waits on.

## 1. System access and convergent ensure (PR 1)

- [x] 1.1 Migration `Version1Date20261010120000`: column `imported` (boolean, nullable for Oracle, default false) on `openregister_case_item_audit`; `CaseItemAudit` gains `imported`; `CaseItemAuditMapper` already keeps a supplied `created`. Bump `appinfo/info.xml`. (lib/Migration/Version1Date20261010120000.php, tests/Unit/Migration/Version1Date20261010120000Test.php)
- [x] 1.2 `CasePlanService::createPlanAsSystem()` and `getPlanAsSystem()`: app id validated, actor `system:<app>`, no authorization; unit tests for the main path and a refused app id. (CasePlanServiceTest::testSystemCallersActAsANamedApp)
- [x] 1.3 `CasePlanService::ensureItems()` per design section 3.6, in a new collaborator `CasePlanEnsurer` with its own unit test: missing-only insert, recorded states, lifecycle check of each state, anchor mismatch refused, audit only for created rows, history imported with `imported: true`, no realisation, no cascade, double run idempotent. (lib/Service/Case/CasePlanEnsurer.php, CasePlanEnsurerTest, CasePlanServiceTest::testEnsureItemsBringsAPlanOverAsTheApp)
- [x] 1.4 Structural test: no file under `lib/Controller/` references `AsSystem` or `ensureItems`. (CaseSystemVerbsUnroutedTest)

## 2. One state list and the process task (PR 2)

- [x] 2.1 `CaseItem::STATES` / `TERMINAL_STATES` reference `Task::STATES` / `TERMINAL_STATES`; unit test asserts identity. (CaseProcessTaskTest::testPlanItemsAndTasksShareOneListOfStates)
- [x] 2.2 `CaseItem::TYPE_PROCESS_TASK`; `CasePlanTransitions` work-item edges; `CasePlanDefinition` accepts it, refuses one without a flow or with children. (CaseProcessTaskTest, CasePlanTransitionsTest matrix)
- [x] 2.3 `CaseRealisationService::realise()` queues the run for a process task; `CaseRunTerminalListener` drives it as for a stage (the cascade's realisation check is type-agnostic, `CasePlanCascade.php:223`); unit tests. (CaseProcessTaskTest::testAProcessTaskQueuesItsFlow)

## 3. Plan-item timers on the shared clock (PR 3)

- [x] 3.1 `FlowTimer::SUBJECT_TYPES` gains `case-item`. (CasePlanItemTimersTest::testCaseItemIsATimerSubject)
- [x] 3.2 `CasePlanItemTimers` (new, own unit test): arm on entering active with `dueAt` / `expiresAt`; cancel on terminal; arming failure logged, never thrown. (lib/Service/Case/CasePlanItemTimers.php, CasePlanItemTimersTest)
- [x] 3.3 Wire it through `CaseItemTimerListener` on `CaseItemTransitionedEvent` (no change to the state machine) and `FlowTimerFiredEvent` (expiry, subject `case-item`) -> `CasePlanService::onTimerExpired()` terminates the item; unit tests. (CaseItemTimerListenerTest, CasePlanServiceTest::testAnExpiredTimerTerminatesItsItem)

## 4. Flow nodes that open and advance a case (PR 4)

- [x] 4.1 `CaseOpenNode` (`openregister.case-open`), own unit test: creates on the run's subject as the run's identity; existing plan reported on output (`CasePlanExistsException`). (lib/Service/Flow/Nodes/CaseOpenNode.php, CaseNodeBase.php, CaseFlowNodesTest)
- [x] 4.2 `CaseAdvanceNode` (`openregister.case-advance`), own unit test: transitions by key; refusal fails the step with the engine's message. (lib/Service/Flow/Nodes/CaseAdvanceNode.php, CaseFlowNodesTest)
- [x] 4.3 Register both through `FlowNodeRegistrationListener` (FlowNodeRegistrationListenerTest: 29 built-ins) and the declared taxonomy table (FlowNodeDeclaredTaxonomyTest). Palette strings follow every other node: through IL10N, not in the backend catalogue (no built-in node's strings are in `l10n/en.json`; adding them would oblige all required locales at once).

## 5. Close

- [x] 5.1 Engine docs: one page on the shared core and the two model types (docs/features/processes-and-cases.md).
- [x] 5.2 Sibling ask for dossiq (filed in for-ruben/dossiq-sibling-asks.md, 10 Oct): move `CasePlanProjectionService` / `CasePlanRollbackService` to `createPlanAsSystem` / `getPlanAsSystem`, and the drain to `ensureItems`.
- [x] 5.3 opsx-verify (every scenario maps to a unit test named above; all scenarios are `@e2e exclude` with reasons), then archive into `openspec/specs/flow-cases/spec.md`.
