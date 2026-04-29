# Tasks — Lifecycle Annotation

- [ ] 1.1 Add `x-openregister-lifecycle` validation rules to `SchemaService::saveSchema()` — fields exist, every `from`/`to`/`initial` in enum, every `requires` tag resolvable.
- [ ] 1.2 Create `lib/Listener/LifecycleValidationListener.php` — subscribes to `ObjectUpdatingEvent`; if the schema declares `x-openregister-lifecycle` and the new `field` value isn't in `transitions[*].to` reachable from the current value, call `event->stopPropagation()` + set rejection metadata.
- [ ] 1.3 Create `lib/Listener/LifecycleInitialStateListener.php` — subscribes to `ObjectCreatingEvent`; force-sets `field = initial` regardless of supplied value (logs at debug when the supplied value differs).
- [ ] 1.4 Create `lib/Lifecycle/LifecycleGuardInterface.php` + `lib/Lifecycle/GuardResult.php` (public namespace, re-exported via composer.json autoload).
- [ ] 1.5 Create `lib/Service/Lifecycle/LifecycleGuardRegistry.php` — resolves `requires` DI tag → `LifecycleGuardInterface`. Cache per request.
- [ ] 1.6 Create `lib/Event/ObjectTransitionedEvent.php` — readonly `object`, `action`, `from`, `to`, `userId`, `register`, `schema`. Joins the existing event-driven-architecture catalog.
- [ ] 1.7 Create `lib/Controller/TransitionController.php` — `transition(string $id)` and `availableActions(string $id)`. Annotate `#[NoAdminRequired]`. Both call into `TransitionEngine`.
- [ ] 1.8 Create `lib/Service/Lifecycle/TransitionEngine.php` — apply(action) loads object, calls guard, patches field, saves through `ObjectService::saveObject` (so event chain fires), dispatches `ObjectTransitionedEvent`.
- [ ] 1.9 Register both listeners + the controller in `lib/AppInfo/Application.php`.
- [ ] 1.10 Add routes `POST /api/objects/{id}/transition` and `GET /api/objects/{id}/available-actions` to `appinfo/routes.php`.
- [ ] 1.11 Unit tests for validator (every rule), listener (every transition shape, including stop-propagation), engine (guard allow/deny, missing schema, locked object).
- [ ] 1.12 Integration test: end-to-end transition through the endpoint produces an audit-trail entry, fires `ObjectTransitionedEvent`, returns 200.
- [ ] 1.13 Doc: `docs/annotations/x-openregister-lifecycle.md` with a worked example mirroring decidesk's Meeting.
- [ ] 1.14 Update the platform-capabilities catalog to register `x-openregister-lifecycle` + `ObjectTransitionedEvent` + the two endpoints + the guard interface.
