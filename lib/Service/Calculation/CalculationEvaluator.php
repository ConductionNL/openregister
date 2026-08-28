<?php

/**
 * OpenRegister CalculationEvaluator
 *
 * Pure-function evaluator over a JSON-shaped expression AST. No I/O, no
 * DB access, no HTTP. Inputs: object payload + expression. Output:
 * typed value or EvaluationException.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use Throwable;

/**
 * Expression AST evaluator.
 *
 * Expression shape (JSON):
 * - Scalar literal: a bare string / int / float / bool / null
 * - Property ref:   { "prop": "fieldName" }
 * - Function call:  { "<op>": [<arg>, <arg>, ...] }
 *
 * v1 vocabulary (single-token op keys):
 * - prop, lit, concat, if, not, and, or
 * - +, -, *, /, %
 * - eq, ne, lt, lte, gt, gte
 * - now (no args), diffDays(later, earlier), formatDate(date, fmt)
 * - dateDiff({from, to, unit}) — signed integer difference between two dates
 *
 * Placeholders inside literal strings (e.g. "$now", "$currentUser") are
 * resolved via the shared PlaceholderResolver.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Evaluator dispatches 20+ operator
 *   types (arithmetic, logical, date, string, comparison, etc.); each operator requires
 *   its own parse/validate/execute path. Splitting into sub-evaluators would require
 *   a plugin registry and is outside the scope of this service's single-responsibility.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     The same one-handler-per-operator design
 *   that drives the complexity also drives the line count: each operator carries its own
 *   fully-documented private handler. A plugin registry to split the file is out of scope.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Each operator (prop/concat/if/arith/compare/date/
 *   string/sha256/…) is a dedicated private handler dispatched from the single `evaluate()`
 *   match; the count rises one-per-operator by design. Collapsing handlers would lose the
 *   per-operator validation and error messages, and a plugin registry is out of scope.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-1
 */
class CalculationEvaluator {
	/**
	 * Constructor.
	 *
	 * @param PlaceholderResolver $placeholders Shared placeholder resolver for literal-string interpolation.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PlaceholderResolver $placeholders,
	) {
	}//end __construct()

	/**
	 * Transient sequence-consumption context for the current outermost evaluate() call.
	 *
	 * Set only by the public entry point when a SequenceContext is supplied
	 * (the create path in CalculationOnSaveListener). It is null on every read
	 * path so a `{ "sequence": … }` node never burns a number while merely
	 * rendering an object. Recursive internal evaluate() calls inherit it via
	 * this property; the outermost call saves/restores the previous value so
	 * nested evaluation never clears an active context prematurely.
	 *
	 * @var SequenceContext|null
	 */
	private ?SequenceContext $sequenceContext = null;

	/**
	 * Evaluate an expression against an object payload.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $expression Expression AST (scalar literal or array).
	 * @param SequenceContext|null $sequenceContext Consume-sequences context; non-null ONLY on
	 *                                              the create path. When null, `sequence` nodes
	 *                                              resolve to null instead of reserving a number.
	 *
	 * @return mixed The computed value.
	 *
	 * @throws EvaluationException When the expression is malformed or references unknown properties/operators.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-1
	 */
	public function evaluate(array $object, mixed $expression, ?SequenceContext $sequenceContext = null): mixed {
		// Outermost call installs the sequence context (when supplied); nested
		// recursive calls pass null and therefore inherit the active context.
		$restore = false;
		$previous = $this->sequenceContext;
		if ($sequenceContext !== null) {
			$this->sequenceContext = $sequenceContext;
			$restore = true;
		}

		try {
			return $this->evaluateNode(object: $object, expression: $expression);
		} finally {
			if ($restore === true) {
				$this->sequenceContext = $previous;
			}
		}
	}//end evaluate()

