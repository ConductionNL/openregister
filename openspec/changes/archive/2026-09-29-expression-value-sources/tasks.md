# Tasks: expression-value-sources

- [x] 1.1 `ExpressionValueSources`: resolve a reference through integriq's registry when it is installed, report unresolved otherwise; never read the environment.
- [x] 1.2 `ConditionDialect::holds()` replaces `source` nodes before either dialect evaluates; an unresolved reference makes the condition not hold and logs the reference only.
- [x] 1.3 `CalculationEvaluator` refuses a `source` node in a calculation.
- [x] 1.4 Tests: a lifecycle-shaped condition holds on a resolved value in both dialects, fails closed on a refused reference and without integriq, the value never reaches the log, a calculation refuses the node.
