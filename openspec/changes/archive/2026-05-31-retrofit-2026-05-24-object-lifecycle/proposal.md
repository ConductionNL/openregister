# Retrofit — object-lifecycle (Bucket 2a extras)

Describes observed behavior of 9 methods (Bucket 2a) across 5 keeper files that
implement the schema-declared state-machine layer added by the
`2026-04-29-lifecycle-annotation` change. The original retrofit
(`retrofit-2026-04-28-object-lifecycle`) anchored REQ-001..005 on the layered
save pipeline. Those REQs do not cover the lifecycle annotation / guard /
transition surface — this change extends `object-lifecycle` with four new REQs
(REQ-006..009) for that surface. Code already exists — this change
retroactively specifies it.

## Affected code units (keepers, 9 methods / 5 files)
- lib/Lifecycle/GuardResult.php (`allow`, `deny`, `isAllowed`) — verdict VO
- lib/Lifecycle/LifecycleGuardInterface.php (`check`) — guard contract
- lib/Service/Lifecycle/LifecycleAnnotationValidator.php (`validate`) — schema-save annotation shape check
- lib/Service/Lifecycle/LifecycleGuardRegistry.php (`__construct`, `resolve`) — DI tag → guard resolver with NC server fallback
- lib/Service/Lifecycle/TransitionEngine.php (`__construct`, `transition`) — apply named transition, fire `ObjectTransitionedEvent`

## Triaged DROPs (16 methods, recorded for audit only)
The Bucket 2a batch carried 16 methods triaged as drops. Reasons in the batch
JSON (`/tmp/or-scan/rspec-cluster-object-lifecycle.json`):
- `GuardResult::__construct`, `GuardResult::getMessage` — internal VO plumbing already implied by allow/deny/isAllowed contract
- `LifecycleGuardRegistry` constructor was already a keeper; nothing dropped there
- `TransitionEngine::availableActions`, `TransitionEngine::loadSchema`, `TransitionEngine::getLifecycleAnnotation` — `availableActions` is read-only enumeration over the same annotation surface and is adequately implied by REQ-007's transition lookup; the two private helpers are pure resolvers
- `LifecycleInitialStateListener::*` (4 methods) — initial-state forcer on create; arguably belongs to schema-hooks or a separate event-driven REQ. Out of scope for this retrofit pass; will be picked up by a future retrofit if it shows up under a different bucket
- `LifecycleValidationListener::*` (5 methods) — pre-save state-machine enforcement at the `ObjectUpdatingEvent` hook. The listener is the on-save twin of `TransitionEngine` (which provides the API-level path). REQ-007 covers the API-level guarantee; the listener's pre-save enforcement is observable through REQ-007's "transition is rejected from disallowed `from` state" scenario, so a separate REQ would be duplicative
- `TenantLifecycleService::isValidStatus` — tenant-lifecycle capability, not object-lifecycle

## Approach
The 9 keeper methods break into four behaviours not covered by REQ-001..005:

1. **Schema-save annotation validation** (REQ-006) — `LifecycleAnnotationValidator::validate()` shape-checks `x-openregister-lifecycle` and returns a structured error list (empty = valid). Required top-level keys, field/initial/final/from/to enum-membership, transitions must be a non-empty map, `requires` (when present) must be a non-empty string.
2. **Named transition application** (REQ-007) — `TransitionEngine::transition()` loads an object, gates with `PermissionHandler` (`update`), resolves the schema's lifecycle annotation, validates the requested action is declared and the current state is in `from`, mutates the lifecycle field, saves through `ObjectService::saveObject()`, and dispatches a typed `ObjectTransitionedEvent`.
3. **Guard DI tag resolution** (REQ-008) — `LifecycleGuardRegistry::resolve()` resolves a `requires` tag against the OR app container first then falls back to NC's `IServerContainer` (covers FQCN-referenced guards in other apps that NC can autowire). Fail-closed: unresolved tag throws `RuntimeException`; per-request cache prevents repeat resolution within one request.
4. **Guard verdict value object** (REQ-009) — `GuardResult::allow()` / `GuardResult::deny(string $message)` factories and `isAllowed()` inspector form the contract returned by `LifecycleGuardInterface::check()`. Guards are read-only — must not mutate the object — side effects belong on `ObjectTransitionedEvent` listeners.

## Mode
`--extend` — adds REQ-006..009 to the existing `object-lifecycle` spec. Existing
REQ-001..005 (layered save pipeline, validation, cache, bulk, hydration) are
unchanged.

## Notes
- The keeper list contains both `__construct` methods of services; constructors
  carry the DI contract (which collaborators are injected) that the REQs depend
  on, so they are annotated under the same REQ as their service's primary
  method.
- `LifecycleValidationListener` (the on-save twin of `TransitionEngine`) was
  triaged out — it is the event-driven enforcement path. A future retrofit can
  promote it to its own REQ when the `event-driven-architecture` capability is
  extended; for now its behavior is covered indirectly by REQ-007.
- The 16 DROPs are listed above so the next coverage scan does not re-surface
  them as gaps.

Source: `/tmp/or-scan/rspec-cluster-object-lifecycle.json` (25 methods, 8 files).
