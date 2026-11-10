---
status: proposed
retrofit_extensions: [Save-Time Materialisation of Declared Calculations]
---

# Computed Fields — on-save calculation materialisation (delta)

**Cross-references**: [computed-fields main spec](../../../../specs/computed-fields/spec.md), archived change `2026-04-29-calculations-annotation` (the `x-openregister-calculations` JSON-AST annotation this listener consumes).

## Purpose of this delta

The `computed-fields` capability already specifies save-time evaluation of `computed.expression` properties under "Save-Time Evaluation". This delta retroactively captures the observed behaviour of `CalculationOnSaveListener`, the event-driven component that materialises declared calculations into the persisted object payload. It runs from `ObjectCreatingEvent` / `ObjectUpdatingEvent` (the "creating"/"updating" pre-persist phase), implements the `x-openregister-calculations` annotation variant, and adds evaluator-context plumbing (synthetic `@self`, change-detection, declaration-order iteration) not described by the existing save-time scenarios.

## ADDED Requirements

### Requirement: Save-Time Materialisation of Declared Calculations

When a schema declares an `x-openregister-calculations` configuration block, the system MUST materialise each calculation marked `materialise: true` into the object payload before the object is persisted, on both create and update. Materialisation MUST run during the `ObjectCreatingEvent` / `ObjectUpdatingEvent` (pre-persist) phase via `CalculationOnSaveListener`. The listener MUST iterate calculations in declaration order (a later calculation MAY reference an earlier one; the validator's cycle check guarantees the graph is acyclic), evaluate each expression through `CalculationEvaluator`, and write the serialised result back into the object data. The listener MUST NOT abort the save when an individual calculation fails.

#### Scenario: Materialise a calculation into the payload on create
- **GIVEN** a schema whose configuration declares `x-openregister-calculations` with an entry `total` having `materialise: true` and an expression over other fields
- **WHEN** an object is created and `ObjectCreatingEvent` fires
- **THEN** `CalculationOnSaveListener::handle()` MUST invoke `process()` with the new object
- **AND** the evaluated value MUST be written into the object data under key `total`
- **AND** the materialised value MUST be present in the persisted object

#### Scenario: Re-materialise on update
- **GIVEN** an existing object with a materialised calculation `total`
- **WHEN** the object is updated and `ObjectUpdatingEvent` fires
- **THEN** `CalculationOnSaveListener::handle()` MUST invoke `process()` with the new object state (`getNewObject()`)
- **AND** `total` MUST be recomputed from the updated source data

#### Scenario: Only `materialise: true` entries are written
- **GIVEN** a calculations block containing one entry with `materialise: true` and one with `materialise` unset or false
- **WHEN** the object is saved
- **THEN** only the `materialise: true` entry MUST be written into the payload
- **AND** the non-materialised entry MUST be skipped

#### Scenario: Synthetic `@self` metadata is available during evaluation but stripped before persist
- **GIVEN** a calculation expression that references `@self.created` or `@self.uuid`
- **WHEN** `process()` builds the evaluation context
- **THEN** it MUST inject a `@self` array containing `id`, `uuid`, `register`, `schema`, `owner`, and ISO-8601 `created` / `updated` (null when the entity timestamp is null)
- **AND** the expression MUST resolve `@self.*` references against that block
- **AND** the `@self` key MUST be removed from the data before the payload is persisted (it is a runtime aid, not user data)

#### Scenario: No-op save does not rewrite the payload
- **GIVEN** a materialised calculation whose recomputed value equals the value already stored
- **WHEN** `process()` runs
- **THEN** the listener MUST NOT call `setObject()` (the payload is only re-set when at least one materialised value actually changed)

#### Scenario: Per-calculation evaluation error is logged and skipped
- **GIVEN** one calculation whose expression raises an `EvaluationException`
- **WHEN** `process()` evaluates the calculations in order
- **THEN** the failing calculation MUST be skipped
- **AND** a WARNING MUST be logged via `LoggerInterface` including the calculation name, the object UUID, and the error message
- **AND** the remaining calculations MUST still be evaluated and the object MUST still be saved successfully

#### Scenario: DateTime results are serialised to ATOM
- **GIVEN** a calculation that returns a `DateTimeInterface` value
- **WHEN** the result is written into the payload
- **THEN** `serialise()` MUST format it as an ATOM (`DATE_ATOM`) string
- **AND** non-DateTime values MUST be stored unchanged

#### Scenario: Schema without a calculations block is a no-op
- **GIVEN** a schema whose configuration does not contain `x-openregister-calculations` (or contains a non-array value)
- **WHEN** an object of that schema is saved
- **THEN** `getCalculations()` MUST return null and `process()` MUST return without modifying the payload

#### Notes
- `loadSchema()` resolves the object's schema via `SchemaMapper::find($ref, _multitenancy: false)`; an unresolvable or empty schema reference yields a null schema and the listener returns early. The `_multitenancy: false` flag is a system-level lookup (the listener is not user-scoped) — see the change Notes for the multitenancy-boundary follow-up.
- This listener consumes the JSON-AST `x-openregister-calculations` annotation (archived change `2026-04-29-calculations-annotation`), which is the declarative sibling of the `computed.expression` form covered by the main spec's "Save-Time Evaluation" requirement. Both materialise derived values at save time.