	/**
	 * Evaluate a single AST node (the recursion target shared by every handler).
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $expression Expression AST (scalar literal or array).
	 *
	 * @return mixed The computed value.
	 *
	 * @throws EvaluationException When the expression is malformed or references unknown properties/operators.
	 */
	private function evaluateNode(array $object, mixed $expression): mixed {
		if (is_array($expression) === false) {
			// Bare scalar — resolve placeholder strings, otherwise pass through.
			return $this->placeholders->resolve($expression);
		}

		if (count($expression) !== 1) {
			throw new EvaluationException('Expression must be a single-key object.');
		}

		$op = (string)array_key_first($expression);
		$args = $expression[$op];

		return match ($op) {
			'prop' => $this->propValue(object: $object, args: $args),
			'lit' => $this->placeholders->resolve($args),
			'concat' => $this->concat(object: $object, args: $args),
			'if' => $this->ifExpr(object: $object, args: $args),
			'not' => !$this->boolEval(object: $object, expr: $args[0] ?? null),
			'and' => $this->reduceBool(object: $object, args: $args, shortCircuit: true),
			'or' => $this->reduceBool(object: $object, args: $args, shortCircuit: false),
			'+' => $this->arith(object: $object, args: $args, reducer: fn ($a, $b) => $a + $b, initial: 0),
			'-' => $this->subOrNeg(object: $object, args: $args),
			'*' => $this->arith(object: $object, args: $args, reducer: fn ($a, $b) => $a * $b, initial: 1),
			'/' => $this->divide(object: $object, args: $args),
			'%' => $this->modulo(object: $object, args: $args),
			'eq', 'ne', 'lt', 'lte', 'gt', 'gte' => $this->compare(object: $object, args: $args, op: $op),
			'now' => $this->now(),
			'diffDays' => $this->diffDays(object: $object, args: $args),
			'formatDate' => $this->formatDate(object: $object, args: $args),
			'dateDiff' => $this->dateDiff(object: $object, args: $args),
			'dateAdd' => $this->dateAdd(object: $object, args: $args),
			'sequence' => $this->sequence(object: $object, args: $args),
			'max' => $this->minMax(object: $object, args: $args, wantMax: true),
			'min' => $this->minMax(object: $object, args: $args, wantMax: false),
			'coalesce' => $this->coalesce(object: $object, args: $args),
			'abs' => $this->absVal(object: $object, args: $args),
			'round' => $this->roundVal(object: $object, args: $args),
			'year' => $this->yearOf(object: $object, args: $args),
			'monthsElapsed' => $this->monthsElapsed(object: $object, args: $args),
			'sha256' => $this->sha256Of(object: $object, args: $args),
			default => throw new EvaluationException(sprintf('Unknown operator "%s".', $op)),
		};//end match
	}//end evaluateNode()

	/**
	 * Resolve a property reference against the object payload.
	 *
	 * Supports dotted paths for nested values and `@self` system metadata.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Property name (string) or single-element array containing it.
	 *
	 * @return mixed The resolved value, or null when the path is missing.
	 *
	 * @throws EvaluationException When the property name is empty.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-1
	 */
	private function propValue(array $object, mixed $args): mixed {
		$name = '';
		if (is_string($args) === true) {
			$name = $args;
		} elseif (is_array($args) === true) {
			$name = (string)($args[0] ?? '');
		}

		if ($name === '') {
			throw new EvaluationException('prop requires a non-empty field name.');
		}

		// Support dotted paths: `@self.created`, `parent.subfield`, etc.
		// The CalculationOnSaveListener injects `@self` system metadata so
		// calculations can reference `@self.created`, `@self.updated`, etc.
		if (strpos($name, '.') === false) {
			return ($object[$name] ?? null);
		}

		$parts = explode('.', $name);
		$current = $object;
		foreach ($parts as $part) {
			if (is_array($current) === false || array_key_exists($part, $current) === false) {
				return null;
			}

			$current = $current[$part];
		}

		return $current;
	}//end propValue()

	/**
	 * Concatenate the string forms of evaluated arguments.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Array of sub-expressions (or a single sub-expression).
	 *
	 * @return string The concatenated string.
	 */
	private function concat(array $object, mixed $args): string {
		if (is_array($args) === false) {
			return (string)$this->evaluateNode(object: $object, expression: $args);
		}

		$parts = [];
		foreach ($args as $a) {
			$parts[] = (string)($this->evaluateNode(object: $object, expression: $a) ?? '');
		}

		return implode('', $parts);
	}//end concat()

	/**
	 * Conditional branching: (cond, then[, else]).
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Argument array: [cond, then, else?].
	 *
	 * @return mixed The selected branch's evaluation, or null when no else branch.
	 *
	 * @throws EvaluationException When fewer than two arguments are supplied.
	 */
	private function ifExpr(array $object, mixed $args): mixed {
		if (is_array($args) === false || count($args) < 2) {
			throw new EvaluationException('if requires (cond, then[, else]).');
		}

		$cond = $this->boolEval(object: $object, expr: $args[0]);
		if ($cond === true) {
			return $this->evaluateNode(object: $object, expression: $args[1]);
		}

		if (count($args) >= 3) {
			return $this->evaluateNode(object: $object, expression: $args[2]);
		}

		return null;
	}//end ifExpr()

	/**
	 * Evaluate an expression and coerce the result to bool using truthy semantics.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $expr Sub-expression to evaluate.
	 *
	 * @return bool True when the value is non-empty and not zero/false/null.
	 */
	private function boolEval(array $object, mixed $expr): bool {
		$v = $this->evaluateNode(object: $object, expression: $expr);
		return $v !== null && $v !== false && $v !== 0 && $v !== '0' && $v !== '';
	}//end boolEval()

