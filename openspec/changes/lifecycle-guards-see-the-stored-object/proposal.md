---
kind: code
depends_on: []
---

# Proposal: lifecycle-guards-see-the-stored-object

## Summary

A transition guard (`requires` on a lifecycle transition) is called with the
incoming payload only: `check($newData, $action, $userId)`. A guard that asks
"who filed this request" or "which run is this" reads the answer from the
data the writer sends, so the writer can change the answer. Three reviews hit
the same cause: a no-self-approval check that reads the applicant from the
payload, a reopen guard, and a create that skips the lifecycle check.

This change gives guards the stored object, and checks the state a create
starts in.

## What changes

- A new interface, `LifecycleTransitionGuardInterface`, extends
  `LifecycleGuardInterface` with `checkTransition(LifecycleGuardContext)`.
  The context holds the incoming data, the stored object, the action and the
  caller. A guard that implements it is called with the context. Any other
  guard is called with `check()` exactly as before, so no app breaks.
- A create in a lifecycle schema that sets the lifecycle field must set the
  declared `initial` state. Another value is refused with
  `lifecycle-invalid-initial`. A dynamic initial is resolved and compared. When
  it cannot be resolved, a value the caller sends is refused (fail closed). A
  create that leaves the field empty still gets the initial state filled in.
- A write inside `SystemOperationContext` (seeds, example sets, configuration
  imports, system jobs) may create in any state, as it may write declared
  state on update today.

## What stays the same

- A schema with no `initial` declared has no create check.
- Guards that keep implementing only `check()` see the payload, as today. The
  apps listed in design.md should move to `checkTransition()`.
