## ADDED Requirements

### Requirement: JSON-AST calculation expressions MUST be evaluated by a pure-function evaluator

OpenRegister MUST provide a second derivation engine, distinct from the Twig-based
`ComputedFieldHandler`, that evaluates calculations expressed as a single-key JSON
expression AST. `CalculationEvaluator::evaluate(array $object, mixed $expression)`
MUST accept either a bare scalar literal (resolved through the shared
`PlaceholderResolver`) or a single-key array of the form `{ "<op>": <args> }`, and MUST
dispatch on the operator key. The recognised v1 vocabulary is: `prop`, `lit`, `concat`,
`if`, `not`, `and`, `or`, the arithmetic operators `+ - * / %`, the comparison operators
`eq ne lt lte gt gte`, and the date operators `now`, `diffDays`, `formatDate`, `dateDiff`.
The evaluator MUST be side-effect-free: no I/O, no database access. Any malformed
expression, unknown operator, non-numeric arithmetic operand, zero divisor, or unknown
`dateDiff` unit MUST raise an `EvaluationException` rather than returning a silent default.

#### Scenario: Single-key dispatch on a known operator
- **GIVEN** the expression `{ "+": [{ "prop": "aantal" }, 1] }` and an object `{ "aantal": 4 }`
- **WHEN** `evaluate()` is called
- **THEN** the result MUST be `5`

#### Scenario: Bare scalar resolves through the placeholder resolver
- **GIVEN** the expression is the string `"$now"` (a placeholder, not an array)
- **WHEN** `evaluate()` is called
- **THEN** the value MUST be resolved by the shared `PlaceholderResolver` and returned as-is for non-placeholder scalars

#### Scenario: Dotted-path and @self property resolution
- **GIVEN** an object whose `@self` metadata was injected by the on-save listener
- **WHEN** the expression `{ "prop": "@self.created" }` is evaluated
- **THEN** the value at the dotted path MUST be returned
- **AND** a path that does not exist MUST resolve to null rather than raising

#### Scenario: Expression with more than one top-level key is rejected
- **GIVEN** the expression `{ "+": [1, 2], "-": [3] }` (two keys)
- **WHEN** `evaluate()` is called
- **THEN** an `EvaluationException` MUST be raised stating the expression must be a single-key object

#### Scenario: Arithmetic guards reject non-numeric and zero-divisor operands
- **GIVEN** the expression `{ "/": [{ "prop": "a" }, { "prop": "b" }] }`
- **WHEN** `b` resolves to `0`
- **THEN** an `EvaluationException` MUST be raised requiring non-zero numeric operands
- **AND** when any operand of `+ - * %` is non-numeric an `EvaluationException` MUST be raised

#### Scenario: Comparison normalises ISO-date operands before ordering
- **GIVEN** the expression `{ "lt": [{ "prop": "start" }, { "prop": "end" }] }` with ISO-8601 date strings
- **WHEN** `evaluate()` is called
- **THEN** both operands MUST be coerced to integer timestamps before the `<` comparison
- **AND** an ordering comparison against a null operand MUST yield false

#### Scenario: dateDiff computes a signed integer difference in the requested unit
- **GIVEN** the expression `{ "dateDiff": { "from": "now", "to": { "prop": "@self.dueDate" }, "unit": "days" } }`
- **WHEN** `to` is after `from`
- **THEN** the result MUST be a positive integer day count
- **AND** when `to` is before `from` the result MUST be negative
- **AND** an unsupported `unit` MUST raise an `EvaluationException`
- **AND** an unparseable `from` or `to` MUST yield null

### Requirement: Calculation annotations MUST be validated at schema-save time

`CalculationAnnotationValidator::validate(array $schema)` MUST validate the
`x-openregister-calculations` annotation before a schema is persisted and MUST return a
list of `{code, message}` errors (empty list when the annotation is absent or valid).
Each calculation declaration MUST be an object with a `type` drawn from
`[string, integer, number, boolean, date]` and a non-empty `expression`. Every `prop`
reference inside an expression MUST resolve either to a property declared on the schema,
to a sibling calculation declared in the same annotation, or to an allowlisted
`@self.<field>` system field (`id`, `uuid`, `register`, `schema`, `owner`, `created`,
`updated`). Every operator key MUST be in the v1 vocabulary. The validator MUST detect
cycles in the calc-to-calc dependency graph and report them.

#### Scenario: Absent annotation produces no errors
- **GIVEN** a schema with no `x-openregister-calculations` key
- **WHEN** `validate()` is called
- **THEN** the result MUST be an empty error list

#### Scenario: Empty annotation is rejected
- **GIVEN** a schema with `x-openregister-calculations` present but empty
- **WHEN** `validate()` is called
- **THEN** the result MUST include an error with code `calculations-empty`

#### Scenario: Bad type or missing expression is reported per calculation
- **GIVEN** a calculation declaring `type: "object"` (not in the allowed set) or omitting `expression`
- **WHEN** `validate()` is called
- **THEN** an error with code `calculation-bad-type` or `calculation-no-expression` MUST be returned for that calculation

#### Scenario: Unknown property reference is reported
- **GIVEN** a calculation whose expression references `{ "prop": "doesNotExist" }`
- **WHEN** `validate()` is called
- **THEN** an error with code `calculation-prop-unknown` MUST be returned
- **AND** a reference to a sibling calculation name MUST be accepted and recorded as a dependency edge

#### Scenario: Unknown @self field is reported
- **GIVEN** a calculation referencing `{ "prop": "@self.secret" }`
- **WHEN** `validate()` is called
- **THEN** an error with code `calculation-self-unknown` MUST be returned listing the allowed system fields

#### Scenario: Calculation cycle is detected
- **GIVEN** calculation `a` referencing `b` and calculation `b` referencing `a`
- **WHEN** `validate()` runs DFS-colouring cycle detection over the dependency graph
- **THEN** an error with code `calculation-cycle` MUST be returned naming the cycle path

#### Scenario: dateDiff argument dict is validated
- **GIVEN** a calculation using `dateDiff` without all of `from`, `to`, `unit`
- **WHEN** `validate()` is called
- **THEN** an error with code `calculation-dateDiff-missing-keys` MUST be returned
- **AND** a `dateDiff` whose `unit` is a literal string outside the supported list MUST report `calculation-dateDiff-invalid-unit`

## Notes

- `ConditionMatcher` and `OperatorEvaluator` (scanner-bundled here) implement **RBAC
  condition matching**, not computed-field derivation, and are dropped from this run.
  Worth a future `rbac-scopes` reverse-spec: `OperatorEvaluator` deliberately **fails
  closed** — an unknown operator and any null-operand comparison (`$gt/$gte/$lt/$lte`,
  `$in/$nin`) return false to mirror SQL three-valued logic, preventing a list-vs-find
  authorization drift (a real `publishedAt: null` bug fixed there). That SQL/PHP RBAC
  parity is a security-relevant invariant currently undocumented in any spec.
