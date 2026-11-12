---
kind: code
depends_on: []
---
# Proposal: sequence-fields-are-set-by-the-sequence-service-only

## Summary

A task's sequence fields are written by the task sequence only. `TaskService::create()` is the create path behind the task routes, and today it passes `sequenceUuid`, `sequencePosition`, `legacyStepId` and a `templateSnapshot` carrying `approvingOutcomes` straight into the new task. A caller who may read an object can then add a task to a running sequence on that object, at a position of their choosing and in state `enabled`. The sequence reads its positions back through `TaskMapper::findBySequence()`, so such a task can be enabled or completed as if the sequence had made it. The approving set on the snapshot also decides what `consume` accepts.

## What changes

- `create()` refuses `sequenceUuid`, `sequencePosition` and `legacyStepId` when they carry a value, and a `templateSnapshot` that carries `approvingOutcomes`. The refusal names the field and comes before any write. It applies to every caller, administrators included.
- A field sent as `null` sets nothing and is not refused.
- `import()`, the in-process path that `TaskSequenceService::provision()` and the approval migration use, is unchanged.
- A `templateSnapshot` without `approvingOutcomes` is still accepted, so a templated task keeps its frozen template.

## What stays

- `findBySequence()` and `advance()` trust `sequence_uuid`. After this change only the sequence service and the migration write it. Rows created through the old path before this change are not cleaned up.

## Impact

- Amends `flow-approval-consolidation`.
- Affected code: `lib/Service/Task/TaskService.php`.
- No route or app sends these fields when creating a task today.
- Size: S.
