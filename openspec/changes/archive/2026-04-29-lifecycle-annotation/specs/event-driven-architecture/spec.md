---
status: draft
---
# Lifecycle Annotation (delta — event-driven-architecture)

## Purpose
Extend the implemented event-driven-architecture spec with a declarative `x-openregister-lifecycle` schema annotation, a pre-save validator that uses the existing `StoppableEventInterface` hook on `ObjectUpdatingEvent` to reject invalid transitions, and a sugar `transition` endpoint plus a typed `ObjectTransitionedEvent` that joins the existing event family.

## ADDED Requirements

### Requirement: Schemas MAY declare a state machine via `x-openregister-lifecycle`
A schema MAY include a top-level `x-openregister-lifecycle` block with `field`, `initial`, and a `transitions` map (action → `{from[], to, requires?, description?}`). When present, OpenRegister's existing schema-save validation MUST verify the annotation against the schema's `properties[field]` enum and reject malformed annotations with HTTP 422.

**Uniqueness constraint.** Within a single schema's `transitions` map, no two transitions MAY share the same `(from, to)` pair (i.e. for any pair `(F, T)`, at most one action has both `F ∈ transitions[action].from` and `transitions[action].to == T`). Schema-save validation MUST reject duplicates with HTTP 422 and `{ code: "lifecycle-duplicate-from-to", from: F, to: T, actions: [a, b] }`. This makes the action name uniquely resolvable when `ObjectTransitionedEvent` fires from a direct PATCH (where the client did not supply an action name) — the implementation MUST resolve `event.action` deterministically by looking up the unique transition matching `(from, to)`.

#### Scenario: A valid annotation passes validation
- GIVEN a schema with property `lifecycle` of type string with enum `["draft", "scheduled", "opened", "closed"]`
- AND the schema declares `x-openregister-lifecycle` with `field: "lifecycle"`, `initial: "draft"`, and `transitions: {open: {from: ["scheduled"], to: "opened"}}`
- WHEN the schema is saved
- THEN `SchemaService::saveSchema()` MUST accept it
- AND every property reference in the annotation MUST resolve

#### Scenario: A `to` outside the enum is rejected
- GIVEN a schema with `lifecycle` enum `["draft", "scheduled"]`
- AND `x-openregister-lifecycle.transitions.open.to = "opened"` (not in enum)
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include `{ code: "lifecycle-to-not-in-enum", field: "lifecycle", value: "opened" }`

#### Scenario: A missing `field` property reference is rejected
- GIVEN a schema without a `lifecycle` property
- AND `x-openregister-lifecycle.field = "lifecycle"`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422 and `{ code: "lifecycle-field-missing" }`

#### Scenario: Duplicate (from, to) pair is rejected
- GIVEN a schema declaring `open: {from: ["draft"], to: "opened"}` and `expedite: {from: ["draft"], to: "opened"}`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include `{ code: "lifecycle-duplicate-from-to", from: "draft", to: "opened", actions: ["open", "expedite"] }`

### Requirement: The pre-save validator MUST reject invalid transitions via `ObjectUpdatingEvent`
The implementation MUST register an `IEventListener` against `ObjectUpdatingEvent`. When the event's schema has an `x-openregister-lifecycle` annotation AND the proposed new value of `field` is not equal to the existing value, the listener MUST verify the new value is `transitions[action].to` for some `action` whose `from` list includes the existing value. If not, the listener MUST call `Event::stopPropagation()` and attach a structured rejection reason via `ObjectUpdatingEvent::setErrors(array $errors)`.

**Rejection-metadata contract.** The implementation reuses the existing rejection-metadata API on `ObjectUpdatingEvent` (already shipped in the `event-driven-architecture` spec): `setErrors(array $errors): void` and `getErrors(): array`. After `stopPropagation()` is called, `ObjectService::saveObject` MUST detect the stopped event, read `getErrors()`, and translate any non-empty value into HTTP 422 with the array as the response body. The lifecycle listener MUST NOT introduce a new setter/getter pair — renaming the publicly-exposed event API would be a breaking change for third-party listeners that already call `setErrors()`.

