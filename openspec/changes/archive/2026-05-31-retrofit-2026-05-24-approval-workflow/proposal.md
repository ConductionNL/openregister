# Retrofit — approval-workflow

Describes observed frontend behavior of 2 Vue surfaces under the `approval-workflow` capability as 2 new REQs. Code already exists — this change retroactively specifies it.

The existing `approval-workflow` spec (REQ-001..REQ-005) describes the backend HTTP surface and state machine. The Vue components in `src/components/workflow/` are the canonical frontend for that surface and are not yet specified. This retrofit closes that gap.

## Affected code units

- `src/components/workflow/ApprovalChainPanel.vue::fetchChains()` — lists chains, filters by `schemaId` on the client.
- `src/components/workflow/ApprovalStepList.vue::fetchSteps()` — lists steps for one `objectUuid`, with inline approve/reject controls.

## Approach

- Treat the Vue panels as the documented UI surface for REQ-001 (chain CRUD) and REQ-005 (decide a step).
- Describe observed inputs, outputs, and side effects of `fetchChains` / `fetchSteps` (HTTP requests issued, client-side filtering, post-decide refresh).
- Leave the (currently broken) `canDecide()` always-true stub flagged in Notes — observed behavior is "client does not enforce", server enforces.

## Out of scope (drift from coverage batch)

The coverage batch for `approval-workflow` lumped in 25 additional methods that, on inspection, belong to **other** capabilities — they are not approval-workflow behavior. They are already covered by sibling specs and should NOT be retrofitted here:

- `ScheduledWorkflowController::*` + `BackgroundJob/ScheduledWorkflowJob::*` → belong under `workflow-integration` (scheduled-workflow surface).
- `WorkflowEngineController::*` (update, destroy, testHook, available, health) → covered by `workflow-engine-abstraction` REQs and `workflow-integration` engine-CRUD scenarios.
- `WorkflowEngineRegistry::*` (resolveAdapterById, getEngines, getEnginesByType, getEngine, createEngine, updateEngine, deleteEngine, discoverEngines) → covered by `workflow-engine-abstraction` (registry + adapter resolution + auto-discovery REQs).
- `HookForm`, `TestHookDialog`, `WorkflowExecutionPanel`, `SchemaWorkflowTab::editHook` → belong under `schema-hooks` and `workflow-integration` UI scenarios.

These should be picked up by separate retrofit runs targeting the correct capabilities. Forcing them into `approval-workflow` would inflate REQs and drift the capability boundary.

Source: `openspec/coverage-report.json` generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
