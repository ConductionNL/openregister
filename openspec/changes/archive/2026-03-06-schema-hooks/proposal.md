# Proposal: schema-hooks

## Summary
Schema-level workflow hooks using CloudEvents 1.0 with synchronous (request-response) and asynchronous (fire-and-forget) delivery modes, and configurable failure behavior per hook.

## Motivation
OpenRegister needs the ability to run external validation and enrichment logic before objects are saved, and trigger notifications after saves. Currently, the event system fires events but has no mechanism to block or modify saves based on external workflow results. Workflow engines like n8n and Windmill can provide validation (e.g., KvK number checks), data enrichment (e.g., address normalization), and post-save automation (e.g., welcome emails), but there is no schema-level configuration to wire these up.

Schema hooks solve this by letting administrators define per-schema, per-event hooks that call external workflows synchronously (blocking the save until approval) or asynchronously (fire-and-forget after save). Each hook has configurable failure modes (reject, allow, flag, queue) so administrators can control what happens when a workflow fails, times out, or is unreachable.

## Affected Projects
- [x] Project: `openregister` — Event classes (StoppableEventInterface), Schema entity (hooks JSON field), new HookExecutor service, ObjectEntityMapper save flow changes

## Scope
### In Scope
- Hook configuration as a JSON field on the Schema entity
- Sync mode: request-response delivery with timeout handling
- Async mode: fire-and-forget delivery (reuses existing webhook system)
- Failure modes: reject, allow, flag, queue — each with distinct behavior
- Stoppable events: ObjectCreatingEvent, ObjectUpdatingEvent, ObjectDeletingEvent implement PSR-14 StoppableEventInterface
- Hook execution order when multiple hooks exist for the same event
- Hook execution logging for debugging and audit
- CloudEvents 1.0 structured content mode for all hook payloads
- Sync hook response format: approved, rejected, modified

### Out of Scope
- Workflow building UI (use engine's native UI)
- Workflow engine adapter interface (see Workflow Engine Abstraction spec)
- Workflow deployment and import (see Workflow-in-Import spec)
- Retry logic for async hooks (handled by existing webhook system)

## Approach
1. Extend `ObjectCreatingEvent`, `ObjectUpdatingEvent`, and `ObjectDeletingEvent` with PSR-14's `StoppableEventInterface` so event propagation can be stopped
2. Add a `hooks` JSON field to the Schema entity to store hook configurations
3. Create a `HookExecutor` service that processes hooks in order, builds CloudEvents payloads, makes sync HTTP calls, and applies failure modes
4. Update the `ObjectEntityMapper` save flow to check `isPropagationStopped()` after dispatching `*ing` events, skipping the database write if stopped
5. Add metadata fields `_validationStatus` and `_validationErrors` for the "flag" and "queue" failure modes
6. Create a background job for the "queue" failure mode to re-run hooks when the engine recovers

## Cross-Project Dependencies
- Depends on the **Workflow Engine Abstraction** change (for engine adapter interfaces that resolve engine names to webhook URLs)

## Rollback Strategy
1. Remove the `hooks` JSON field from the Schema entity (database migration down)
2. Revert event classes to remove StoppableEventInterface
3. Remove the HookExecutor service
4. Revert ObjectEntityMapper to skip the `isPropagationStopped()` check
5. No data loss — hook configurations are stored as JSON on schemas and can be ignored
