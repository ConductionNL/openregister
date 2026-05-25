# Object Lifecycle

## ADDED Requirements

### Requirement: REQ-006 — Schema lifecycle annotations MUST be shape-validated at schema-save time

`LifecycleAnnotationValidator::validate()` MUST check the `x-openregister-lifecycle`
annotation on a schema and return a structured list of error entries (each with
a `code` and `message`). An empty list MUST be returned when the annotation is
absent or fully valid. Validation MUST NOT throw on malformed input; errors are
collected and returned, mapped to HTTP 422 by the caller. The validator MUST
enforce:

- the required top-level keys `field`, `initial`, and `transitions` are present;
- the `field` name resolves to a declared property of type `string` with a
  non-empty `enum`;
- the `initial` value and every declared `final` value is a member of the
  enum;
- the `transitions` map is non-empty;
- each transition object declares a non-empty `from` array whose every member
  is in the enum, and a non-empty `to` string that is in the enum;
- when a transition declares `requires`, the value is a non-empty string
  (DI-tag shape only — the validator does NOT attempt to resolve the tag).

#### Scenario: Annotation absent — no errors
- **GIVEN** a schema definition without an `x-openregister-lifecycle` key
- **WHEN** `LifecycleAnnotationValidator::validate()` is invoked
- **THEN** the method MUST return an empty array

#### Scenario: Missing required top-level key
- **GIVEN** an annotation `{"field": "status", "initial": "draft"}` (no `transitions`)
- **WHEN** the annotation is validated
- **THEN** the result MUST contain an entry with code `lifecycle-missing-key`
  and a message naming `transitions` as the missing key

#### Scenario: Initial state not in field enum
- **GIVEN** a schema with `properties.status.enum = ["draft", "open"]` and an
  annotation whose `initial` is `"closed"`
- **WHEN** the annotation is validated
- **THEN** the result MUST contain an entry with code
  `lifecycle-initial-not-in-enum` referencing the offending value

#### Scenario: Transition `to` not in enum
- **GIVEN** a schema with `properties.status.enum = ["draft", "open"]` and a
  transition `{"open": {"from": ["draft"], "to": "closed"}}`
- **WHEN** the annotation is validated
- **THEN** the result MUST contain an entry with code
  `lifecycle-to-not-in-enum`

#### Scenario: `requires` shape check only
- **GIVEN** a transition declaring `"requires": "decidesk.meeting.openGuard"`
- **WHEN** the annotation is validated
- **THEN** the result MUST NOT contain a tag-resolution error — the validator
  does not attempt DI resolution at schema-save time

### Requirement: REQ-007 — Named transitions MUST be applied through the central engine

`TransitionEngine::transition($objectId, $action)` MUST be the entry point for
state-machine transitions and MUST, in order:

1. Load the object via `ObjectService::find()`; throw `RuntimeException` if not found.
2. Resolve the object's schema; throw `RuntimeException` if unresolvable.
3. Gate on per-object RBAC via `PermissionHandler::hasPermission(action: 'update')`;
   throw `NotAuthorizedException` on denial.
4. Read the schema's `x-openregister-lifecycle` annotation; throw
   `RuntimeException` if the schema does not declare lifecycle.
5. Look up the requested action in `transitions`; throw `RuntimeException` if
   the action is not declared.
6. Reject the transition if the object's current lifecycle field value is not
   in the action's `from` array.
7. Mutate the lifecycle field to the action's `to` value and persist through
   `ObjectService::saveObject()` (so all standard validation/eventing/audit
   machinery runs unchanged).
8. Dispatch a typed `ObjectTransitionedEvent` carrying object, action, from,
   to, userId, register, and schema.

The engine MUST NOT bypass the standard save pipeline; transitions inherit
validation, audit, and event behaviour from REQ-001..005.

#### Scenario: Successful transition
- **GIVEN** an object in state `"draft"` and a transition `open` with
  `from: ["draft"], to: "open"`
- **AND** the caller has `update` permission on the object
- **WHEN** `TransitionEngine::transition($objectId, "open")` is invoked
- **THEN** the saved object MUST have lifecycle field `"open"`
- **AND** an `ObjectTransitionedEvent(from: "draft", to: "open", action: "open")`
  MUST be dispatched

#### Scenario: Transition rejected when current state not in `from`
- **GIVEN** an object in state `"closed"` and a transition `open` declaring
  `from: ["draft"]`
- **WHEN** `TransitionEngine::transition($objectId, "open")` is invoked
- **THEN** a `RuntimeException` MUST be thrown with a message naming the
  current state and the action
