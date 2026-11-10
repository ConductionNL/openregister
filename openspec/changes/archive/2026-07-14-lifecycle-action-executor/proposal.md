---
kind: code
---

## Why

OpenRegister parses and stores `x-openregister-lifecycle`, but there is **no
action executor**. `TransitionEngine` only mutates the lifecycle field and
saves; `LifecycleValidationListener` only runs `requires` guards; the
`actions[]` / `actionParameters` blocks that register.d files declare **inside a
transition** are never executed. A fleet-wide survey of `lib/Settings/register.d`
across shillinq, procest, decidesk and opencatalogi finds **60+ declared actions**
(`set-fields` ×10, `set-field` ×6, `materialise-gl-transaction` ×13,
`audit-trail-append`, `emit-event`, `publish-cloudevent`, `commit-stock-reservation`,
…) — every one of them dead by construction. This is the same defect class as the
phantom `x-openregister-approval-chains` key fixed in #396: a declarative
capability that looks done and silently no-ops (issue #427, orphaned-capability
defect class #393).

Compounding it, **list-form transitions bypass `TransitionEngine`**. A plain
`saveObject()` that edits the lifecycle field never dispatches
`ObjectTransitionedEvent` (only `TransitionEngine` does), so even the event-driven
slice of lifecycle behaviour is dead for objects transitioned by ordinary edits
(observed on shillinq `LeaseContract`, whose `activate` actions never ran).

## What Changes

- **New handler contract** `Lifecycle\LifecycleActionInterface` — mirrors
  `Lifecycle\LifecycleGuardInterface`. Guards authorise a transition (read-only);
  actions run its side effects. A self-mutating action returns the modified
  payload; a pure side-effect action returns the payload unchanged.
- **New built-in handler** `Lifecycle\Action\SetFieldsAction` — backs the two
  most-declared action names across the fleet (`set-fields`, `set-field`; 16
  declarations), stamping field values onto the transitioning object and
  resolving the `@now` token. Self-mutating.
- **New registry** `Service\Lifecycle\LifecycleActionRegistry` — mirrors
  `Service\Lifecycle\LifecycleGuardRegistry` exactly (OR container first, server
  container fallback, per-request cache). Built-in action names resolve to their
  handler FQCN; app-declared action names resolve by DI id. **A declared action
  naming an unregistered handler FAILS LOUDLY** (`RuntimeException`) — the
  anti-phantom guard from #396, never a silent no-op.
- **New executor** `Service\Lifecycle\LifecycleActionExecutor` — iterates a
  matched transition's `actions[]`, evaluates each action's optional `condition`
  (`@self`/`@previous` equality, the forms register.d actually declares),
  resolves each via the registry, runs it, and threads the payload forward.
  Fail-loud on missing handler, malformed action, and unparseable condition.
- **New listener** `Listener\LifecycleActionListener` on `ObjectUpdatingEvent`,
  registered directly after `ApprovalChainGateListener`. It parses
  `x-openregister-lifecycle` off `Schema::getConfiguration()`, matches the
  transition from the old/new lifecycle value (same shape as
  `LifecycleValidationListener::findTransitionByTarget`), runs the executor, and
  applies self-mutations via `$object->setObject()` (same pre-persistence
  mutation `CalculationOnSaveListener` uses). Because it hooks the **save path**,
  declared actions run for **every transition form** — a named `TransitionEngine`
  action AND a plain list-form edit — which is the #427 bypass fix. It skips when
  a prior listener stopped propagation (rejected/blocked transition).

Scope: ships the executor + list-form fix + the built-in `SetFieldsAction`
covering the two most-declared action names. The remaining fleet-declared action
types (`materialise-gl-transaction`, `audit-trail-append`, `emit-event`/
`emit-cloud-event`/`publish-cloudevent`, `snapshot-inventory`,
`commit-stock-reservation`, …) become **app-registered handlers** the registry
now resolves — named as follow-up built-ins where a generic implementation is
warranted. No `Schema.php` vocabulary change: `x-openregister-lifecycle` is
already whitelisted and the nested `actions` block already survives the save
round-trip.

## Impact

- Affected specs: `object-lifecycle` (ADDED REQ-011).
- Affected code: `lib/Lifecycle/`, `lib/Service/Lifecycle/`, `lib/Listener/`,
  `lib/AppInfo/Application.php` (one listener registration).
- Backward compatible: schemas without `actions[]` on any transition are
  unaffected; the listener no-ops when no matched transition declares actions.
  A schema that *did* declare an action with no handler now fails loudly instead
  of silently dropping it — the intended behaviour change.
