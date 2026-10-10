## 1. Evaluator operators

- [x] 1.1 Add `max` and `min` arms to `evaluate()`'s `match($op)` plus a shared numeric-operand collector (null operands skipped; all-null → `null`; non-null non-numeric → `EvaluationException`) in `lib/Service/Calculation/CalculationEvaluator.php`. (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)
- [x] 1.2 Add `coalesce` arm + handler (first non-null sub-expression result; all-null → `null`; non-array args → `EvaluationException`). (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)
- [x] 1.3 Add `abs` arm + handler (single numeric operand; `null` → `null`; non-null non-numeric → `EvaluationException`). (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)
- [x] 1.4 Add `round` arm + handler (`[value]` or `[value, precision]`; default precision 0; `null` value → `null`; non-numeric value or non-integer precision → `EvaluationException`). (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)
- [x] 1.5 Add `year` arm + handler (extract integer year via `toDateOrNull()`; unparseable/`null` date → `null`). (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)
- [x] 1.6 Add `monthsElapsed` arm + handler reusing the existing `calendarDiff(..., 'months')` month logic (`[later, earlier]`; signed whole months; unparseable/`null` → `null`; fewer than two operands → `EvaluationException`). (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)

## 2. Tests

- [x] 2.1 Add `max`/`min` unit cases (multiple operands, null-skip, all-null→null, non-numeric→exception, non-array→exception) in `tests/Unit/Service/Calculation/`. (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)
- [x] 2.2 Add `coalesce` cases (first non-null, nulls-then-value, all-null→null). (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)
- [x] 2.3 Add `abs` and `round` cases (sign/zero, null→null, non-numeric→exception; default vs explicit vs negative precision, non-integer precision→exception). (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)
- [x] 2.4 Add `year` and `monthsElapsed` cases (ISO string, `DateTimeImmutable`, `@self.created`, unparseable→null; exact/partial/reversed months, one-operand→exception). (verified: lib/Service/Calculation/CalculationEvaluator.php, tests/Unit/Service/Calculation/CalculationEvaluatorTest.php)

## 3. Validation

- [ ] 3.1 Run the evaluator test suite and confirm all new cases pass.
- [ ] 3.2 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) on the changed evaluator file and fix any new findings.

Acceptance criteria:
- All seven operators dispatch from `evaluate()` and match the existing arg/null/error conventions.
- No existing operator's name, arity, or behaviour changes (backward-compatible).
- Cross-object folding (`sum`/`lookup`/`map`) is NOT added.

Quality:
- New code carries EUPL-1.2 SPDX header (file already has one) and full PHPDoc per OR conventions.
- No new PHPMD/PHPStan/Psalm regressions beyond the class's existing documented suppressions.
