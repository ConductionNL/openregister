## ADDED Requirements

### Requirement: Declarative aggregate-reference annotation
A schema MUST be able to declare an `x-openregister-aggregate-refs` annotation: a map of
named aggregate-references, each folding over MANY objects of a target `schema` into a
value the declaring schema's calculations MAY read via `@aggregate.<name>` (scalar) or
`@aggregate.<name>.<field>` (grouped). Each aggregate-reference SHALL declare a target
`schema` and a `metric` (one of `count`, `sum`, `avg`, `min`, `max`); a non-`count`
metric SHALL declare a `field`; it MAY declare a `filters` criteria map (each value a
literal or a `@self.<field>` token, parameterising the aggregation by the saving
object) and an optional `groupBy`. Aggregate-references SHALL be resolved by
`CalculationOnSaveListener` (and `RematerialiseCalculationsCommand`) BEFORE any
calculation is evaluated, via `AggregationRunner::runAdhoc()`, and injected into the
evaluation payload under `@aggregate.<name>`; the pure `CalculationEvaluator` SHALL
remain free of I/O and resolve `@aggregate.<name>` only through its existing dotted-path
`prop` mechanism. Aggregate-reference resolution MUST run under the saving user's
existing RBAC and multitenancy scope (inherited from `runAdhoc`), and MUST NOT fail the
save when an aggregation is unresolvable.

#### Scenario: Resolve a scalar aggregate-reference
- **GIVEN** a schema `UrenRegistratie` declaring `x-openregister-aggregate-refs.billableHoursThisPeriod` with `schema: TimeEntry`, `metric: sum`, `field: hours`, and `filters` keyed by `@self`-derived values (e.g. `{ "resourceId": "@self.resourceId", "billable": true }`)
- **AND** matching `TimeEntry` rows whose `hours` sum to `120`
- **WHEN** a `UrenRegistratie` is saved and a calculation reads `{ "prop": "@aggregate.billableHoursThisPeriod" }`
- **THEN** the listener MUST resolve the aggregation via `AggregationRunner::runAdhoc()` parameterised by the object's values
- **AND** inject the scalar `120` under `@aggregate.billableHoursThisPeriod` so the calculation reads `120`

#### Scenario: Resolve a grouped aggregate-reference
- **GIVEN** a schema declaring an aggregate-reference with a `groupBy` (e.g. `{ "field": "status" }`)
- **WHEN** the object is saved and a calculation reads `{ "prop": "@aggregate.<name>.<groupKey>" }`
- **THEN** the listener MUST inject a `{<groupKey>: <value>}` map under `@aggregate.<name>`
- **AND** the calculation MUST read the per-group value via the dotted path

#### Scenario: An unresolvable aggregate-reference injects null and never fails the save
- **GIVEN** an aggregate-reference whose target schema is missing, whose query errors, or which the saving user may not list
- **WHEN** the object is saved
- **THEN** the listener MUST inject `@aggregate.<name>` as `null`
- **AND** a calculation reading `{ "prop": "@aggregate.<name>" }` MUST yield `null`
- **AND** the save MUST complete successfully (a warning MAY be logged)

#### Scenario: Aggregate-reference resolution respects RBAC and tenant scope
- **GIVEN** a saving user without list permission on the target schema, or a target schema in another tenant
- **WHEN** an aggregate-reference is resolved during save
- **THEN** the resolution MUST use `AggregationRunner::runAdhoc()` with its RBAC `list` gate and multi-tenancy predicate (never bypassed)
- **AND** the aggregation MUST NOT fold over rows the saving user cannot list, NOR leak cross-tenant data

#### Scenario: Materialised aggregate values are save-time snapshots refreshed by rematerialise
- **GIVEN** a `UrenRegistratie` whose `utilizationPercent` was materialised from aggregate-references at save time
- **WHEN** a contributing `TimeEntry` row is later added or edited
- **THEN** the previously saved `utilizationPercent` MUST remain unchanged (a snapshot) until the entry is re-saved
- **AND** running `openregister:rematerialise-calculations <register> <schema>` MUST re-resolve the aggregate-references and refresh the materialised value

#### Scenario: Aggregate-reference annotation is preserved on schema save
- **GIVEN** a schema imported with an `x-openregister-aggregate-refs` block
- **WHEN** the schema is saved
- **THEN** the annotation MUST be retained in the schema configuration because `x-openregister-aggregate-refs` is registered in `Schema::ANNOTATION_VOCABULARY`
- **AND** a calculation reading `@aggregate.<name>` MUST NOT be rejected as referencing an undeclared aggregate

### Requirement: sha256 scalar operator
The pure `CalculationEvaluator` SHALL support a `sha256` single-argument operator that
returns the lowercase hex SHA-256 digest of the stringified value of its operand. The
operator SHALL accept both `{ "sha256": [<expr>] }` and a bare `{ "sha256": <expr> }`
argument shape, MUST be deterministic (no I/O), and MUST return `null` when its operand
resolves to `null` (rather than hashing an empty string). The operator name SHALL be
recognised by `CalculationAnnotationValidator`.

#### Scenario: Hash a string operand deterministically
- **GIVEN** a calculation `{ "sha256": ["abc"] }`
- **WHEN** the calculation is evaluated
- **THEN** the result MUST be `ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad`
- **AND** repeated evaluation of the same operand MUST yield the same digest

#### Scenario: Hash a stringified non-string operand
- **GIVEN** a calculation `{ "sha256": [ { "prop": "@aggregate.contributingIds" } ] }` whose operand resolves to a non-string value
- **WHEN** the calculation is evaluated
- **THEN** the operand MUST be cast to its string form before hashing
- **AND** the result MUST be the 64-character hex SHA-256 of that string

#### Scenario: A null operand yields null, not a hash
- **GIVEN** a calculation `{ "sha256": [ { "prop": "missingField" } ] }` whose operand resolves to `null`
- **WHEN** the calculation is evaluated
- **THEN** the result MUST be `null`
- **AND** the calculation MUST NOT return the SHA-256 of an empty string
