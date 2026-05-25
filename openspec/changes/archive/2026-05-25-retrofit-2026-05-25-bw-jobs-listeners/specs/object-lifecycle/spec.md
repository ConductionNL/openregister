# Object Lifecycle

## Purpose

The `object-lifecycle` capability already specifies the annotation validator (REQ-006), the named-action `TransitionEngine` (REQ-007), and the guard registry / `GuardResult` contract (REQ-008/009). This delta retroactively captures the **event-listener enforcement layer** those requirements do not describe: the two listeners that apply a schema's declared initial lifecycle state on create and guard direct lifecycle-field edits on update. The code already ships in production; these requirements describe its observed behaviour so the spec reflects the live system.

**Cross-references**: object-lifecycle REQ-006 (annotation validator), REQ-007 (named-action `TransitionEngine`), REQ-008/009 (guard registry / `GuardResult`); [event-driven-architecture](../../../../specs/event-driven-architecture/spec.md).

## ADDED Requirements

### Requirement: Declared initial lifecycle state applied on create

`LifecycleInitialStateListener::handle()` MUST, on `ObjectCreatingEvent`, force-set the schema's declared initial lifecycle value when the caller did not supply one. The listener reads the `x-openregister-lifecycle` annotation from the object's schema, takes the annotation's `field` and `initial` keys, and writes `initial` into the object payload under `field` ONLY when that field is currently absent, null, or an empty string. A caller-supplied non-empty value MUST be left untouched (its validity is the validator's / update-guard's concern). The listener MUST be a no-op when the event is not an `ObjectCreatingEvent`, when the schema cannot be resolved, when the schema declares no lifecycle annotation, or when the annotation's `field`/`initial` are empty.

Apps therefore never need to know the starting state — lifecycle is a declarative property of the schema.

#### Scenario: Initial state applied when caller omits it
- **GIVEN** a schema declaring `x-openregister-lifecycle` with `field: "status"` and `initial: "draft"`
- **AND** an object being created whose `status` field is absent
- **WHEN** `ObjectCreatingEvent` fires and `LifecycleInitialStateListener::handle()` runs
- **THEN** the object payload MUST have `status` set to `"draft"` before persistence

#### Scenario: Caller-supplied value is preserved
- **GIVEN** the same schema and an object being created with `status: "open"`
- **WHEN** the listener runs
- **THEN** the `status` value MUST remain `"open"` (the listener MUST NOT overwrite it)

#### Scenario: Empty string is treated as missing
- **GIVEN** the same schema and an object being created with `status: ""`
- **WHEN** the listener runs
- **THEN** `status` MUST be set to the declared `initial` value `"draft"`

#### Scenario: No-op without a lifecycle annotation
- **GIVEN** a schema with no `x-openregister-lifecycle` annotation
- **WHEN** the listener runs on an object of that schema
- **THEN** the payload MUST be left unchanged

#### Notes
- `loadSchema()` resolves the object's schema via `SchemaMapper::find($ref, _multitenancy: false)` — a system-level lookup because the listener is not user-scoped. An unresolvable or empty schema reference yields a null schema and the listener returns early after logging a warning. See the change Notes for the multitenancy-boundary follow-up.
- This is the create-time complement to REQ-006's annotation validator; it relies on the annotation already being shape-valid.

### Requirement: Direct lifecycle-field edits guarded on update

`LifecycleValidationListener::handle()` MUST, on `ObjectUpdatingEvent`, reject lifecycle-field edits made through the ordinary save path (`ObjectService::saveObject()`) that no declared transition allows. This is the complement to REQ-007's named-action `TransitionEngine`: it guards the case where a caller edits the lifecycle field value directly rather than invoking a named action. When the old and new value of the annotation's `field` differ, the listener MUST:

1. require the new value to be a non-empty string (else reject with code `lifecycle-invalid-value`);
2. find a declared transition whose `to` equals the new value AND whose `from` array contains the old value (else reject with code `lifecycle-invalid-transition`);
3. when the matched transition declares a non-empty `requires` tag, resolve the guard via `LifecycleGuardRegistry` and run `check()` with the new data, the action name, and the caller's uid — rejecting with code `lifecycle-guard-denied` when the verdict is not allowed.