	/**
	 * Reduce a list of boolean expressions with AND/OR semantics.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Array of sub-expressions to fold.
	 * @param bool $shortCircuit True for AND (return false on first false), false for OR.
	 *
	 * @return bool The reduced result.
	 */
	private function reduceBool(array $object, mixed $args, bool $shortCircuit): bool {
		if (is_array($args) === false) {
			return $shortCircuit;
		}

		foreach ($args as $a) {
			$v = $this->boolEval(object: $object, expr: $a);
			if ($shortCircuit === true && $v === false) {
				return false;
			}

			if ($shortCircuit === false && $v === true) {
				return true;
			}
		}

		return $shortCircuit;
	}//end reduceBool()

	/**
	 * Reduce an array of numeric operands with a binary callback.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Array of operand sub-expressions.
	 * @param callable $reducer Binary callback applied to (acc, operand).
	 * @param int|float $initial Initial accumulator value.
	 *
	 * @return int|float The reduced numeric value.
	 *
	 * @throws EvaluationException When args is not an array or an operand is non-numeric.
	 */
	private function arith(array $object, mixed $args, callable $reducer, int|float $initial): int|float {
		if (is_array($args) === false) {
			throw new EvaluationException('Arithmetic requires an array of operands.');
		}

		$acc = $initial;
		foreach ($args as $a) {
			$v = $this->evaluateNode(object: $object, expression: $a);
			if (is_numeric($v) === false) {
				throw new EvaluationException('Arithmetic operand is not numeric.');
			}

			$acc = $reducer($acc, $v + 0);
		}

		return $acc;
	}//end arith()

	/**
	 * Subtract operands or, with one operand, negate it.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Operand list (1+ entries).
	 *
	 * @return int|float The subtracted/negated result.
	 *
	 * @throws EvaluationException When args is empty or any operand is non-numeric.
	 */
	private function subOrNeg(array $object, mixed $args): int|float {
		if (is_array($args) === false || count($args) === 0) {
			throw new EvaluationException('- requires at least one operand.');
		}

		$first = $this->evaluateNode(object: $object, expression: $args[0]);
		if (is_numeric($first) === false) {
			throw new EvaluationException('- first operand not numeric.');
		}

		if (count($args) === 1) {
			return -($first + 0);
		}

		$acc = $first + 0;
		$argCount = count($args);
		for ($i = 1; $i < $argCount; $i++) {
			$v = $this->evaluateNode(object: $object, expression: $args[$i]);
			if (is_numeric($v) === false) {
				throw new EvaluationException('- operand not numeric.');
			}

			$acc -= $v + 0;
		}

		return $acc;
	}//end subOrNeg()

	/**
	 * Divide the first operand by the second.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Two-operand list.
	 *
	 * @return float The quotient.
	 *
	 * @throws EvaluationException When fewer than two operands or the divisor is zero/non-numeric.
	 */
	private function divide(array $object, mixed $args): float {
		if (is_array($args) === false || count($args) < 2) {
			throw new EvaluationException('/ requires two operands.');
		}

		$a = $this->evaluateNode(object: $object, expression: $args[0]);
		$b = $this->evaluateNode(object: $object, expression: $args[1]);
		if (is_numeric($a) === false || is_numeric($b) === false || (float)$b === 0.0) {
			throw new EvaluationException('/ requires non-zero numeric operands.');
		}

		return ((float)$a) / ((float)$b);
	}//end divide()

	/**
	 * Modulo of the first operand by the second.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Two-operand list.
	 *
	 * @return int|float The remainder.
	 *
	 * @throws EvaluationException When fewer than two operands or the divisor is zero/non-numeric.
	 */
	private function modulo(array $object, mixed $args): int|float {
		if (is_array($args) === false || count($args) < 2) {
			throw new EvaluationException('% requires two operands.');
		}

		$a = $this->evaluateNode(object: $object, expression: $args[0]);
		$b = $this->evaluateNode(object: $object, expression: $args[1]);
		if (is_numeric($a) === false || is_numeric($b) === false || (float)$b === 0.0) {
			throw new EvaluationException('% requires non-zero numeric operands.');
		}

		return fmod((float)$a, (float)$b);
	}//end modulo()

	/**
	 * Compare two operands using the given operator.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Two-operand list.
	 * @param string $op One of 'eq','ne','lt','lte','gt','gte'.
	 *
	 * @return bool The comparison result.
	 *
	 * @throws EvaluationException When fewer than two operands.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Six comparison operators (eq/ne/lt/lte/gt/gte)
	 *   each require null-safety checks via && in their match arm; collapsing to a lookup
	 *   table would remove the null guards and risk undefined-comparison exceptions.
	 */
	private function compare(array $object, mixed $args, string $op): bool {
		if (is_array($args) === false || count($args) < 2) {
			throw new EvaluationException(sprintf('%s requires two operands.', $op));
		}

		$a = $this->normaliseForCompare(v: $this->evaluateNode(object: $object, expression: $args[0]));
		$b = $this->normaliseForCompare(v: $this->evaluateNode(object: $object, expression: $args[1]));
		return match ($op) {
			'eq' => $a == $b,
			'ne' => $a != $b,
			'lt' => $a !== null && $b !== null && $a < $b,
			'lte' => $a !== null && $b !== null && $a <= $b,
			'gt' => $a !== null && $b !== null && $a > $b,
			'gte' => $a !== null && $b !== null && $a >= $b,
			default => false,
		};
	}//end compare()

