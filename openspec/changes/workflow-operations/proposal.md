# Proposal: workflow-operations

## Why

The shipped `workflow-engine-abstraction`, `workflow-integration`, `event-driven-architecture`, and `schema-hooks` capabilities give OpenRegister a working backend pipeline (HookExecutor, n8n/Windmill adapters, registry, typed events). What's still missing is the operator-facing surface government beheerders need: a configuration UI on schemas, scheduled triggers, multi-step approval chains, execution history with monitoring, and a safe dry-run. 38% of analysed tenders require workflow/process automation with these operator capabilities; without them, workflows can only be configured by developers and approval logic must be re-implemented per project.

## What Changes

- Add `WorkflowExecution` entity, mapper, and migration to persist hook execution results (status, durationMs, errors, payload, executedAt) — already implemented and tested
- Modify `HookExecutor` to persist execution history alongside its existing logger calls without failing the original hook on persistence error — already implemented
- Add `WorkflowExecutionController` with list/show endpoints (filter by objectUuid, schemaId, hookId, status, engine, since) and admin-only delete — already implemented
- Add `ScheduledWorkflow` entity, migration, and `ScheduledWorkflowJob` (TimedJob) that triggers workflows via the engine adapter on a configurable interval, with disabled/errored schedules skipped gracefully
- Add `ApprovalChain` and `ApprovalStep` entities for multi-step approval state per object with role-based step assignment and reject/advance hooks
- Add `ApprovalController` with chain CRUD and step approve/reject/status endpoints
- Add `WorkflowEngineController::testHook()` dry-run endpoint that executes a hook with sample data derived from the schema without database persistence
- Add Vue components: `SchemaWorkflowTab`, `HookForm`, `WorkflowExecutionPanel`, `ApprovalChainPanel`, `TestHookDialog`
- Note: 49 of 56 tasks complete; remaining 7 are the approval-chain UI, test-hook UI, and final integration polish

## Capabilities

### Modified Capabilities
- `workflow-integration`: Adds the operator-facing capabilities (configuration UI, scheduling, approval chains, execution history, dry-run) listed as "Not yet implemented" in the canonical spec

## Summary

Add the missing operational capabilities for OpenRegister's workflow integration: a workflow configuration UI for schema settings, scheduled workflow triggers via Nextcloud TimedJobs, a multi-step approval chain state machine, workflow execution history with a monitoring dashboard, and a "test hook" dry-run facility. These features close the gap between the implemented backend pipeline (HookExecutor, adapters, registry) and the end-user/admin experience needed for production use in government environments.

## Demand Evidence

**Cluster: Workflow/process automation** -- 38% of analyzed government tenders require workflow/process automation capabilities.
**Cluster: Approval chains** -- Government organisations universally require multi-step approval for permits, subsidies, and case handling.
**Cluster: Monitoring/observability** -- Functional administrators need visibility into workflow execution status without accessing server logs.

### Sample Requirements from Tenders

1. "Beheerders moeten zonder programmeerkennis workflows kunnen configureren en koppelen aan zaaktypen."
2. "Het systeem ondersteunt meervoudige goedkeuringsketens met escalatie bij termijnoverschrijding."
3. "Uitvoering van workflows moet traceerbaar zijn via een auditoverzicht in de beheerinterface."
4. "Het systeem biedt de mogelijkheid om workflows op vaste tijdstippen te laten draaien."
5. "Beheerders moeten workflows kunnen testen met voorbeelddata voordat deze in productie worden geactiveerd."

## Affected Projects

- [x] Project: `openregister` -- UI components, scheduled job service, approval state machine, execution history entity/API

## Scope

### In Scope

- **Workflow configuration UI**: Vue tab in schema settings to list, add, edit, and delete hooks; select engine and workflow from registered engines; configure mode, order, timeout, and failure modes
- **Scheduled workflow triggers**: `ScheduledWorkflowJob` (TimedJob) that triggers workflows on a cron-like interval, with a `ScheduledWorkflow` entity linking a workflow to a register/schema and interval
- **Multi-step approval state machine**: `ApprovalChain` entity defining approval steps (role, order), `ApprovalStep` tracking per-object progress, and hooks that advance/reject objects through the chain
- **Workflow execution history**: `WorkflowExecution` entity persisting hook execution results (hookId, objectUuid, engine, status, durationMs, errors, timestamp) with a REST API and Vue monitoring panel
- **Test hook / dry-run**: API endpoint and UI button to execute a hook with sample data derived from the schema without persisting changes

### Out of Scope

- Workflow editing (use engine's native UI -- n8n editor, Windmill editor)
- Complex filterCondition expression language (kept as simple key-value equality for now)
- Notification templates/channels (use n8n's built-in notification nodes)
- Workflow template marketplace or library

## Approach

1. Create `WorkflowExecution` entity and mapper to persist hook execution history from HookExecutor
2. Add `WorkflowExecutionController` with list/show endpoints and filtering by objectId, schemaId, hookId, status
3. Create `ScheduledWorkflow` entity/mapper and `ScheduledWorkflowJob` TimedJob that triggers workflows via the engine adapter on a configurable interval
4. Create `ApprovalChain` and `ApprovalStep` entities for tracking multi-step approval progress per object
5. Add `ApprovalController` with endpoints for chain CRUD, step approval/rejection, and status queries
6. Add `WorkflowEngineController::testHook()` endpoint that executes a workflow with sample data and returns the result without database persistence
7. Build Vue components: `SchemaWorkflowTab`, `HookForm`, `WorkflowExecutionPanel`, `ApprovalChainPanel`, `TestHookDialog`

## Cross-Project Dependencies

- **workflow-engine-abstraction**: Foundation layer with `WorkflowEngineInterface`, adapters, and registry (already implemented)
- **schema-hooks**: Hook configuration format on schemas (already implemented)
- **event-driven-architecture**: Typed PHP events and StoppableEventInterface (already implemented)

## Rollback Strategy

- UI components can be removed by reverting Vue source and rebuilding
- New entities (`WorkflowExecution`, `ScheduledWorkflow`, `ApprovalChain`, `ApprovalStep`) are purely additive -- drop their migrations to roll back
- `ScheduledWorkflowJob` entries in `oc_jobs` can be removed via `IJobList::remove()`
- Existing HookExecutor and workflow pipeline remain unchanged

## Open Questions

None -- scope is confirmed based on the "Not yet implemented" items in the workflow-integration spec.
