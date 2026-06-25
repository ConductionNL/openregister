## Context

`CalculationEvaluator` (`lib/Service/Calculation/CalculationEvaluator.php`) is a
pure JSON-AST interpreter. `evaluate(array $object, mixed $expression)` dispatches
on a single-key operator object via `match($op)` (current arms: `prop, lit, concat,
if, not, and, or, +, -, *, /, %, eq/ne/lt/lte/gt/gte, now, diffDays, formatDate,
dateDiff`). Each arm delegates to a private handler that re-evaluates its operands
through `evaluate()`, so operators compose recursively. The engine has no I/O — it
reads only the object payload (plus the synthetic `@self` block the
`CalculationOnSaveListener` injects). Materialisation, serialisation, and the
`materialise: true` gate live in the listener and are operator-agnostic; adding
operators requires no listener change.

Established conventions in the existing arms (read from the code, to be matched):

- **Operand shape.** Multi-operand ops take an array of sub-expressions (e.g.
  `arith()`); fixed-arity ops index `$args[0]`, `$args[1]` (e.g. `divide`, `diffDays`).
- **Numeric guard.** Arithmetic uses `is_numeric($v) === false → throw
  EvaluationException` and coerces with `$v + 0` (`arith`, `subOrNeg`, `divide`).
- **Date handling.** `toDateOrNull()` returns `DateTimeImmutable|null`; date ops
  (`diffDays`, `formatDate`) return `null` when an operand is unparseable rather
  than throwing. Calendar month math already exists in `calendarDiff()`
  (`$interval->y * 12 + $interval->m`, sign from `$interval->invert`).
- **Errors.** Malformed arity / wrong arg type → `EvaluationException` with a
  descriptive message; data-shaped failures (null/unparseable date) → `null`.

The fleet gap (shillinq, ~43 calcs) is that common derived fields — a clamped
total, a null-fallback default, an absolute variance, a rounded amount, a year
extracted from a date, months-elapsed for an ageing bucket — have no operator and
fall back to imperative PHP, violating ADR-031's declarative-first rule.

## Goals / Non-Goals

**Goals:**
- Add `max, min, coalesce, abs, round, year, monthsElapsed` as pure per-object
  operators, each matching the existing evaluator's arg/null/error conventions.
- Keep the change additive and backward-compatible (no existing arm touched).
- Cover each operator with unit tests including null/edge cases.

**Non-Goals:**
- **Cross-object folding** (`sum`/`lookup`/`map` over OTHER objects). These require
  a register/schema query surface the per-object evaluator deliberately does not
  have. They belong to `x-openregister-aggregations`. Stated as an explicit
  non-goal so authors do not reach for `max`/`min` expecting a cross-object reduce.
- Changing the `materialise` gate, serialisation, listener, or schema validator.
- Twig-path computed fields (a separate mechanism in the same `computed-fields`
  capability) — untouched.

## Decisions

### ADR-031 declarative-vs-imperative decision

| Behaviour | Declarative (this change) | Imperative (rejected) | Decision |
|---|---|---|---|
| Clamp / pick extreme (`max`,`min`) | New evaluator operator | Per-app PHP calc service | **Declarative** — pure per-object math, fits `x-openregister-calculations` |
| Null-fallback (`coalesce`) | New operator | `if(not(prop),…)` chains | **Declarative** — first-non-null is a primitive, chains are unreadable |
| Magnitude / rounding (`abs`,`round`) | New operator | PHP at read time | **Declarative** — pure scalar math |
| Year extraction (`year`) | New operator | `formatDate(d,'Y')` then cast | **Declarative** — typed integer result, avoids string round-trip |
| Whole months (`monthsElapsed`) | New operator reusing calendar logic | `dateDiff({unit:'months'})` | **Declarative** — `dateDiff` already covers months but requires the dict arg shape; `monthsElapsed(later,earlier)` is the positional twin matching `diffDays`, and is what the audited calcs expect |

This is pure per-object math/date logic with no external reach, so it is squarely
declarative per ADR-031's calculations row — no exception applies.

### Operator semantics (the contract for the spec)