	/**
	 * Coerce ISO-8601 date strings + DateTimeInterface values to integer timestamps.
	 *
	 * Coerce ISO-8601 date strings + DateTimeInterface values to integer
	 * timestamps so ordering comparisons behave consistently. Other
	 * scalars pass through unchanged.
	 *
	 * @param mixed $v The value to normalise.
	 *
	 * @return mixed The normalised value (int timestamp for dates, original otherwise).
	 */
	private function normaliseForCompare(mixed $v): mixed {
		if ($v instanceof DateTimeInterface) {
			return $v->getTimestamp();
		}

		if (is_string($v) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1) {
			try {
				return (new DateTimeImmutable($v))->getTimestamp();
			} catch (Throwable) {
				return $v;
			}
		}

		return $v;
	}//end normaliseForCompare()

	/**
	 * Return the current timestamp as an immutable DateTime.
	 *
	 * @return DateTimeImmutable The current moment.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('now');
	}//end now()

	/**
	 * Return the integer day difference between two date operands.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Two-operand list: (later, earlier).
	 *
	 * @return int|null The day difference, or null when either operand isn't a parseable date.
	 *
	 * @throws EvaluationException When fewer than two operands.
	 */
	private function diffDays(array $object, mixed $args): ?int {
		if (is_array($args) === false || count($args) < 2) {
			throw new EvaluationException('diffDays requires (later, earlier).');
		}

		$later = $this->toDateOrNull(v: $this->evaluateNode(object: $object, expression: $args[0]));
		$earlier = $this->toDateOrNull(v: $this->evaluateNode(object: $object, expression: $args[1]));
		if ($later === null || $earlier === null) {
			return null;
		}

		$diff = $later->getTimestamp() - $earlier->getTimestamp();
		return (int)floor($diff / 86400);
	}//end diffDays()

	/**
	 * Format a date/time value via PHP's DateTimeImmutable::format().
	 *
	 * Trust model: the `$fmt` string is sourced from the schema annotation,
	 * which is authored at schema-edit time by an admin/operator who already
	 * has full schema-write privileges. PHP's `format()` is side-effect-free
	 * and the result is stored as a calculated field value (text) — there is
	 * no template/SQL/eval surface downstream that could turn an exotic
	 * format character into a vulnerability. Accordingly, the format string
	 * is NOT validated against an allowlist. If non-admin operators ever
	 * gain calculation-authoring rights, restrict `$fmt` to a safe subset
	 * (e.g. ISO-style `Y-m-d\TH:i:sP`, common locale formats) before calling
	 * `format()`.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Two-operand list: (date, fmt).
	 *
	 * @return string|null The formatted string, or null when the date isn't parseable.
	 *
	 * @throws EvaluationException When fewer than two operands.
	 */
	private function formatDate(array $object, mixed $args): ?string {
		if (is_array($args) === false || count($args) < 2) {
			throw new EvaluationException('formatDate requires (date, fmt).');
		}

		$date = $this->toDateOrNull(v: $this->evaluateNode(object: $object, expression: $args[0]));
		$fmt = (string)$this->evaluateNode(object: $object, expression: $args[1]);
		if ($date === null) {
			return null;
		}

		return $date->format($fmt);
	}//end formatDate()

	/**
	 * Compute the signed integer difference between two date/time values.
	 *
	 * Argument shape (dict, not positional array):
	 * ```json
	 * { "dateDiff": { "from": "now", "to": "@self.dueDate", "unit": "days" } }
	 * ```
	 *
	 * Supported units: years, months, weeks, days, hours, minutes, seconds.
	 *
	 * Both `from` and `to` accept:
	 * - The literal string `"now"` (resolved to current server time at call time)
	 * - An ISO-8601 date or datetime string
	 * - A `@self.<field>` reference that resolves to an ISO-8601 string
	 *
	 * The result is a **signed** integer: positive when `to` is after `from`,
	 * negative when `to` is before `from`.  Returns null when either operand
	 * cannot be parsed as a date.
	 *
	 * For `months` and `years`, the difference is calendar-based (using
	 * DateInterval), matching PHP's DateTimeImmutable::diff() semantics.
	 * For `weeks` and all sub-day units, the result is derived from the
	 * elapsed-second delta so DST transitions are handled consistently.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Dict with keys `from`, `to`, and `unit`.
	 *
	 * @return int|null The signed integer difference, or null when a date is unparseable.
	 *
	 * @throws EvaluationException When the args dict is missing required keys or the unit is unknown.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Input validation (type + three required
	 *   key checks) and null propagation are mandatory guard steps; each extracted helper
	 *   (validateDateDiffUnit, resolveDateOperand, applyDateDiffUnit) already carries its
	 *   own CC but PHPMD 2.x accumulates their complexity into the calling method's score.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      NPath inflation mirrors the CyclomaticComplexity
	 *   accumulation issue; the method body itself delegates entirely to extracted helpers.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-1
	 */
	private function dateDiff(array $object, mixed $args): ?int {
		if (is_array($args) === false) {
			throw new EvaluationException('dateDiff requires an object argument with keys: from, to, unit.');
		}

		if (array_key_exists('from', $args) === false
			|| array_key_exists('to', $args) === false
			|| array_key_exists('unit', $args) === false
		) {
			throw new EvaluationException('dateDiff requires keys: from, to, unit.');
		}

		$unit = $this->validateDateDiffUnit(object: $object, unitExpr: $args['unit']);

		$from = $this->resolveDateOperand(object: $object, expression: $args['from']);
		$to = $this->resolveDateOperand(object: $object, expression: $args['to']);

		if ($from === null || $to === null) {
			return null;
		}

		return $this->applyDateDiffUnit(from: $from, to: $to, unit: $unit);
	}//end dateDiff()

