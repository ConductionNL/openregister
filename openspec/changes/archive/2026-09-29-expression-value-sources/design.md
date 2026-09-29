## Context

Conditions reach one evaluation point, `ConditionDialect::holds()`, from the
lifecycle (`LifecycleConditionEvaluator`, `StateConditionEvaluator`), field
rules by state (`StateFieldRuleResolver`) and named conditions. Computed values
go through `CalculationEvaluator::evaluate()` and are written into the object.

## Decisions

### D-1: one node shape, resolved before evaluation

`{"source": "env:SMTP_HOST"}` is replaced by its value in a copy of the
condition before either dialect sees it. Neither dialect grows an operator, the
operator catalogue stays as it is, and the node reads the same in both.

### D-2: the registry is looked up, not depended on

`ExpressionValueSources` asks the container for
`OCA\Integriq\Expression\ExpressionValueSourceRegistry` by class name. Without
integriq every reference is unresolved. Openregister never calls `getenv()`.

### D-3: fail closed, never log the value

An unresolved reference makes `holds()` answer false, as an expression that
cannot be evaluated already does. The warning names the reference only.

### D-4: secrets stay inside a boolean

A `source` node in a calculation is refused (`InvalidArgumentException`,
"a calculation cannot read a value source"), because its result is stored and
returned. The trial and tracer surfaces show the reference, not a value.

## Risks

- A condition author who expects `env:` to work without integriq gets a
  transition that never holds. The log line says why.
