---
kind: code
depends_on: []
---

# Notification `updated` Trigger — Field-Change Condition

## Why

The fleet notification analysis (hydra/openspec/fleet-notification-plan.md) found that the single most-wanted notification pattern across every app is *"notify when a field changed to X"* — case/zaak status moved, a lead was won/lost, an assignee was re-assigned, a signing request was signed. The `notificatie-engine` dispatcher's `updated` trigger today fires on **every** update with no condition, and the only conditional path (`calculatedChange`) is **numeric-only**. So precise status/assignee-change rules are not expressible, forcing apps into noisy "every update" rules or awkward `transition`/`scheduled` workarounds. This is the highest-leverage gap blocking the per-app rollout.

## What Changes

- Extend the `updated` trigger in `AnnotationNotificationDispatcher::matches()` with an optional **`condition`** block that evaluates a (non-numeric) field change against the old vs new object data the listener already supplies:
  - `{"field": "status", "operator": "changed"}` — fires only when the field's value differs between old and new.
  - `{"field": "status", "operator": "equals", "value": "afgehandeld"}` — fires only when the new value equals `value` (optionally also `"from"` to require the prior value).
  - `{"field": "assignee", "operator": "changed"}` — the assignee-reassignment case the fleet needs.
- The `AnnotationNotificationListener` already dispatches `updated` with the new object; extend it to also pass `_oldData`/`_newData` for the plain `updated` trigger (it already does this for `calculatedChange`), so `matches()` can compare without re-reading history.
- When `_oldData`/`_newData` are unavailable (e.g. no old object), a `condition`-bearing `updated` rule **fails closed** (does not fire) — consistent with the existing `calculatedChange` behaviour.
- Rules with **no** `condition` keep firing on every update (back-compatible).

## Capabilities

### Modified Capabilities
- `notificatie-engine`: the `updated` trigger gains an optional non-numeric field-change `condition` (`changed` / `equals` (+optional `from`)), evaluated against old-vs-new object data. Unblocks status/assignee-change notifications fleet-wide.

## Impact

- **Code (OpenRegister):**
  - `lib/Service/Notification/AnnotationNotificationDispatcher.php` — `matches()` evaluates the new `condition` block for `updated`; a small string-condition evaluator alongside the existing numeric `numericConditionMatches()`.
  - `lib/Listener/AnnotationNotificationListener.php` — pass `_oldData`/`_newData` in the `updated` dispatch context.
  - Tests in `tests/Unit/Service/Notification/AnnotationNotificationDispatcherTest.php`.
- **No** new DB tables / schemas. Back-compatible: existing `updated` rules without `condition` are unaffected.
- **Unblocks** the per-app notification change requests (procest reassignment, zaakafhandelapp status transitions, pipelinq won/lost, docudesk signer-signed, openconnector status→error) that were deferred to this engine gap.