	/**
	 * Validate and resolve the unit expression for dateDiff.
	 *
	 * @param array<string,mixed> $object The object's stored data.
	 * @param mixed $unitExpr The unit expression to evaluate.
	 *
	 * @return string The resolved unit string.
	 *
	 * @throws EvaluationException When the unit is not in the supported list.
	 */
	private function validateDateDiffUnit(array $object, mixed $unitExpr): string {
		$validUnits = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds'];
		$unit = (string)$this->evaluateNode(object: $object, expression: $unitExpr);
		if (in_array($unit, $validUnits, true) === false) {
			throw new EvaluationException(
				sprintf(
					'dateDiff unit "%s" is invalid. Supported: %s.',
					$unit,
					implode(', ', $validUnits)
				)
			);
		}

		return $unit;
	}//end validateDateDiffUnit()

	/**
	 * Resolve a dateDiff operand expression to a DateTimeImmutable.
	 *
	 * The sentinel string "now" is replaced with the current server time.
	 *
	 * @param array<string,mixed> $object The object's stored data.
	 * @param mixed $expression The expression to evaluate.
	 *
	 * @return DateTimeImmutable|null Parsed date, or null when not parseable.
	 */
	private function resolveDateOperand(array $object, mixed $expression): ?DateTimeImmutable {
		$raw = $this->evaluateNode(object: $object, expression: $expression);
		if ($raw === 'now') {
			$raw = new DateTimeImmutable('now');
		}

		return $this->toDateOrNull(v: $raw);
	}//end resolveDateOperand()

	/**
	 * Compute the signed integer difference for a resolved unit.
	 *
	 * Calendar-based units (years, months) use DateInterval for accuracy
	 * across leap years and variable month lengths. All sub-day and week
	 * units are derived from the elapsed-second delta so DST transitions
	 * are handled consistently.
	 *
	 * @param DateTimeImmutable $from The start date.
	 * @param DateTimeImmutable $to The end date.
	 * @param string $unit One of years, months, weeks, days, hours, minutes, seconds.
	 *
	 * @return int The signed integer difference.
	 *
	 * @throws EvaluationException When the unit is unhandled (should not occur after validation).
	 */
	private function applyDateDiffUnit(DateTimeImmutable $from, DateTimeImmutable $to, string $unit): int {
		if ($unit === 'years' || $unit === 'months') {
			return $this->calendarDiff(from: $from, to: $to, unit: $unit);
		}

		$deltaSecs = $to->getTimestamp() - $from->getTimestamp();

		return match ($unit) {
			'weeks' => (int)intdiv($deltaSecs, 604800),
			'days' => (int)intdiv($deltaSecs, 86400),
			'hours' => (int)intdiv($deltaSecs, 3600),
			'minutes' => (int)intdiv($deltaSecs, 60),
			'seconds' => $deltaSecs,
			default => throw new EvaluationException(sprintf('Unhandled unit "%s".', $unit)),
		};
	}//end applyDateDiffUnit()

	/**
	 * Compute a calendar-accurate signed difference in years or months.
	 *
	 * Uses DateInterval::diff() to respect leap years and variable month lengths.
	 *
	 * @param DateTimeImmutable $from The start date.
	 * @param DateTimeImmutable $to The end date.
	 * @param string $unit Either "years" or "months".
	 *
	 * @return int The signed integer difference.
	 */
	private function calendarDiff(DateTimeImmutable $from, DateTimeImmutable $to, string $unit): int {
		$interval = $from->diff($to);
		$sign = 1;
		if ($interval->invert === 1) {
			$sign = -1;
		}

		if ($unit === 'years') {
			return $sign * $interval->y;
		}

		return $sign * ($interval->y * 12 + $interval->m);
	}//end calendarDiff()

