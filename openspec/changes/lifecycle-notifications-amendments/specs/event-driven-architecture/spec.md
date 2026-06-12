---
status: draft
---
# Event-driven architecture (delta — lifecycle + transition surface)

## Purpose

Graduate the lifecycle-annotation refinements from the archived
`2026-04-29-lifecycle-annotation` change directory into the canonical
`event-driven-architecture` spec, so the active capability catalog
reflects the surface that has already shipped on this branch
(`lib/Event/ObjectTransitionedEvent.php`,
`lib/Controller/TransitionController.php`,
`lib/Service/Lifecycle/TransitionEngine.php`,
`lib/Lifecycle/LifecycleGuardInterface.php`).

## ADDED Requirements

### Requirement: Schemas MAY declare a state machine via `x-openregister-lifecycle`

A schema MAY include a top-level `x-openregister-lifecycle` block
with `field`, `initial`, and a `transitions` map (action →
`{from[], to, requires?, description?}`). When present, the
schema-save validator MUST verify the annotation against the
schema's `properties[field]` enum and reject malformed annotations
with HTTP 422.

**Uniqueness constraint.** Within a single schema's `transitions`
map, no two transitions MAY share the same `(from, to)` pair (i.e.
for any pair `(F, T)`, at most one action has both
`F ∈ transitions[action].from` and `transitions[action].to == T`).
Schema-save validation MUST reject duplicates with HTTP 422 and
`{ code: "lifecycle-duplicate-from-to", from: F, to: T, actions: [a, b] }`.
This makes the action name uniquely resolvable when
`ObjectTransitionedEvent` fires from a direct PATCH (where the
client did not supply an action name) — the implementation MUST
resolve `event.action` deterministically by looking up the unique
transition matching `(from, to)`.

#### Scenario: Duplicate (from, to) pair is rejected
- GIVEN a schema declaring `open: {from: ["draft"], to: "opened"}` and `expedite: {from: ["draft"], to: "opened"}`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include `{ code: "lifecycle-duplicate-from-to", from: "draft", to: "opened", actions: ["open", "expedite"] }`

### Requirement: The pre-save validator MUST reject invalid transitions via `ObjectUpdatingEvent`

The implementation MUST register an `IEventListener` against
`ObjectUpdatingEvent`. When the event's schema has an
`x-openregister-lifecycle` annotation AND the proposed new value
of `field` is not equal to the existing value, the listener MUST
verify the new value is `transitions[action].to` for some `action`
whose `from` list includes the existing value. If not, the
listener MUST call `Event::stopPropagation()` and attach a
structured rejection reason via the existing
`ObjectUpdatingEvent::setErrors(array $errors)` API.

**Rejection-metadata contract.** The implementation reuses the
existing rejection-metadata API on `ObjectUpdatingEvent`:
`setErrors(array $errors): void` and `getErrors(): array` (already
shipped). After `stopPropagation()` is called,
`ObjectService::saveObject` MUST detect the stopped event, read
`getErrors()`, and translate any non-empty value into HTTP 422
with the array as the response body. The lifecycle listener MUST
NOT introduce a new setter/getter pair — renaming the
publicly-exposed event API would be a breaking change for
third-party listeners that already call `setErrors()`.

#### Scenario: An invalid transition is rejected via setErrors
- GIVEN a meeting object with `lifecycle = "draft"` and no transition declares `from: ["draft"], to: "closed"`
- WHEN a client PATCHes `lifecycle = "closed"`
- THEN the lifecycle listener MUST call `event->stopPropagation()`
- AND the listener MUST call `event->setErrors([...])` with `{ code: "invalid-transition", from: "draft", attempted: "closed" }`
- AND `ObjectService::saveObject` MUST translate the stopped event's `getErrors()` into HTTP 422 with that body
- AND the object's stored value MUST remain `draft`

### Requirement: Initial state MUST be enforced on object creation

The implementation MUST register an `IEventListener` against
`ObjectCreatingEvent` that, when the schema has an
`x-openregister-lifecycle` annotation, force-sets
`object[field] = initial` regardless of the supplied value. The
override MUST be logged at debug level when the supplied value
differs from `initial`.