- **AND** no save MUST occur and no `ObjectTransitionedEvent` MUST be dispatched

#### Scenario: Transition denied by RBAC
- **GIVEN** a caller without `update` permission on the target object
- **WHEN** `TransitionEngine::transition()` is invoked
- **THEN** a `NotAuthorizedException` MUST be thrown before the annotation is
  read or the object is saved

#### Scenario: Schema does not declare lifecycle
- **GIVEN** an object whose schema has no `x-openregister-lifecycle` annotation
- **WHEN** `TransitionEngine::transition()` is invoked
- **THEN** a `RuntimeException` MUST be thrown naming the schema slug

### Requirement: REQ-008 — Guard DI tags MUST resolve through the registry with NC server fallback

`LifecycleGuardRegistry::resolve($tag)` MUST resolve a transition's `requires`
DI tag to a `LifecycleGuardInterface` instance. Resolution MUST:

- try the OpenRegister app container first (covers OR-internal guards);
- fall back to the injected `IServerContainer` (covers FQCN-referenced guards
  in cooperating apps that Nextcloud can autowire);
- fail closed: when neither container resolves the tag, log the collected
  resolution errors at error level and throw `RuntimeException` whose message
  names the tag;
- type-check the resolved service: if it does not implement
  `LifecycleGuardInterface`, throw `RuntimeException` naming the offending
  service and the required interface;
- cache successful resolutions per request so repeat transitions on the same
  tag within one request reuse the resolved instance.

The registry MUST NOT reach `\OC::$server` directly; the server container is
injected via constructor (`IServerContainer`) to keep `lib/` free of static
server accessors.

#### Scenario: Tag resolves from OR app container
- **GIVEN** a guard service `my.guard` registered in the OR app container
- **WHEN** `LifecycleGuardRegistry::resolve("my.guard")` is invoked
- **THEN** the registered `LifecycleGuardInterface` instance MUST be returned
- **AND** a second invocation with the same tag MUST return the cached instance

#### Scenario: Tag falls back to server container
- **GIVEN** a guard FQCN `Acme\\Guard\\OpenGuard` autowirable by Nextcloud
  but not registered in the OR app container
- **WHEN** `LifecycleGuardRegistry::resolve("Acme\\\\Guard\\\\OpenGuard")` is invoked
- **THEN** the server container MUST be consulted and its instance MUST be
  returned

#### Scenario: Unresolvable tag fails closed
- **GIVEN** a tag that neither container can resolve
- **WHEN** `resolve()` is invoked
- **THEN** a `RuntimeException` MUST be thrown whose message names the tag
- **AND** the logger MUST receive an error-level entry containing the
  resolution errors from each container

#### Scenario: Resolved service does not implement the interface
- **GIVEN** a service registered under a tag that does NOT implement
  `LifecycleGuardInterface`
- **WHEN** `resolve()` is invoked with that tag
- **THEN** a `RuntimeException` MUST be thrown naming the service and the
  required interface

### Requirement: REQ-009 — Guard verdicts MUST use the immutable GuardResult contract

Guards (implementations of `LifecycleGuardInterface::check()`) MUST return a
`GuardResult` value object constructed via the static factories
`GuardResult::allow()` or `GuardResult::deny(string $message)`. The
constructor MUST be private; callers MUST NOT instantiate `GuardResult`
directly. The verdict MUST be inspectable via `isAllowed(): bool`, and a deny
verdict MUST carry a human-readable message that is surfaced to the caller in
the 403 response. Guards MUST be read-only: implementations MUST NOT mutate
the inbound `$object` payload; side effects (notifications, cascades,
derived-field maintenance) belong on `ObjectTransitionedEvent` listeners.

#### Scenario: Allow factory
- **WHEN** `GuardResult::allow()` is called
- **THEN** the returned instance MUST report `isAllowed() === true`

#### Scenario: Deny factory carries message
- **WHEN** `GuardResult::deny("Meeting is not in draft state")` is called
- **THEN** the returned instance MUST report `isAllowed() === false`
- **AND** the deny message MUST be retrievable for surfacing in the response

#### Scenario: Guard contract receives loaded object, action, and userId
- **GIVEN** a guard implementing `LifecycleGuardInterface::check()`
- **WHEN** invoked from a transition flow
- **THEN** the guard MUST receive the loaded object payload, the action name,
  and the caller's uid as parameters
- **AND** the guard MUST return a `GuardResult` without having mutated the
  inbound object array