	/**
	 * Add a duration to a date and return an ISO date / date-time string.
	 *
	 * Named-key dict argument (two mutually-exclusive shapes):
	 * ```json
	 * { "dateAdd": { "date": <expr>, "amount": <expr→int>, "unit": "days"|"weeks"|"months"|"years" } }
	 * { "dateAdd": { "date": <expr>, "duration": <expr→ISO-8601 duration e.g. "P30D"> } }
	 * ```
	 *
	 * The `amount`+`unit` shape composes a DateInterval — weeks fold to
	 * `P{n*7}D`, days to `P{n}D`, months to `P{n}M`, years to `P{n}Y`. The
	 * `duration` shape parses an ISO-8601 duration string directly
	 * (`new DateInterval("P30D")`). When the resolved date carries a time
	 * component the result keeps it (`Y-m-d\TH:i:sP`); a pure date returns
	 * `Y-m-d`.
	 *
	 * Null / empty / unparseable inputs return null (no throw) so the on-save
	 * listener's swallow-on-error keeps a bad annotation a no-op, never a 500.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Dict with `date` plus either (`amount` + `unit`) or `duration`.
	 *
	 * @return string|null The shifted date string, or null when the date/duration is unusable.
	 *
	 * @throws EvaluationException When the args dict is missing the required keys.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The method branches over the two
	 *   mutually-exclusive argument shapes (amount+unit vs ISO-duration) plus null guards
	 *   on the resolved date and interval; each branch is a distinct, required path.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      The sequential null guards (missing keys,
	 *   unparseable date, unbuildable interval) multiply into the NPath count but are each a
	 *   mandatory fail-soft return; extracting them would not remove a single decision.
	 */
	private function dateAdd(array $object, mixed $args): ?string {
		if (is_array($args) === false || array_key_exists('date', $args) === false) {
			throw new EvaluationException('dateAdd requires a "date" key plus either ("amount" + "unit") or "duration".');
		}

		$hasDuration = array_key_exists('duration', $args);
		$hasAmountUnit = (array_key_exists('amount', $args) === true && array_key_exists('unit', $args) === true);
		if ($hasDuration === false && $hasAmountUnit === false) {
			throw new EvaluationException('dateAdd requires either ("amount" + "unit") or "duration".');
		}

		$hadTime = false;
		$date = $this->toDateAddDate(object: $object, expr: $args['date'], hadTime: $hadTime);
		if ($date === null) {
			return null;
		}

		$interval = null;
		if ($hasDuration === true) {
			$interval = $this->intervalFromDuration(value: $this->evaluateNode(object: $object, expression: $args['duration']));
		}

		if ($hasDuration === false) {
			$interval = $this->intervalFromAmountUnit(
				amount: $this->evaluateNode(object: $object, expression: $args['amount']),
				unit: (string)$this->evaluateNode(object: $object, expression: $args['unit'])
			);
		}

		if ($interval === null) {
			return null;
		}

		$shifted = $date->add($interval);
		if ($hadTime === true) {
			return $shifted->format('Y-m-d\TH:i:sP');
		}

		return $shifted->format('Y-m-d');
	}//end dateAdd()

	/**
	 * Resolve the dateAdd `date` operand and record whether it carried a time component.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $expr The date sub-expression.
	 * @param bool $hadTime Out-param set true when the raw string contained a time part.
	 *
	 * @return DateTimeImmutable|null The parsed date, or null when empty/unparseable.
	 */
	private function toDateAddDate(array $object, mixed $expr, bool &$hadTime): ?DateTimeImmutable {
		$raw = $this->evaluateNode(object: $object, expression: $expr);
		if (is_string($raw) === true && preg_match('/\d{1,2}:\d{2}/', $raw) === 1) {
			$hadTime = true;
		}

		return $this->toDateOrNull(v: $raw);
	}//end toDateAddDate()

