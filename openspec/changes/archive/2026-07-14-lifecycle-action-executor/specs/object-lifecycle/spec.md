## ADDED Requirements

### Requirement: A transition MAY declare `actions[]` that OpenRegister MUST execute on any transition form

OpenRegister MUST execute the `actions[]` a schema declares on a lifecycle
transition whenever that transition occurs, regardless of the transition form.
A schema's `x-openregister-lifecycle.transitions[<action>]` MAY declare an
`actions` array; each entry is an action envelope with a required `action` name,
an optional `actionParameters` object, and an optional `condition` string. When an
object's lifecycle field moves along a declared transition — through
`TransitionEngine::transition()` **or** through a plain list-form edit of the
lifecycle field via `ObjectService::saveObject()` — OpenRegister MUST run that
transition's declared actions.

`LifecycleActionListener`, on `ObjectUpdatingEvent`, MUST parse
`x-openregister-lifecycle` off `Schema::getConfiguration()`, match the transition
from the old and new value of the lifecycle `field`/`property` (the same match
`LifecycleValidationListener` performs), and — when the matched transition
declares a non-empty `actions[]` — invoke `LifecycleActionExecutor`. Because the
listener runs on the save path, the declared actions MUST run for every
transition form, closing the gap where list-form transitions bypassed
`TransitionEngine` and ran no actions at all.

The listener MUST NOT run actions when a prior listener has stopped propagation
(a rejected or approval-blocked transition), and MUST NOT run actions on an
initial create (no prior object state).

`LifecycleActionExecutor` MUST resolve each action's `action` name to a
`LifecycleActionInterface` handler through `LifecycleActionRegistry`. A
self-mutating handler returns the modified object payload, which the executor
threads to the next action and which the listener applies to the object before
persistence. When an action declares a `condition`, the executor MUST evaluate it
(`@self.<field>` / `@previous.<field>` equality against the new/old payload) and
skip the action when it does not hold.

`LifecycleActionRegistry` MUST ship built-in handlers for the action names
`set-fields` and `set-field` (stamping declared field values onto the object,
resolving the `@now` token to an ISO-8601 UTC timestamp). Action names without a
built-in MUST resolve to an app-registered service under that id.

A declared action that resolves to **no** registered handler, an action envelope
with no `action` name, or an **unparseable** `condition`, MUST FAIL LOUDLY —
`LifecycleActionExecutor`/`LifecycleActionRegistry` throw a `RuntimeException`
that propagates out of the listener and aborts the save. A declared action MUST
NOT be silently dropped — silent no-op is the exact defect this requirement
eliminates.

#### Scenario: A declared action runs on a list-form transition
- **GIVEN** a schema whose `activate` transition (draft → active) declares `actions: [{ "action": "set-fields", "actionParameters": { "activatedAt": "@now" } }]`
- **AND** an object whose lifecycle field is edited from `draft` to `active` through an ordinary `saveObject()` (no `TransitionEngine` call)
- **WHEN** the `ObjectUpdatingEvent` fires
- **THEN** the `set-fields` action MUST run and `activatedAt` MUST be stamped onto the object payload that is persisted

#### Scenario: A declared action naming a missing handler fails loudly
- **GIVEN** a transition that declares `actions: [{ "action": "phantom-materialiser" }]` with no service registered under `phantom-materialiser`
- **WHEN** that transition is attempted
- **THEN** `LifecycleActionRegistry::resolve()` MUST throw a `RuntimeException` naming the unregistered action
- **AND** the exception MUST propagate out of `LifecycleActionListener` (aborting the save), never a silent no-op

#### Scenario: A blocked transition runs no actions
- **GIVEN** a transition whose `ObjectUpdatingEvent` has already been rejected or blocked by a prior listener (propagation stopped)
- **WHEN** `LifecycleActionListener::handle()` runs
- **THEN** it MUST return without resolving or running any action

#### Scenario: An action condition that does not hold is skipped
- **GIVEN** an action declaring `condition: "@self.settlementMode == 'reimbursable'"` on a transition
- **AND** the transitioning object's `settlementMode` is `passthrough`
- **WHEN** the executor runs the transition's actions
- **THEN** the conditioned action MUST NOT run and its handler MUST NOT be resolved
