## ADDED Requirements

### Requirement: State-machine transitions MUST dispatch a typed ObjectTransitionedEvent
When an object's lifecycle field is changed through a declared transition (per `x-openregister-lifecycle`), the system MUST dispatch an `ObjectTransitionedEvent` carrying the post-transition `ObjectEntity` together with the transition metadata. This lets listeners (notifications, cascades, calculation re-materialisation, audit enrichment) react to the specific transition without inferring the action from the generic `ObjectUpdatedEvent`.

#### Scenario: Transition event carries object and transition metadata
- **GIVEN** schema `besluiten` declares a lifecycle transition `publish` from state `concept` to state `vastgesteld`
- **WHEN** the `publish` transition is applied to an object and persisted
- **THEN** an `ObjectTransitionedEvent` MUST be dispatched after the lifecycle-field update
- **AND** `getObject()` MUST return the `ObjectEntity` in its post-transition state
- **AND** `getAction()` MUST return `"publish"`, `getFrom()` MUST return `"concept"`, and `getTo()` MUST return `"vastgesteld"`
- **AND** `getRegister()` and `getSchema()` MUST return the object's register and schema slugs

#### Scenario: System-applied transition reports a null caller
- **GIVEN** a transition is applied by a background process rather than an interactive user
- **WHEN** the `ObjectTransitionedEvent` is constructed
- **THEN** `getUserId()` MUST return `null`
- **AND** when the transition was applied by an authenticated user, `getUserId()` MUST return that user's uid

### Requirement: Object unlock MUST dispatch a typed ObjectUnlockedEvent
Symmetric to the lock operation, releasing a lock on an object MUST dispatch an `ObjectUnlockedEvent` carrying the affected `ObjectEntity`. This completes the lock/unlock pair so listeners can observe both halves of the locking lifecycle.

#### Scenario: Unlock dispatches the unlocked object
- **GIVEN** object `obj-1` is currently locked
- **WHEN** the lock on `obj-1` is released
- **THEN** an `ObjectUnlockedEvent` MUST be dispatched
- **AND** `getObject()` MUST return the `ObjectEntity` whose lock was released