#### Scenario: A valid transition is allowed
- GIVEN a meeting object with `lifecycle = "scheduled"` and the meeting schema's transitions allow `scheduled → opened` via action `open`
- WHEN a client PATCHes `lifecycle = "opened"`
- THEN `ObjectUpdatingEvent` fires
- AND the lifecycle listener MUST NOT stop propagation
- AND the save MUST succeed with the new value

#### Scenario: An invalid transition is rejected
- GIVEN a meeting object with `lifecycle = "draft"` and no transition declares `from: ["draft"], to: "closed"`
- WHEN a client PATCHes `lifecycle = "closed"`
- THEN the lifecycle listener MUST call `event->stopPropagation()`
- AND `ObjectService::saveObject` MUST return HTTP 422 with `{ code: "invalid-transition", from: "draft", attempted: "closed" }`
- AND the object's stored value MUST remain `draft`

### Requirement: Initial state MUST be enforced on object creation
The implementation MUST register an `IEventListener` against `ObjectCreatingEvent` that, when the schema has an `x-openregister-lifecycle` annotation, force-sets `object[field] = initial` regardless of the supplied value. The override MUST be logged at debug level when the supplied value differs from `initial`.

#### Scenario: Initial state is enforced
- GIVEN a meeting schema with `x-openregister-lifecycle.initial = "draft"`
- WHEN a client posts a new meeting with `lifecycle: "opened"` in the body
- THEN the listener MUST overwrite the value to `"draft"` before the object is persisted
- AND the persisted object's `lifecycle` MUST equal `"draft"`
- AND a debug log entry MUST record the override `(supplied: "opened", forced: "draft")`

### Requirement: The system MUST expose a sugar transition endpoint
`POST /apps/openregister/api/objects/{id}/transition?register=<app>&schema=<type>` with body `{action: "<name>"}` MUST be a sugar wrapper that loads the object, looks up `transitions[action]`, patches `field = transitions[action].to`, and saves through `ObjectService::saveObject` (so the existing event chain fires, audit trail records, RBAC applies).

**Auth contract.** The endpoint MUST be annotated `#[NoAdminRequired]` — accessible to any authenticated Nextcloud user, NOT admin-only. Authorization MUST be enforced by (a) the per-object RBAC write check and (b) any registered `LifecycleGuardInterface`, NOT by admin status. The endpoint MUST NOT be annotated `#[NoCSRFRequired]` — Nextcloud's standard CSRF middleware MUST apply. State-mutating POSTs without a valid CSRF token MUST be rejected by the framework before the controller runs.

**Response codes:** 401 for unauthenticated requests (no NC session) — emitted by NC's auth middleware before the controller runs; 403 for authenticated users lacking write permission on the object OR for guard denial; 404 for missing object; 422 for unknown action OR from-state mismatch (caught by the listener above) OR malformed body.

#### Scenario: Unauthenticated request returns 401
- GIVEN no Nextcloud session is established
- WHEN a client POSTs to the transition endpoint
- THEN Nextcloud's auth middleware MUST reject with HTTP 401 before the controller runs

#### Scenario: Authenticated user without object-write permission returns 403
- GIVEN user `bob` has read-only access to meeting `m1`
- AND a transition `schedule: {from: ["draft"], to: "scheduled"}` exists
- WHEN bob POSTs `{action: "schedule"}` for `m1`
- THEN the per-object RBAC check MUST reject with HTTP 403 and `{ code: "forbidden-object-write" }`
- AND the object MUST NOT be modified
- AND `ObjectTransitionedEvent` MUST NOT fire

#### Scenario: Missing CSRF token is rejected by the framework
- GIVEN an authenticated user without a valid CSRF token (no `requesttoken` header / cookie)
- WHEN they POST to the transition endpoint
- THEN Nextcloud's CSRF middleware MUST reject before the controller runs
- AND the controller MUST NOT be annotated `#[NoCSRFRequired]`

#### Scenario: A successful transition returns the updated object
- GIVEN a meeting `m1` with `lifecycle = "draft"` and a transition `schedule: {from: ["draft"], to: "scheduled"}`
- WHEN a user with write permission POSTs `{action: "schedule"}` to the transition endpoint
- THEN the endpoint MUST return 200 with `{ object: <updated>, transition: {action: "schedule", from: "draft", to: "scheduled", at: <iso>, by: <uid>} }`
- AND the object's stored `lifecycle` MUST be `"scheduled"`
- AND the existing audit trail MUST record one save event for the transition
- AND `ObjectTransitionedEvent` MUST fire after the save

