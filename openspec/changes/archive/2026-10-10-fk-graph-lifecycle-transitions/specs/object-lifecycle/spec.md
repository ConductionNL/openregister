## ADDED Requirements

### Requirement: Lifecycle graph mode derives transitions from FK-scoped siblings

`x-openregister-lifecycle` SHALL support a declarative `graph` mode in addition to
the static `transitions` map. When a schema declares a non-empty `graph` block and no
non-empty `transitions` map, `TransitionEngine` SHALL derive the available and target
transitions **at runtime** from sibling objects of a related schema, scoped to the
transitioning object's own parent by a foreign key.

The `graph` block SHALL declare: `schema` (the sibling schema slug), `parentField`
(the FK property on the sibling that references the parent), `parentFrom` (the
property on the transitioning object holding the parent reference), `orderField` (the
numeric ordering property on the sibling), `finalField` (the boolean terminal-state
property on the sibling), and `allowedMoves` (one of `forward`, `adjacent`, `any`).

Derivation SHALL: read the parent reference from `object.data[parentFrom]`; fetch
sibling objects of `schema` where `parentField` equals that parent reference, ordered
ascending by `orderField` (ties broken deterministically by UUID); locate the object's
current state (`object.data[field]`) within that ordered list; and compute candidate
targets by `allowedMoves` — `forward` yields only the next-higher-ordered sibling,
`adjacent` yields the next-higher and next-lower siblings, `any` yields every sibling
except the current one. Each derived action SHALL have a stable id `move-to-<targetUuid>`,
a `to` equal to the target UUID, and a `label` equal to the target's display name.

The derivation used by `availableActions()` and the validation used by `transition()`
SHALL be the SAME code path, so a client can only apply a `move-to-<uuid>` action that
the current graph state offers.

#### Scenario: Forward move offers only the next status
- **GIVEN** a `case` object whose `status` is `Ontvangen` (order 1) and whose graph declares `allowedMoves: forward`
- **AND** sibling `statusType` objects `Ontvangen` (1), `In behandeling` (2), `Afgehandeld` (3) all scoped to the case's `caseType`
- **WHEN** `availableActions()` is called for the object
- **THEN** the result MUST contain exactly one action `move-to-<InBehandelingUuid>` targeting `In behandeling`

#### Scenario: Adjacent move offers previous and next status
- **GIVEN** the same object at `status` `In behandeling` (order 2) with `allowedMoves: adjacent`
- **WHEN** `availableActions()` is called
- **THEN** the result MUST contain exactly two actions targeting `Ontvangen` (order 1) and `Afgehandeld` (order 3)

#### Scenario: Any move offers every other sibling
- **GIVEN** the same object at `status` `In behandeling` with `allowedMoves: any`
- **WHEN** `availableActions()` is called
- **THEN** the result MUST contain one action per sibling except the current one

#### Scenario: Applying a derived transition mutates and saves the object
- **GIVEN** a `case` object at `status` `Ontvangen` with `allowedMoves: forward`
- **WHEN** `transition()` is called with action `move-to-<InBehandelingUuid>`
- **THEN** the object's `status` MUST be saved as the `In behandeling` UUID through the standard object save path
- **AND** an `ObjectTransitionedEvent` MUST be dispatched with `from` = the `Ontvangen` UUID and `to` = the `In behandeling` UUID

#### Scenario: A target the graph does not allow is rejected
- **GIVEN** a `case` object at `status` `Ontvangen` with `allowedMoves: forward`
- **WHEN** `transition()` is called with action `move-to-<AfgehandeldUuid>` (order 3, not adjacent)
- **THEN** the transition MUST be rejected and the object's `status` MUST NOT change

#### Scenario: Object without a parent reference yields no actions
- **GIVEN** a `case` object whose `parentFrom` property is empty
- **WHEN** `availableActions()` is called
- **THEN** the result MUST be an empty list

### Requirement: Terminal graph states lock out non-any moves

The engine MUST lock out moves out of a terminal graph state: when the object's
current sibling has `finalField` set to true, `TransitionEngine` SHALL yield no
candidate targets under `allowedMoves` `forward` or `adjacent` (the state is a sink).
Under `allowedMoves` `any`, terminality SHALL be advisory and the engine SHALL still
offer moves to the other siblings.

#### Scenario: Final state blocks forward and adjacent moves
- **GIVEN** a `case` object at `status` `Afgehandeld` whose `statusType.isFinal` is true, with `allowedMoves: forward`
- **WHEN** `availableActions()` is called
- **THEN** the result MUST be an empty list

#### Scenario: Any mode overrides terminal lockout
- **GIVEN** the same final-state object but with `allowedMoves: any`
- **WHEN** `availableActions()` is called
- **THEN** the result MUST contain actions targeting the non-final siblings