| Op | Arity | Operand semantics | Null handling | Error (`EvaluationException`) |
|---|---|---|---|---|
| `max` | N ≥ 1 | array of numeric sub-exprs | a `null` operand is skipped; all-null → `null` | non-array args, or a non-null non-numeric operand |
| `min` | N ≥ 1 | array of numeric sub-exprs | a `null` operand is skipped; all-null → `null` | non-array args, or a non-null non-numeric operand |
| `coalesce` | N ≥ 1 | array of sub-exprs (any type) | returns first operand that is not `null`; all-null → `null` | non-array args |
| `abs` | 1 | single numeric sub-expr | `null` operand → `null` | non-null non-numeric operand |
| `round` | 1 or 2 | `[value]` or `[value, precision]` | `null` value → `null` | non-array args, non-numeric value, or non-integer precision |
| `year` | 1 | single date sub-expr | unparseable / `null` date → `null` | (none beyond arity — mirrors `formatDate`) |
| `monthsElapsed` | 2 | `[later, earlier]` date sub-exprs | either date unparseable / `null` → `null` | fewer than two operands |

Notes:
- `max`/`min` **skip** null operands (rather than returning null) so a calc over an
  optional field still computes from the present operands; this matches spreadsheet
  `MAX`/`MIN` and the audited usage. `coalesce` is the operator for "treat absent as
  a value".
- `round` precision is the number of decimals (PHP `round($v, $p)`); negative
  precision is allowed (PHP semantics) but precision MUST be an integer.
- `year` returns an `int` (e.g. `2026`), reusing `toDateOrNull()`.
- `monthsElapsed` is **whole calendar months** (signed not required by the audited
  calcs, but for symmetry with `diffDays`/`calendarDiff` it returns a signed int:
  positive when `later` is after `earlier`). It reuses the existing
  `calendarDiff(..., 'months')` logic — no new date arithmetic.

### Placement

Add seven arms to the `match` in `evaluate()` and seven small private handlers
following the file's one-handler-per-operator style. `max`/`min` share a numeric-
operand collector; `monthsElapsed` delegates to the existing month branch of
`calendarDiff()`. No public signature changes.

## Risks / Trade-offs

- **`monthsElapsed` calendar vs 30-day ambiguity** → Decision: **calendar whole
  months** (reusing `calendarDiff`), consistent with `dateDiff(unit:'months')`. A
  30-day-bucket variant, if ever needed, is `(/ (diffDays a b) 30)` and needs no new
  operator. Flagged as a deferred question.
- **`max`/`min` null-skip vs null-propagate** → null-skip chosen (spreadsheet
  parity). Documented so reviewers do not "fix" it to propagate. Deferred question.
- **PHPMD class-complexity** → the class already carries
  `@SuppressWarnings(PHPMD.ExcessiveClassComplexity)` for its 20+ operator dispatch;
  seven more arms stay within that documented suppression. No new suppression needed
  beyond per-method cyclomatic where guard chains require it.

## Migration Plan

Additive — deploy is a code change with no data migration. Existing objects are
unaffected until a schema declares a calc using a new operator and the object is
re-saved (or re-materialised via the existing `occ
openregister:rematerialise-calculations`). Rollback = revert the file; any object
whose materialised field used a new operator simply stops updating (the stored value
remains). No schema or DB rollback.

## Open Questions

- `monthsElapsed`: calendar-whole-months (chosen) vs 30-day-period — confirm with
  the shillinq calc audit owner. Provisional: calendar.
- `max`/`min` null operand: skip (chosen) vs propagate-null — confirm against the
  audited calcs. Provisional: skip.

## Test Plan

Unit tests in `tests/Unit/Service/Calculation/` (extend
`CalculationEvaluatorTest.php` or add a sibling `ScalarFunctionsTest.php`), one group
per operator:
- `max`/`min`: multiple numerics; with a null operand (skipped); all-null → null;
  non-numeric operand → `EvaluationException`; non-array args → exception.
- `coalesce`: first non-null returned; nulls then a value; all-null → null.
- `abs`: positive, negative, zero; null → null; non-numeric → exception.
- `round`: default precision (0); explicit precision (2); negative precision; null
  value → null; non-numeric value → exception; non-integer precision → exception.
- `year`: ISO date string; `DateTimeImmutable`; `@self.created`; unparseable → null.
- `monthsElapsed`: exact months; partial month (floors); reversed order (negative);
  unparseable operand → null; one operand → exception.
