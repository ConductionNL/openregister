---
kind: code
---

# Proposal: readonly-is-stamped-by-system-operations

## Why

`readOnly: true` (JSON Schema) means a client may not write the property. `ObjectService::enforceReadOnlyOnUpdate`
refused every changed readOnly value on update, including the writes of a trusted system operation, which is what
stamps such a property in the first place. dossiq's `PaymentStateProjectionJob` patches a case's `paymentState` and
`paymentStateCheckedAt` (both readOnly) from a background job; every patch was refused, the job logged a warning, and
every case stayed null.

## What changes

- Inside a `SystemOperationContext` (repair steps, configuration imports, background work run through
  `ObjectService::runAsSystem()`), `readOnly` is not enforced on update.
- Every other update still refuses a changed readOnly value.
- `immutable: true` is enforced for everyone, a system operation included.

No HTTP route of OpenRegister enters a `SystemOperationContext` with a client payload: the only request paths that
reach one are the administrator-only imports of an app's shipped configuration (`RegisterDescriptorController::import`,
`SetupController::runAction`).