#### Scenario: An unknown action returns 422
- GIVEN a schema whose transitions do not declare action `teleport`
- WHEN a client POSTs `{action: "teleport"}` to the transition endpoint
- THEN the endpoint MUST return 422 with `{ code: "unknown-action", action: "teleport" }`

### Requirement: Apps MAY register `LifecycleGuardInterface` implementations for transition-specific authorization
A transition's `requires` field MUST resolve to a Nextcloud DI service tag implementing `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface`. The transition endpoint MUST call the guard before applying the transition; a `GuardResult::deny(message)` MUST short-circuit with HTTP 403 and the deny message.

#### Scenario: A guard denies the transition
- GIVEN a transition `open` with `requires: "decidesk.meeting.openGuard"`
- AND the guard's `check()` returns `GuardResult::deny("Quorum not met")` for the loaded meeting
- WHEN a user POSTs `{action: "open"}`
- THEN the endpoint MUST return 403 with `{ code: "guard-denied", message: "Quorum not met" }`
- AND the object MUST NOT be modified

#### Scenario: A missing guard tag fails closed at first invocation
- GIVEN a transition `open` with `requires: "decidesk.meeting.openGuard"`
- AND no DI service is registered with that tag
- WHEN a user POSTs `{action: "open"}`
- THEN the endpoint MUST return 500 with `{ code: "guard-not-registered", tag: "decidesk.meeting.openGuard" }`
- AND a warning MUST be logged at install time (visible in `occ openregister:check-guards`)

### Requirement: The system MUST dispatch `ObjectTransitionedEvent` after a successful transition
After a transition is applied via the endpoint OR via a direct write that flips the lifecycle field, the implementation MUST dispatch `ObjectTransitionedEvent` via `IEventDispatcher::dispatchTyped()` with payload `{object, action, from, to, userId, register, schema}`. The event joins the existing event-driven-architecture catalog and is automatically routable through the existing webhook-payload-mapping infrastructure.

**Action resolution for direct PATCH.** When the event is dispatched from a direct PATCH (not the sugar endpoint, where the client did not supply an action name), `event.action` MUST be resolved by looking up the unique transition matching the observed `(from, to)` pair in the schema's `transitions` map. Because the uniqueness constraint above forbids two transitions sharing the same `(from, to)`, this lookup is deterministic. If no transition matches `(from, to)` (the field was changed in violation of the lifecycle), the listener MUST have already rejected the save — `ObjectTransitionedEvent` MUST NOT fire for invalid transitions.

#### Scenario: Transition via endpoint dispatches the event
- GIVEN a successful transition via `POST /api/objects/{id}/transition`
- WHEN the post-save phase completes
- THEN `IEventDispatcher::dispatchTyped(ObjectTransitionedEvent)` MUST be called exactly once
- AND any registered listener (including the existing `WebhookEventListener`) MUST receive it

#### Scenario: A direct lifecycle PATCH also dispatches the event
- GIVEN a client PATCHes the lifecycle field directly via `PUT /api/objects/{id}` (skipping the sugar endpoint)
- AND the new value is a valid transition target from the previous value
- WHEN the save completes
- THEN `ObjectTransitionedEvent` MUST fire (so apps that subscribe to transitions don't have to also subscribe to the generic `ObjectUpdatedEvent` and infer the action)

### Requirement: The system MUST expose `available-actions` for UI rendering
`GET /apps/openregister/api/objects/{id}/available-actions?register=<app>&schema=<type>` MUST return the current state, the list of applicable transitions (those whose `from` includes the current state), and per-transition `allowed: bool` (with optional `denyMessage` when a registered guard pre-emptively denies).

#### Scenario: Available actions reflect current state and guards
- GIVEN a meeting with `lifecycle = "scheduled"`, transitions `open: {from: ["scheduled"]}` and `cancel: {from: ["draft"]}`
- AND `open`'s guard would deny with message `"Quorum not met"` for this object
- WHEN a user GETs the available-actions endpoint
- THEN the response MUST be `{ currentState: "scheduled", actions: [{name: "open", to: "opened", allowed: false, denyMessage: "Quorum not met"}] }`
- AND `cancel` MUST NOT appear (its `from` doesn't include "scheduled")