Each rejection MUST stamp a structured error onto the event and stop propagation, so the controller surfaces it (HTTP 422 for invalid value/transition, 403 for guard denial). The listener MUST be a no-op when the event is not an `ObjectUpdatingEvent`, when there is no prior object state (initial state is REQ-010's concern), when the schema or its lifecycle annotation is absent, or when the lifecycle field value is unchanged.

#### Scenario: Allowed transition passes
- **GIVEN** a schema declaring a transition `open` with `from: ["draft"], to: "open"`
- **AND** an object whose `status` changes from `"draft"` to `"open"`
- **WHEN** `ObjectUpdatingEvent` fires and the listener runs
- **THEN** propagation MUST continue and no error MUST be stamped on the event

#### Scenario: Disallowed transition is rejected
- **GIVEN** the same schema and an object whose `status` changes from `"closed"` to `"open"` (no transition allows that pair)
- **WHEN** the listener runs
- **THEN** the event MUST carry a structured error with code `lifecycle-invalid-transition` naming the from/attempted values
- **AND** propagation MUST be stopped

#### Scenario: Non-string lifecycle value is rejected
- **GIVEN** an object whose lifecycle field is changed to a null or empty value
- **WHEN** the listener runs
- **THEN** the event MUST carry an error with code `lifecycle-invalid-value`
- **AND** propagation MUST be stopped

#### Scenario: Guard denial maps to 403
- **GIVEN** a matched transition declaring `requires: "decidesk.meeting.openGuard"` whose guard returns a deny verdict
- **WHEN** the listener runs
- **THEN** the event MUST carry an error with code `lifecycle-guard-denied` and the guard's message
- **AND** propagation MUST be stopped

#### Scenario: Unchanged lifecycle value is a no-op
- **GIVEN** an object update where the lifecycle field value is identical between old and new
- **WHEN** the listener runs
- **THEN** no validation MUST be performed and propagation MUST continue

#### Notes
- Trust contract: this listener only fires on `ObjectUpdatingEvent`, dispatched by `ObjectService::saveObject()`. Code paths that mutate an object outside `saveObject()` (direct `MagicMapper::update`, raw SQL, import bypass) skip the listener and can persist an invalid lifecycle value. Callers MUST go through `saveObject()` for the guarantee to hold; a DB-level CHECK constraint is a future hardening step.
- `loadSchema()` uses `_multitenancy: false` (system-level lookup). The guard receives the loaded object payload, the action name, and the caller's uid via `IUserSession`.

## Non-Functional Requirements

- **i18n (ADR-007)**: The validation listener emits structured rejection messages (`lifecycle-invalid-value`, `lifecycle-invalid-transition`, `lifecycle-guard-denied`) that the controller surfaces to the user as HTTP 422/403 bodies — these are user-facing and SHOULD be translatable (Dutch + English). The shipped messages are hardcoded English via `sprintf()` and do not yet route through `IL10N`; this is captured as an observed gap (see change Notes), not changed in this reverse-spec pass. The initial-state listener emits no user-facing strings (warnings are log-only).
- **Layering (ADR-003)**: Both listeners react to domain events (`ObjectCreatingEvent`/`ObjectUpdatingEvent`) dispatched by `ObjectService::saveObject()` and resolve schemas via `SchemaMapper` — the service/listener boundary is respected; no controller-to-mapper shortcut is introduced.
- **Trust boundary**: The update guarantee holds only for mutations that flow through `saveObject()`; out-of-band writes (direct `MagicMapper::update`, raw SQL, import bypass) skip the listener (see REQ Notes).

## Acceptance Criteria

- [x] On create, a declared `initial` lifecycle value is force-set only when the caller leaves the field absent/null/empty; caller-supplied values are preserved.
- [x] On update, a lifecycle-field change with no declared allowing transition is rejected with `lifecycle-invalid-transition`, a non-string value with `lifecycle-invalid-value`, and a failed `requires` guard with `lifecycle-guard-denied`; each stops propagation.
- [x] Both listeners are no-ops when the event type, prior state, schema, or lifecycle annotation preconditions are not met.
- [x] Behaviour annotated in code with `@spec object-lifecycle#...` pointers on `LifecycleInitialStateListener::handle` and `LifecycleValidationListener::handle`.
