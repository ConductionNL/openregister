# Tasks: expression-value-sources

- [ ] 1.1 `ExpressionValueSources`: resolve a reference through integriq's registry when it is installed, report unresolved otherwise; never read the environment.
- [ ] 1.2 `ConditionDialect::holds()` replaces `source` nodes before either dialect evaluates; an unresolved reference makes the condition not hold and logs the reference only.
- [ ] 1.3 `CalculationEvaluator` refuses a `source` node in a calculation.
- [ ] 1.4 Tests: a lifecycle-shaped condition holds on a resolved value in both dialects, fails closed on a refused reference and without integriq, the value never reaches the log, a calculation refuses the node.