	/**
	 * Build a DateInterval from an ISO-8601 duration string (e.g. "P30D", "P1Y6M").
	 *
	 * @param mixed $value The resolved duration value.
	 *
	 * @return DateInterval|null The interval, or null when the value is empty/invalid.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateInterval is constructed directly; there is
	 *   no instance-based factory in the PHP standard library.
	 */
	private function intervalFromDuration(mixed $value): ?DateInterval {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateInterval($value);
		} catch (Throwable) {
			return null;
		}
	}//end intervalFromDuration()

	/**
	 * Build a DateInterval from an integer amount and a unit keyword.
	 *
	 * Supported units: days, weeks (folded to days), months, years.
	 *
	 * @param mixed $amount The resolved amount (must be a non-negative-int-like value).
	 * @param string $unit One of days, weeks, months, years.
	 *
	 * @return DateInterval|null The interval, or null when the amount/unit is unusable.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateInterval is constructed directly; there is
	 *   no instance-based factory in the PHP standard library.
	 */
	private function intervalFromAmountUnit(mixed $amount, string $unit): ?DateInterval {
		if (is_numeric($amount) === false) {
			return null;
		}

		$n = (int)$amount;

		$iso = match ($unit) {
			'days' => 'P' . $n . 'D',
			'weeks' => 'P' . ($n * 7) . 'D',
			'months' => 'P' . $n . 'M',
			'years' => 'P' . $n . 'Y',
			default => null,
		};

		if ($iso === null) {
			return null;
		}

		try {
			return new DateInterval($iso);
		} catch (Throwable) {
			return null;
		}
	}//end intervalFromAmountUnit()

	/**
	 * Reserve and zero-pad the next running number for a declared scope.
	 *
	 * Named-key dict argument:
	 * ```json
	 * { "sequence": { "scope": "yearly"|"monthly"|"global", "pad": 4 } }
	 * ```
	 *
	 * The scope key is derived from the current server time: `yearly` → the
	 * four-digit year, `monthly` → `YYYY-MM`, `global` → `""`. The reserved
	 * value is zero-padded to `pad` digits (default 4).
	 *
	 * CRITICAL: a sequence reserves EXACTLY ONCE, only when materialised on
	 * create. The reservation runs through the per-save SequenceContext, which
	 * CalculationOnSaveListener supplies ONLY on the create path. On every read
	 * path (and on update) the context is null, so this returns null and never
	 * burns a number.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Dict with `scope` and optional `pad`.
	 *
	 * @return string|null The zero-padded reserved number, or null when no context is active.
	 *
	 * @throws EvaluationException When the scope is missing or invalid.
	 */
	private function sequence(array $object, mixed $args): ?string {
		if (is_array($args) === false || array_key_exists('scope', $args) === false) {
			throw new EvaluationException('sequence requires a "scope" key (yearly, monthly or global).');
		}

		$scope = (string)$this->evaluateNode(object: $object, expression: $args['scope']);
		$now = new DateTimeImmutable('now');
		$scopeKey = match ($scope) {
			'yearly' => $now->format('Y'),
			'monthly' => $now->format('Y-m'),
			'global' => '',
			default => throw new EvaluationException(
				sprintf('sequence scope "%s" is invalid. Supported: yearly, monthly, global.', $scope)
			),
		};

		// Read path / update path: no context installed → never consume a number.
		if ($this->sequenceContext === null) {
			return null;
		}

		$pad = 4;
		if (array_key_exists('pad', $args) === true) {
			$padArg = $this->evaluateNode(object: $object, expression: $args['pad']);
			if (is_numeric($padArg) === true) {
				$pad = (int)$padArg;
			}
		}

		$reserved = $this->sequenceContext->reserveNext(scopeKey: $scopeKey);

		return str_pad((string)$reserved, $pad, '0', STR_PAD_LEFT);
	}//end sequence()

	/**
	 * Coerce a value to DateTimeImmutable when possible.
	 *
	 * @param mixed $v The value to coerce.
	 *
	 * @return DateTimeImmutable|null The parsed date, or null when not parseable.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface() and
	 *   DateTimeImmutable::createFromFormat() are static factory methods on PHP's built-in
	 *   class; no instance-based alternatives exist in the PHP standard library.
	 */
	private function toDateOrNull(mixed $v): ?DateTimeImmutable {
		if ($v instanceof DateTimeImmutable) {
			return $v;
		}

		if ($v instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($v);
		}

		if (is_string($v) === true && $v !== '') {
			try {
				return new DateTimeImmutable($v);
			} catch (Throwable) {
				return null;
			}
		}

		return null;
	}//end toDateOrNull()

	/**
	 * Resolve a single operand: the first element of a list, or the bare node.
	 *
	 * Single-argument ops accept either `[expr]` (engine list convention) or a
	 * bare AST node / scalar `expr`.
	 *
	 * @param mixed $args The op arguments.
	 *
	 * @return mixed The operand sub-expression.
	 */
	private function firstOperand(mixed $args): mixed {
		if (is_array($args) === true && array_is_list($args) === true) {
			return ($args[0] ?? null);
		}

		return $args;
	}//end firstOperand()

	/**
	 * Max(...) / min(...) over N numeric operands; null operands are skipped.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Operand list.
	 * @param bool $wantMax True for max, false for min.
	 *
	 * @return int|float|null The extreme value, or null when every operand is null.
	 *
	 * @throws EvaluationException When args is not a list or an operand is non-numeric.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The null-skip + non-numeric guard +
	 *   max/min direction branch over N operands sit at the CC threshold; each branch is a
	 *   distinct, required guard and extracting them would not reduce the decision count.
	 */
	private function minMax(array $object, mixed $args, bool $wantMax): int|float|null {
		if (is_array($args) === false) {
			throw new EvaluationException('max/min requires an array of operands.');
		}

		$result = null;
		foreach ($args as $a) {
			$v = $this->evaluateNode(object: $object, expression: $a);
			if ($v === null) {
				continue;
			}

			if (is_numeric($v) === false) {
				throw new EvaluationException('max/min operand is not numeric.');
			}

			$num = ($v + 0);
			if ($result === null
				|| ($wantMax === true && $num > $result)
				|| ($wantMax === false && $num < $result)
			) {
				$result = $num;
			}
		}

		return $result;
	}//end minMax()

	/**
	 * Coalesce(...): the first non-null operand, or null when all are null.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Operand list.
	 *
	 * @return mixed The first non-null value, or null.
	 *
	 * @throws EvaluationException When args is not an array.
	 */
	private function coalesce(array $object, mixed $args): mixed {
		if (is_array($args) === false) {
			throw new EvaluationException('coalesce requires an array of operands.');
		}

		foreach ($args as $a) {
			$v = $this->evaluateNode(object: $object, expression: $a);
			if ($v !== null) {
				return $v;
			}
		}

		return null;
	}//end coalesce()

	/**
	 * Abs(x): absolute value; null passes through.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Single operand (`[expr]` or bare).
	 *
	 * @return int|float|null The absolute value, or null.
	 *
	 * @throws EvaluationException When the operand is non-numeric.
	 */
	private function absVal(array $object, mixed $args): int|float|null {
		$v = $this->evaluateNode(object: $object, expression: $this->firstOperand(args: $args));
		if ($v === null) {
			return null;
		}

		if (is_numeric($v) === false) {
			throw new EvaluationException('abs operand is not numeric.');
		}

		return abs($v + 0);
	}//end absVal()

	/**
	 * Round([value, precision?]): round to precision decimals (default 0).
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args `[value]` or `[value, precision]`.
	 *
	 * @return float|null The rounded value, or null when value is null.
	 *
	 * @throws EvaluationException When args is not a list, value is non-numeric, or precision is non-integer.
	 */
	private function roundVal(array $object, mixed $args): ?float {
		if (is_array($args) === false) {
			throw new EvaluationException('round requires [value, precision?].');
		}

		$v = $this->evaluateNode(object: $object, expression: ($args[0] ?? null));
		if ($v === null) {
			return null;
		}

		if (is_numeric($v) === false) {
			throw new EvaluationException('round value is not numeric.');
		}

		$precision = 0;
		if (array_key_exists(1, $args) === true) {
			$precisionArg = $this->evaluateNode(object: $object, expression: $args[1]);
			if (is_int($precisionArg) === false) {
				throw new EvaluationException('round precision must be an integer.');
			}

			$precision = $precisionArg;
		}

		return round(($v + 0), $precision);
	}//end roundVal()

	/**
	 * Year(date): the four-digit year of a date operand.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Single date operand (`[expr]` or bare).
	 *
	 * @return int|null The year, or null when the date is unparseable.
	 */
	private function yearOf(array $object, mixed $args): ?int {
		$date = $this->resolveDateOperand(object: $object, expression: $this->firstOperand(args: $args));
		if ($date === null) {
			return null;
		}

		return (int)$date->format('Y');
	}//end yearOf()

	/**
	 * MonthsElapsed([later, earlier]): signed whole calendar months between them.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args `[later, earlier]` date operands.
	 *
	 * @return int|null The signed whole-month difference, or null when either date is unparseable.
	 *
	 * @throws EvaluationException When fewer than two operands are supplied.
	 */
	private function monthsElapsed(array $object, mixed $args): ?int {
		if (is_array($args) === false || count($args) < 2) {
			throw new EvaluationException('monthsElapsed requires (later, earlier).');
		}

		$later = $this->resolveDateOperand(object: $object, expression: $args[0]);
		$earlier = $this->resolveDateOperand(object: $object, expression: $args[1]);
		if ($later === null || $earlier === null) {
			return null;
		}

		return $this->calendarDiff(from: $earlier, to: $later, unit: 'months');
	}//end monthsElapsed()

	/**
	 * Sha256(value): lowercase hex SHA-256 digest of the stringified operand.
	 *
	 * Pure and deterministic: the same operand always yields the same 64-char
	 * hex digest. A `null`-resolving operand returns `null` (rather than the
	 * hash of an empty string) so the operator is null-safe and never coins a
	 * fabricated digest for missing data. Mirrors the single-operand shape of
	 * `abs`/`round`/`year` — accepts both `[expr]` and a bare `expr`.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Single operand (`[expr]` or bare).
	 *
	 * @return string|null The 64-character hex SHA-256 digest, or null when the operand is null.
	 *
	 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-3
	 */
	private function sha256Of(array $object, mixed $args): ?string {
		$value = $this->evaluateNode(object: $object, expression: $this->firstOperand(args: $args));
		if ($value === null) {
			return null;
		}

		return hash('sha256', (string)$value);
	}//end sha256Of()
}//end class
