---
kind: code
---

## Why

Integriq built the source half of `allowlisted-expression-sources`: a prefixed
value source registry (`OCA\Integriq\Expression\ExpressionValueSourceRegistry`)
where `env:NAME` resolves only a variable an administrator listed by exact
name, every change to that list is logged, and every `env:` value is declared a
secret (openregister#4169). No openregister evaluator could reach it. None
reads the environment either, so today a condition that needs an instance
setting (an SMTP host, a tenant flag) cannot be written at all.

## What Changes

- A condition MAY name a value source with a `{"source": "<prefix>:<key>"}`
  node wherever it takes an operand. Lifecycle transition conditions, field
  rules by state and named conditions all evaluate through `ConditionDialect`,
  so that one class resolves the node, in both dialects (JSONLogic and the JSON
  AST).
- `ExpressionValueSources` is openregister's only door to the registry. It asks
  integriq's registry when integriq is installed and never reads the
  environment itself, so there is one allowlist and one audit trail.
- Fail closed: a reference the registry refuses, or any reference while
  integriq is not installed, makes the condition not hold, and the refusal is
  logged with the reference, never the value.
- A resolved value lives only inside the boolean evaluation. A computed value
  (`calculation`) is stored and returned, so a `source` node there is refused
  at evaluation with a message naming the reference: a secret must never
  become object data.

## Impact

- Integriq's change names this one as the evaluator wiring.
- No schema or data migration. A condition without a `source` node evaluates
  exactly as before.