### Requirement: Static transitions take precedence over graph mode

The engine MUST prefer static transitions over graph mode: when a schema declares
BOTH a non-empty static `transitions` map and a `graph` block, `TransitionEngine`
SHALL use only the static `transitions` map and SHALL ignore the `graph` block, in
both `availableActions()` and `transition()`. Schemas declaring only
`transitions` SHALL behave exactly as before this change (no regression).

#### Scenario: Both declared uses static path
- **GIVEN** a schema declaring both a non-empty `transitions` map and a `graph` block
- **WHEN** `availableActions()` is called for an object of that schema
- **THEN** the actions MUST be derived from the static `transitions` map only
- **AND** no sibling objects MUST be fetched for derivation

### Requirement: Schema validation accepts the graph block and object-form initial

`LifecycleAnnotationValidator` SHALL accept a `graph` block on
`x-openregister-lifecycle` and SHALL shape-check it: `schema`, `parentField`,
`parentFrom`, `orderField`, and `finalField` MUST be non-empty strings, and
`allowedMoves` MUST be one of `forward`, `adjacent`, `any`. When `graph` is present,
the `field` property MUST be a non-empty string but the `enum`/`type:string`
constraint on that field SHALL be relaxed (a `$ref` lifecycle field has no enum).
`initial` MAY be either the existing literal-string form OR an object of the form
`{ "from": "<property>", "field": "<property>" }`; both string keys MUST be non-empty
when the object form is used. Validation SHALL NOT resolve sibling schemas or parent
objects — existence is a runtime concern. Validation errors SHALL use the existing
`lifecycle-*` error-code convention.

#### Scenario: Valid graph annotation passes validation
- **GIVEN** a schema whose `x-openregister-lifecycle` declares a well-formed `graph` block and object-form `initial`
- **WHEN** the schema is validated
- **THEN** `LifecycleAnnotationValidator` MUST return no errors

#### Scenario: Invalid allowedMoves is rejected
- **GIVEN** a `graph` block whose `allowedMoves` is `sideways`
- **WHEN** the schema is validated
- **THEN** the validator MUST return an error identifying the invalid `allowedMoves` value

#### Scenario: Missing graph key is rejected
- **GIVEN** a `graph` block missing `parentField`
- **WHEN** the schema is validated
- **THEN** the validator MUST return an error identifying the missing key

### Requirement: Object-form initial auto-seeds the lifecycle field on create

The object-create pipeline MUST auto-seed the lifecycle field when the schema
declares an object-form `initial` (with `from` and `field` keys) on
`x-openregister-lifecycle`: on the CREATE path only (never on update), when the
lifecycle field is absent, null, or the empty string, the pipeline MUST read the
parent reference from the object's `initial.from` property, load the parent object
through the standard `ObjectService` read path, and set the lifecycle field to the
parent's `initial.field` value BEFORE schema validation and persistence.

An explicitly provided lifecycle value MUST NOT be overwritten by the seed step.
When the parent reference is empty, the parent cannot be loaded, or the parent's
`initial.field` value is empty, the seed step SHALL be a no-op and the create SHALL
proceed with the field unset (normal schema validation then applies). The seed step
SHALL NOT dispatch an `ObjectTransitionedEvent` (it is an initialisation, not a
transition), and the legacy literal-string `initial` form SHALL keep its existing
static-mode semantics unchanged (no auto-seed behaviour change for static schemas).

#### Scenario: Empty lifecycle field is seeded from the parent on create
- **GIVEN** a `case` schema declaring `initial: { "from": "caseType", "field": "initialStatus" }`
- **AND** a create payload whose `caseType` references an `Omgevingsvergunning` case type with `initialStatus` = the `Ontvangen` UUID and whose `status` is empty
- **WHEN** the object is created
- **THEN** the persisted object's `status` MUST equal the `Ontvangen` UUID
- **AND** no `ObjectTransitionedEvent` MUST be dispatched for the seed

#### Scenario: Explicitly provided value is not overwritten
- **GIVEN** the same schema and a create payload whose `status` is explicitly set to the `In behandeling` UUID
- **WHEN** the object is created
- **THEN** the persisted object's `status` MUST equal the `In behandeling` UUID (the client-supplied value wins)

#### Scenario: Missing parent reference makes the seed a no-op
- **GIVEN** the same schema and a create payload with an empty `caseType` and an empty `status`
- **WHEN** the object is created
- **THEN** the seed step MUST be a no-op and the `status` field MUST remain unset
- **AND** normal schema validation MUST still apply to the unset field

#### Scenario: Parent without an initial status makes the seed a no-op
- **GIVEN** the same schema and a create payload whose `caseType` references a parent whose `initialStatus` is empty
- **WHEN** the object is created
- **THEN** the seed step MUST be a no-op and the `status` field MUST remain unset
