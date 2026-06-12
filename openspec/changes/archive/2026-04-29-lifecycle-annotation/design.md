# Design: Lifecycle Annotation

## Approach
Sit on top of the implemented `event-driven-architecture`. The lifecycle validator is just one more `IEventListener` registered against `ObjectUpdatingEvent` — uses the existing `StoppableEventInterface` to reject invalid transitions. The transition endpoint is sugar that loads, patches the lifecycle field, and saves through `ObjectService::saveObject` (the existing path that already fires the events). `ObjectTransitionedEvent` joins the existing 39-event family.

## Files Affected
- `lib/Service/SchemaService.php` — schema-save validation gains `x-openregister-lifecycle` rules.
- `lib/Listener/LifecycleValidationListener.php` — new listener on `ObjectUpdatingEvent`; rejects on invalid from→to.
- `lib/Listener/LifecycleInitialStateListener.php` — new listener on `ObjectCreatingEvent`; force-sets the initial state.
- `lib/Controller/TransitionController.php` — new sugar endpoint.
- `lib/Event/ObjectTransitionedEvent.php` — new typed event.
- `lib/Lifecycle/LifecycleGuardInterface.php` — public DI tag for app-side guards.
- `lib/Lifecycle/GuardResult.php` — value object.
- `appinfo/routes.php` — `POST /api/objects/{id}/transition` + `GET /api/objects/{id}/available-actions`.

## Out of scope
- Cascade rules between transitions (handled by app-side listeners on `ObjectTransitionedEvent`).
- Complex workflows with parallel branches (use `notificatie-engine` for fan-out, app code for orchestration).