### Requirement: The system MUST expose a sugar transition endpoint

`POST /apps/openregister/api/objects/{id}/transition?register=<app>&schema=<type>`
with body `{action: "<name>"}` MUST be a sugar wrapper that loads
the object, looks up `transitions[action]`, patches
`field = transitions[action].to`, and saves through
`ObjectService::saveObject` (so the existing event chain fires,
audit trail records, RBAC applies).

**Auth contract.** The endpoint MUST be annotated
`#[NoAdminRequired]` — accessible to any authenticated Nextcloud
user, NOT admin-only. Authorization MUST be enforced by (a) the
per-object RBAC write check and (b) any registered
`LifecycleGuardInterface`, NOT by admin status. The endpoint MUST
NOT be annotated `#[NoCSRFRequired]` — Nextcloud's standard CSRF
middleware MUST apply. State-mutating POSTs without a valid CSRF
token MUST be rejected by the framework before the controller
runs.

**Response codes:** 401 for unauthenticated requests (no NC
session) — emitted by NC's auth middleware before the controller
runs; 403 for authenticated users lacking write permission on the
object OR for guard denial; 404 for missing object; 422 for
unknown action OR from-state mismatch (caught by the listener
above) OR malformed body.

#### Scenario: Missing CSRF token is rejected by the framework
- GIVEN an authenticated user without a valid CSRF token (no `requesttoken` header / cookie)
- WHEN they POST to the transition endpoint
- THEN Nextcloud's CSRF middleware MUST reject before the controller runs
- AND the controller method MUST NOT carry `@NoCSRFRequired` / `#[NoCSRFRequired]`

#### Scenario: Unauthenticated request returns 401
- GIVEN no Nextcloud session is established
- WHEN a client POSTs to the transition endpoint
- THEN Nextcloud's auth middleware MUST reject with HTTP 401 before the controller runs

### Requirement: Apps MAY register `LifecycleGuardInterface` implementations for transition-specific authorization

A transition's `requires` field MUST resolve to a Nextcloud DI
service tag implementing
`OCA\OpenRegister\Lifecycle\LifecycleGuardInterface`. The
transition endpoint MUST call the guard before applying the
transition; a `GuardResult::deny(message)` MUST short-circuit
with HTTP 403 and the deny message.

### Requirement: The system MUST dispatch `ObjectTransitionedEvent` after a successful transition

After a transition is applied via the endpoint OR via a direct
write that flips the lifecycle field, the implementation MUST
dispatch `ObjectTransitionedEvent` via
`IEventDispatcher::dispatchTyped()` with payload
`{object, action, from, to, userId, register, schema}`. The event
joins the existing event-driven-architecture catalog and is
automatically routable through the existing
webhook-payload-mapping infrastructure.

**Action resolution for direct PATCH.** When the event is
dispatched from a direct PATCH (not the sugar endpoint, where the
client did not supply an action name), `event.action` MUST be
resolved by looking up the unique transition matching the observed
`(from, to)` pair in the schema's `transitions` map. Because the
uniqueness constraint above forbids two transitions sharing the
same `(from, to)`, this lookup is deterministic. If no transition
matches `(from, to)` (the field was changed in violation of the
lifecycle), the listener MUST have already rejected the save —
`ObjectTransitionedEvent` MUST NOT fire for invalid transitions.

#### Scenario: A direct lifecycle PATCH dispatches the event with the resolved action
- GIVEN a schema with `open: {from: ["draft"], to: "opened"}` (the unique transition matching that pair)
- AND a client PATCHes `lifecycle = "opened"` on a draft object
- WHEN the save completes
- THEN `ObjectTransitionedEvent` MUST fire exactly once
- AND `event.action` MUST equal `"open"` (resolved deterministically by the (from, to) lookup)

### Requirement: The system MUST expose `available-actions` for UI rendering

`GET /apps/openregister/api/objects/{id}/available-actions?register=<app>&schema=<type>`
MUST return the current state, the list of applicable transitions
(those whose `from` includes the current state), and per-transition
`allowed: bool` (with optional `denyMessage` when a registered
guard pre-emptively denies).
