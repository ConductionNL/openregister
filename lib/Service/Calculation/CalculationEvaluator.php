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
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolverInterface;
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
class CalculationEvaluator implements CalculationEvaluatorInterface {
	use PayloadPathWalking;
	use PropNameReading;

	/**
	 * The operator vocabulary, described.
	 *
	 * This table is the published catalogue. The `category` picks the dispatch
	 * in {@see self::evaluateOperator()}, and the `match` blocks there dispatch
	 * on the same keys, so an operator is added to both in one file;
	 * `OperatorCatalogueTest` reads the match arms out of this file and fails
	 * when the two drift.
	 * `CalculationAnnotationValidator` reads its vocabulary from here, so a
	 * schema can never be refused for an operator the catalogue advertises.
	 *
	 * `arity` is a human-readable shape: a count, a range, `N+` for variadic,
	 * `0` for none, and `dict` for the operators that take a named-key object.
	 *
	 * @var array<string, array{category: string, arity: string, operands: array<int, string>, result: string, description: string}>
	 */
	public const OPERATORS = [
		'prop' => [
			'category' => 'reference',
			'arity' => '1',
			'operands' => ['string'],
			'result' => 'any',
			'description' => 'Reads a property of the object. Accepts a dotted path and the @self, @ref and @aggregate prefixes.',
		],
		'lit' => [
			'category' => 'reference',
			'arity' => '1',
			'operands' => ['any'],
			'result' => 'any',
			'description' => 'A literal value. Placeholders such as $now and $currentUser are resolved.',
		],
		'concat' => [
			'category' => 'string',
			'arity' => '1+',
			'operands' => ['any'],
			'result' => 'string',
			'description' => 'Joins its operands into one string.',
		],
		'if' => [
			'category' => 'logic',
			'arity' => '3',
			'operands' => ['boolean', 'any', 'any'],
			'result' => 'any',
			'description' => 'Returns the second operand when the first is true, otherwise the third.',
		],
		'not' => [
			'category' => 'logic',
			'arity' => '1',
			'operands' => ['boolean'],
			'result' => 'boolean',
			'description' => 'Negates its operand.',
		],
		'and' => [
			'category' => 'logic',
			'arity' => '1+',
			'operands' => ['boolean'],
			'result' => 'boolean',
			'description' => 'True when every operand is true.',
		],
		'or' => [
			'category' => 'logic',
			'arity' => '1+',
			'operands' => ['boolean'],
			'result' => 'boolean',
			'description' => 'True when at least one operand is true.',
		],
		'+' => [
			'category' => 'arithmetic',
			'arity' => '1+',
			'operands' => ['number'],
			'result' => 'number',
			'description' => 'Adds its operands.',
		],
		'-' => [
			'category' => 'arithmetic',
			'arity' => '1+',
			'operands' => ['number'],
			'result' => 'number',
			'description' => 'Subtracts the later operands from the first, or negates a single operand.',
		],
		'*' => [
			'category' => 'arithmetic',
			'arity' => '1+',
			'operands' => ['number'],
			'result' => 'number',
			'description' => 'Multiplies its operands.',
		],
		'/' => [
			'category' => 'arithmetic',
			'arity' => '2',
			'operands' => ['number', 'number'],
			'result' => 'number',
			'description' => 'Divides the first operand by the second. A zero divisor is refused.',
		],
		'%' => [
			'category' => 'arithmetic',
			'arity' => '2',
			'operands' => ['number', 'number'],
			'result' => 'number',
			'description' => 'The remainder after dividing the first operand by the second.',
		],
		'eq' => [
			'category' => 'comparison',
			'arity' => '2',
			'operands' => ['any', 'any'],
			'result' => 'boolean',
			'description' => 'True when both operands are equal.',
		],
		'ne' => [
			'category' => 'comparison',
			'arity' => '2',
			'operands' => ['any', 'any'],
			'result' => 'boolean',
			'description' => 'True when the operands differ.',
		],
		'lt' => [
			'category' => 'comparison',
			'arity' => '2',
			'operands' => ['number|date', 'number|date'],
			'result' => 'boolean',
			'description' => 'True when the first operand sorts before the second.',
		],
		'lte' => [
			'category' => 'comparison',
			'arity' => '2',
			'operands' => ['number|date', 'number|date'],
			'result' => 'boolean',
			'description' => 'True when the first operand sorts before the second or equals it.',
		],
		'gt' => [
			'category' => 'comparison',
			'arity' => '2',
			'operands' => ['number|date', 'number|date'],
			'result' => 'boolean',
			'description' => 'True when the first operand sorts after the second.',
		],
		'gte' => [
			'category' => 'comparison',
			'arity' => '2',
			'operands' => ['number|date', 'number|date'],
			'result' => 'boolean',
			'description' => 'True when the first operand sorts after the second or equals it.',
		],
		'now' => [
			'category' => 'date',
			'arity' => '0',
			'operands' => [],
			'result' => 'date',
			'description' => 'The current moment.',
		],
		'diffDays' => [
			'category' => 'date',
			'arity' => '2',
			'operands' => ['date', 'date'],
			'result' => 'integer',
			'description' => 'Whole days between the later and the earlier date.',
		],
		'formatDate' => [
			'category' => 'date',
			'arity' => '2',
			'operands' => ['date', 'string'],
			'result' => 'string',
			'description' => 'Renders a date with a PHP date format string.',
		],
		'dateDiff' => [
			'category' => 'date',
			'arity' => 'dict',
			'operands' => ['from', 'to', 'unit'],
			'result' => 'integer',
			'description' => 'Signed difference between two dates in years, months, weeks, days, hours, minutes or seconds.',
		],
		'dateAdd' => [
			'category' => 'date',
			'arity' => 'dict',
			'operands' => ['date', 'amount', 'unit', 'duration'],
			'result' => 'date',
			'description' => 'Shifts a date by an amount and a unit, or by an ISO-8601 duration such as P6W.',
		],
		'sequence' => [
			'category' => 'identifier',
			'arity' => 'dict',
			'operands' => ['scope', 'pad'],
			'result' => 'string',
			'description' => 'Reserves the next running number in a yearly, monthly or global scope, once, when the object is created.',
		],
		'max' => [
			'category' => 'arithmetic',
			'arity' => '1+',
			'operands' => ['number'],
			'result' => 'number',
			'description' => 'The largest of its operands, skipping nulls.',
		],
		'min' => [
			'category' => 'arithmetic',
			'arity' => '1+',
			'operands' => ['number'],
			'result' => 'number',
			'description' => 'The smallest of its operands, skipping nulls.',
		],
		'coalesce' => [
			'category' => 'logic',
			'arity' => '1+',
			'operands' => ['any'],
			'result' => 'any',
			'description' => 'The first operand that is not null.',
		],
		'abs' => [
			'category' => 'arithmetic',
			'arity' => '1',
			'operands' => ['number'],
			'result' => 'number',
			'description' => 'The absolute value of its operand.',
		],
		'round' => [
			'category' => 'arithmetic',
			'arity' => '1..2',
			'operands' => ['number', 'integer'],
			'result' => 'number',
			'description' => 'Rounds its operand, optionally to a number of decimals.',
		],
		'year' => [
			'category' => 'date',
			'arity' => '1',
			'operands' => ['date'],
			'result' => 'integer',
			'description' => 'The four-digit year of a date.',
		],
		'monthsElapsed' => [
			'category' => 'date',
			'arity' => '2',
			'operands' => ['date', 'date'],
			'result' => 'integer',
			'description' => 'Signed whole calendar months between the later and the earlier date.',
		],
		'graduated' => [
			'category' => 'arithmetic',
			'arity' => '2..3',
			'operands' => ['number', 'bands', 'number'],
			'result' => 'number',
			'description' => 'Amount a quantity accrues over rate bands ({upTo, rate}, the last may leave out upTo), capped by an optional third operand.',
		],
		'bandRate' => [
			'category' => 'arithmetic',
			'arity' => '2',
			'operands' => ['number', 'bands'],
			'result' => 'number',
			'description' => 'The rate of the band a unit falls in, counting units from 1. Null when no band covers it.',
		],
		'sha256' => [
			'category' => 'string',
			'arity' => '1',
			'operands' => ['any'],
			'result' => 'string',
			'description' => 'The lowercase hexadecimal SHA-256 digest of its operand.',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param PlaceholderResolverInterface $placeholders Shared placeholder resolver for literal-string interpolation.
	 * @param RateSchedule        $rates        The arithmetic behind graduated and bandRate.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PlaceholderResolverInterface $placeholders,
		private readonly RateSchedule $rates = new RateSchedule(),
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

		return $this->evaluateOperator(object: $object, expression: $expression);
	}//end evaluateNode()

	/**
	 * Evaluate a single-key operator node through its category's dispatch.
	 *
	 * @param array<string, mixed> $object     The object's stored data.
	 * @param array<mixed, mixed>  $expression The single-key node.
	 *
	 * @return mixed The computed value.
	 *
	 * @throws EvaluationException When the operator is unknown or reads a value source.
	 */
	private function evaluateOperator(array $object, array $expression): mixed {
		$op = (string)array_key_first($expression);
		$this->refuseValueSource(op: $op, args: $expression[$op]);

		return match (self::OPERATORS[$op]['category'] ?? null) {
			'reference', 'string' => $this->evaluateValueOperator(object: $object, expression: $expression),
			'logic', 'comparison' => $this->evaluateLogicOperator(object: $object, expression: $expression),
			'arithmetic' => $this->evaluateArithmeticOperator(object: $object, expression: $expression),
			'date', 'identifier' => $this->evaluateDateOperator(object: $object, expression: $expression),
			default => throw new EvaluationException(sprintf('Unknown operator "%s".', $op)),
		};
	}//end evaluateOperator()

	/**
	 * A calculation's result is stored and returned, so it never reads a value
	 * source: those may be secrets (expression-value-sources D-4). `source` is
	 * not an operator, so it stays out of the dispatch and the catalogue.
	 *
	 * @param string $op   The node's key.
	 * @param mixed  $args The node's value.
	 *
	 * @return void
	 *
	 * @throws EvaluationException When the node reads a value source.
	 */
	private function refuseValueSource(string $op, mixed $args): void {
		if ($op === 'source') {
			throw new EvaluationException(
				sprintf('A calculation cannot read the value source %s: its result is stored.', (string)json_encode($args))
			);
		}
	}//end refuseValueSource()

	/**
	 * Dispatch of the reference and string operators.
	 *
	 * @param array<string, mixed> $object     The object's stored data.
	 * @param array<mixed, mixed>  $expression The single-key node.
	 *
	 * @return mixed The computed value.
	 */
	private function evaluateValueOperator(array $object, array $expression): mixed {
		$op = (string)array_key_first($expression);
		$args = $expression[$op];

		return match ($op) {
			'prop' => $this->propValue(object: $object, args: $args),
			'lit' => $this->placeholders->resolve($args),
			'concat' => $this->concat(object: $object, args: $args),
			'sha256' => $this->sha256Of(object: $object, args: $args),
			default => throw new EvaluationException(sprintf('Unknown operator "%s".', $op)),
		};//end match
	}//end evaluateValueOperator()

	/**
	 * Dispatch of the logic and comparison operators.
	 *
	 * @param array<string, mixed> $object     The object's stored data.
	 * @param array<mixed, mixed>  $expression The single-key node.
	 *
	 * @return mixed The computed value.
	 */
	private function evaluateLogicOperator(array $object, array $expression): mixed {
		$op = (string)array_key_first($expression);
		$args = $expression[$op];

		return match ($op) {
			'if' => $this->ifExpr(object: $object, args: $args),
			'not' => !$this->boolEval(object: $object, expr: $args[0] ?? null),
			'and' => $this->allTrue(object: $object, args: $args),
			'or' => $this->anyTrue(object: $object, args: $args),
			'coalesce' => $this->coalesce(object: $object, args: $args),
			'eq', 'ne', 'lt', 'lte', 'gt', 'gte' => $this->compare(object: $object, expression: $expression),
			default => throw new EvaluationException(sprintf('Unknown operator "%s".', $op)),
		};//end match
	}//end evaluateLogicOperator()

	/**
	 * Dispatch of the arithmetic operators; the numeric functions continue in
	 * {@see self::evaluateNumericFunction()}.
	 *
	 * @param array<string, mixed> $object     The object's stored data.
	 * @param array<mixed, mixed>  $expression The single-key node.
	 *
	 * @return int|float|null The computed value.
	 */
	private function evaluateArithmeticOperator(array $object, array $expression): int|float|null {
		$op = (string)array_key_first($expression);
		$args = $expression[$op];

		return match ($op) {
			'+' => array_sum($this->numericOperands(object: $object, args: $args)),
			'-' => $this->subOrNeg(object: $object, args: $args),
			'*' => array_product($this->numericOperands(object: $object, args: $args)),
			'/' => $this->divide(object: $object, args: $args),
			'%' => $this->modulo(object: $object, args: $args),
			default => $this->evaluateNumericFunction(object: $object, expression: $expression),
		};//end match
	}//end evaluateArithmeticOperator()

	/**
	 * Dispatch of the numeric functions.
	 *
	 * @param array<string, mixed> $object     The object's stored data.
	 * @param array<mixed, mixed>  $expression The single-key node.
	 *
	 * @return int|float|null The computed value.
	 */
	private function evaluateNumericFunction(array $object, array $expression): int|float|null {
		$op = (string)array_key_first($expression);
		$args = $expression[$op];

		return match ($op) {
			'max' => $this->maxOf(object: $object, args: $args),
			'min' => $this->minOf(object: $object, args: $args),
			'abs' => $this->absVal(object: $object, args: $args),
			'round' => $this->roundVal(object: $object, args: $args),
			'graduated' => $this->graduated(object: $object, args: $args),
			'bandRate' => $this->bandRate(object: $object, args: $args),
			default => throw new EvaluationException(sprintf('Unknown operator "%s".', $op)),
		};//end match
	}//end evaluateNumericFunction()

	/**
	 * Dispatch of the date and identifier operators.
	 *
	 * @param array<string, mixed> $object     The object's stored data.
	 * @param array<mixed, mixed>  $expression The single-key node.
	 *
	 * @return mixed The computed value.
	 */
	private function evaluateDateOperator(array $object, array $expression): mixed {
		$op = (string)array_key_first($expression);
		$args = $expression[$op];

		return match ($op) {
			'now' => $this->now(),
			'diffDays' => $this->diffDays(object: $object, args: $args),
			'formatDate' => $this->formatDate(object: $object, args: $args),
			'dateDiff' => $this->dateDiff(object: $object, args: $args),
			'dateAdd' => $this->dateAdd(object: $object, args: $args),
			'year' => $this->yearOf(object: $object, args: $args),
			'monthsElapsed' => $this->monthsElapsed(object: $object, args: $args),
			'sequence' => $this->sequence(object: $object, args: $args),
			default => throw new EvaluationException(sprintf('Unknown operator "%s".', $op)),
		};//end match
	}//end evaluateDateOperator()

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
		$name = $this->propName(args: $args);
		if ($name === '') {
			throw new EvaluationException('prop requires a non-empty field name.');
		}

		// Support dotted paths: `@self.created`, `parent.subfield`, etc.
		// The CalculationOnSaveListener injects `@self` system metadata so
		// calculations can reference `@self.created`, `@self.updated`, etc.
		if (strpos($name, '.') === false) {
			return ($object[$name] ?? null);
		}

		return $this->walkPath(payload: $object, parts: explode('.', $name));
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
	 * AND over a list of expressions, stopping at the first false one.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Array of sub-expressions.
	 *
	 * @return bool True when every operand is true, or args is not a list.
	 */
	private function allTrue(array $object, mixed $args): bool {
		if (is_array($args) === false) {
			return true;
		}

		foreach ($args as $a) {
			if ($this->boolEval(object: $object, expr: $a) === false) {
				return false;
			}
		}

		return true;
	}//end allTrue()

	/**
	 * OR over a list of expressions, stopping at the first true one.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Array of sub-expressions.
	 *
	 * @return bool True when an operand is true.
	 */
	private function anyTrue(array $object, mixed $args): bool {
		if (is_array($args) === false) {
			return false;
		}

		foreach ($args as $a) {
			if ($this->boolEval(object: $object, expr: $a) === true) {
				return true;
			}
		}

		return false;
	}//end anyTrue()

	/**
	 * Evaluate the operands of `+` or `*` to numbers.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Array of operand sub-expressions.
	 *
	 * @return array<int, int|float> The operands' numeric values.
	 *
	 * @throws EvaluationException When args is not an array or an operand is non-numeric.
	 */
	private function numericOperands(array $object, mixed $args): array {
		if (is_array($args) === false) {
			throw new EvaluationException('Arithmetic requires an array of operands.');
		}

		$values = [];
		foreach ($args as $a) {
			$values[] = $this->numberOf(
				value: $this->evaluateNode(object: $object, expression: $a),
				message: 'Arithmetic operand is not numeric.'
			);
		}

		return $values;
	}//end numericOperands()

	/**
	 * A numeric value as a number.
	 *
	 * @param mixed  $value   The evaluated operand.
	 * @param string $message The refusal when it is not numeric.
	 *
	 * @return int|float The number.
	 *
	 * @throws EvaluationException When the value is not numeric.
	 */
	private function numberOf(mixed $value, string $message): int|float {
		if (is_numeric($value) === false) {
			throw new EvaluationException($message);
		}

		return $value + 0;
	}//end numberOf()

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

		$first = $this->numberOf(
			value: $this->evaluateNode(object: $object, expression: $args[0]),
			message: '- first operand not numeric.'
		);
		if (count($args) === 1) {
			return -$first;
		}

		return $this->subtractRest(object: $object, operands: ['first' => $first, 'args' => $args]);
	}//end subOrNeg()

	/**
	 * Subtract the operands after the first from its value, left to right.
	 *
	 * @param array<string, mixed>                         $object   The object's stored data.
	 * @param array{first: int|float, args: array<mixed>} $operands The first value and every sub-expression.
	 *
	 * @return int|float The result.
	 *
	 * @throws EvaluationException When an operand is non-numeric.
	 */
	private function subtractRest(array $object, array $operands): int|float {
		$acc = $operands['first'];
		$args = $operands['args'];
		$argCount = count($args);
		for ($i = 1; $i < $argCount; $i++) {
			$acc -= $this->numberOf(
				value: $this->evaluateNode(object: $object, expression: $args[$i]),
				message: '- operand not numeric.'
			);
		}

		return $acc;
	}//end subtractRest()

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
		$this->requireTwoOperands(args: $args, message: '/ requires two operands.');
		$a = $this->evaluateNode(object: $object, expression: $args[0]);
		$b = $this->evaluateNode(object: $object, expression: $args[1]);
		if ($this->isValidDivision(dividend: $a, divisor: $b) === false) {
			throw new EvaluationException('/ requires non-zero numeric operands.');
		}

		return ((float)$a) / ((float)$b);
	}//end divide()

	/**
	 * Refuse anything but an array of at least two operands.
	 *
	 * @param mixed  $args    The operator's arguments.
	 * @param string $message The refusal.
	 *
	 * @return void
	 *
	 * @throws EvaluationException When there are fewer than two operands.
	 */
	private function requireTwoOperands(mixed $args, string $message): void {
		if (is_array($args) === false || count($args) < 2) {
			throw new EvaluationException($message);
		}
	}//end requireTwoOperands()

	/**
	 * Whether two evaluated operands can be divided.
	 *
	 * @param mixed $dividend The first operand.
	 * @param mixed $divisor  The second operand.
	 *
	 * @return bool True when both are numeric and the divisor is not zero.
	 */
	private function isValidDivision(mixed $dividend, mixed $divisor): bool {
		return is_numeric($dividend) === true && is_numeric($divisor) === true && (float)$divisor !== 0.0;
	}//end isValidDivision()

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
		$this->requireTwoOperands(args: $args, message: '% requires two operands.');
		$a = $this->evaluateNode(object: $object, expression: $args[0]);
		$b = $this->evaluateNode(object: $object, expression: $args[1]);
		if ($this->isValidDivision(dividend: $a, divisor: $b) === false) {
			throw new EvaluationException('% requires non-zero numeric operands.');
		}

		return fmod((float)$a, (float)$b);
	}//end modulo()

	/**
	 * Compare two operands; the node's key is the comparison.
	 *
	 * @param array<string, mixed> $object     The object's stored data.
	 * @param array<mixed, mixed>  $expression The single-key node: one of eq, ne, lt, lte, gt, gte.
	 *
	 * @return bool The comparison result.
	 *
	 * @throws EvaluationException When fewer than two operands.
	 */
	private function compare(array $object, array $expression): bool {
		$relation = (string)array_key_first($expression);
		$args = $expression[$relation];
		$this->requireTwoOperands(args: $args, message: sprintf('%s requires two operands.', $relation));

		$a = $this->normaliseForCompare(v: $this->evaluateNode(object: $object, expression: $args[0]));
		$b = $this->normaliseForCompare(v: $this->evaluateNode(object: $object, expression: $args[1]));
		if ($relation === 'eq') {
			return $a == $b;
		}

		if ($relation === 'ne') {
			return $a != $b;
		}

		return $a !== null && $b !== null && $this->isOrdered(relation: $relation, pair: [$a, $b]);
	}//end compare()

	/**
	 * Whether two non-null values stand in an ordering relation.
	 *
	 * @param string            $relation One of lt, lte, gt, gte.
	 * @param array{mixed, mixed} $pair     The left and right value.
	 *
	 * @return bool The comparison result.
	 */
	private function isOrdered(string $relation, array $pair): bool {
		[$a, $b] = $pair;

		return match ($relation) {
			'lt' => $a < $b,
			'lte' => $a <= $b,
			'gt' => $a > $b,
			'gte' => $a >= $b,
			default => false,
		};
	}//end isOrdered()

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
			return $this->dateFromString(value: $v)?->getTimestamp() ?? $v;
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
		$this->requireTwoOperands(args: $args, message: 'diffDays requires (later, earlier).');
		$dates = $this->bothDates(
			dates: [
				$this->toDateOrNull(v: $this->evaluateNode(object: $object, expression: $args[0])),
				$this->toDateOrNull(v: $this->evaluateNode(object: $object, expression: $args[1])),
			]
		);
		if ($dates === null) {
			return null;
		}

		return (int)floor(($dates[0]->getTimestamp() - $dates[1]->getTimestamp()) / 86400);
	}//end diffDays()

	/**
	 * Both dates of a pair, or null when one of them is missing.
	 *
	 * @param array{DateTimeImmutable|null, DateTimeImmutable|null} $dates The pair.
	 *
	 * @return array{DateTimeImmutable, DateTimeImmutable}|null
	 */
	private function bothDates(array $dates): ?array {
		if ($dates[0] === null || $dates[1] === null) {
			return null;
		}

		return $dates;
	}//end bothDates()

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
		$this->requireTwoOperands(args: $args, message: 'formatDate requires (date, fmt).');
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
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-1
	 */
	private function dateDiff(array $object, mixed $args): ?int {
		$this->requireDateDiffKeys(args: $args);

		$unit = $this->validateDateDiffUnit(object: $object, unitExpr: $args['unit']);
		$dates = $this->resolvedDates(object: $object, expressions: [$args['from'], $args['to']]);
		if ($dates === null) {
			return null;
		}

		return $this->applyDateDiffUnit(dates: $dates, unit: $unit);
	}//end dateDiff()

	/**
	 * Refuse dateDiff arguments that are not a dict with from, to and unit.
	 *
	 * @param mixed $args The operator's arguments.
	 *
	 * @return void
	 *
	 * @throws EvaluationException When a key is missing.
	 */
	private function requireDateDiffKeys(mixed $args): void {
		if (is_array($args) === false) {
			throw new EvaluationException('dateDiff requires an object argument with keys: from, to, unit.');
		}

		if (array_diff(['from', 'to', 'unit'], array_keys($args)) !== []) {
			throw new EvaluationException('dateDiff requires keys: from, to, unit.');
		}
	}//end requireDateDiffKeys()

	/**
	 * Two date operands, resolved; null when one of them is not a date.
	 *
	 * @param array<string, mixed> $object      The object's stored data.
	 * @param array{mixed, mixed}  $expressions The two sub-expressions.
	 *
	 * @return array{DateTimeImmutable, DateTimeImmutable}|null
	 */
	private function resolvedDates(array $object, array $expressions): ?array {
		return $this->bothDates(
			dates: [
				$this->resolveDateOperand(object: $object, expression: $expressions[0]),
				$this->resolveDateOperand(object: $object, expression: $expressions[1]),
			]
		);
	}//end resolvedDates()

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
	 * @param array{DateTimeImmutable, DateTimeImmutable} $dates The start and end date.
	 * @param string $unit One of years, months, weeks, days, hours, minutes, seconds.
	 *
	 * @return int The signed integer difference.
	 *
	 * @throws EvaluationException When the unit is unhandled (should not occur after validation).
	 */
	private function applyDateDiffUnit(array $dates, string $unit): int {
		if (in_array($unit, ['years', 'months'], true) === true) {
			return $this->calendarDiff(dates: $dates)[$unit];
		}

		$deltaSecs = $dates[1]->getTimestamp() - $dates[0]->getTimestamp();

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
	 * Compute a calendar-accurate signed difference in years and in months.
	 *
	 * Uses DateInterval::diff() to respect leap years and variable month lengths.
	 *
	 * @param array{DateTimeImmutable, DateTimeImmutable} $dates The start and end date.
	 *
	 * @return array{years: int, months: int} The signed whole years and whole months.
	 */
	private function calendarDiff(array $dates): array {
		$interval = $dates[0]->diff($dates[1]);
		$sign = 1;
		if ($interval->invert === 1) {
			$sign = -1;
		}

		return ['years' => $sign * $interval->y, 'months' => $sign * ($interval->y * 12 + $interval->m)];
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
	 */
	private function dateAdd(array $object, mixed $args): ?string {
		$this->requireDateAddShape(args: $args);

		$raw = $this->evaluateNode(object: $object, expression: $args['date']);
		$date = $this->toDateOrNull(v: $raw);
		if ($date === null) {
			return null;
		}

		$interval = $this->dateAddInterval(object: $object, args: $args);
		if ($interval === null) {
			return null;
		}

		return $date->add($interval)->format($this->dateAddFormat(raw: $raw));
	}//end dateAdd()

	/**
	 * Refuse dateAdd arguments without a date, or without (amount + unit) or duration.
	 *
	 * @param mixed $args The operator's arguments.
	 *
	 * @return void
	 *
	 * @throws EvaluationException When the dict is missing the required keys.
	 */
	private function requireDateAddShape(mixed $args): void {
		if (is_array($args) === false || array_key_exists('date', $args) === false) {
			throw new EvaluationException('dateAdd requires a "date" key plus either ("amount" + "unit") or "duration".');
		}

		$hasAmountUnit = array_diff(['amount', 'unit'], array_keys($args)) === [];
		if (array_key_exists('duration', $args) === false && $hasAmountUnit === false) {
			throw new EvaluationException('dateAdd requires either ("amount" + "unit") or "duration".');
		}
	}//end requireDateAddShape()

	/**
	 * The interval dateAdd adds: its `duration`, else its `amount` and `unit`.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param array<string, mixed> $args   The operator's arguments.
	 *
	 * @return DateInterval|null The interval, or null when it is unusable.
	 */
	private function dateAddInterval(array $object, array $args): ?DateInterval {
		if (array_key_exists('duration', $args) === true) {
			return $this->intervalFromDuration(value: $this->evaluateNode(object: $object, expression: $args['duration']));
		}

		return $this->intervalFromAmountUnit(
			amount: $this->evaluateNode(object: $object, expression: $args['amount']),
			unit: (string)$this->evaluateNode(object: $object, expression: $args['unit'])
		);
	}//end dateAddInterval()

	/**
	 * The format of a dateAdd result: with a time when the raw date string has one.
	 *
	 * @param mixed $raw The evaluated `date` operand.
	 *
	 * @return string The format.
	 */
	private function dateAddFormat(mixed $raw): string {
		if (is_string($raw) === true && preg_match('/\d{1,2}:\d{2}/', $raw) === 1) {
			return 'Y-m-d\TH:i:sP';
		}

		return 'Y-m-d';
	}//end dateAddFormat()

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
	 */
	private function intervalFromAmountUnit(mixed $amount, string $unit): ?DateInterval {
		if (is_numeric($amount) === false) {
			return null;
		}

		$n = (int)$amount;

		return $this->intervalFromDuration(
			value: match ($unit) {
				'days' => 'P' . $n . 'D',
				'weeks' => 'P' . ($n * 7) . 'D',
				'months' => 'P' . $n . 'M',
				'years' => 'P' . $n . 'Y',
				default => null,
			}
		);
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

		$scopeKey = $this->sequenceScopeKey(scope: (string)$this->evaluateNode(object: $object, expression: $args['scope']));

		// Read path / update path: no context installed → never consume a number.
		if ($this->sequenceContext === null) {
			return null;
		}

		$pad = $this->sequencePad(object: $object, args: $args);
		$reserved = $this->sequenceContext->reserveNext(scopeKey: $scopeKey);

		return str_pad((string)$reserved, $pad, '0', STR_PAD_LEFT);
	}//end sequence()

	/**
	 * The key a scope reserves under, from the current server time.
	 *
	 * @param string $scope yearly, monthly or global.
	 *
	 * @return string The scope key.
	 *
	 * @throws EvaluationException When the scope is invalid.
	 */
	private function sequenceScopeKey(string $scope): string {
		$now = new DateTimeImmutable('now');

		return match ($scope) {
			'yearly' => $now->format('Y'),
			'monthly' => $now->format('Y-m'),
			'global' => '',
			default => throw new EvaluationException(
				sprintf('sequence scope "%s" is invalid. Supported: yearly, monthly, global.', $scope)
			),
		};
	}//end sequenceScopeKey()

	/**
	 * The zero-padding width of a sequence: its numeric `pad`, default 4.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param array<string, mixed> $args   The sequence's arguments.
	 *
	 * @return int The width.
	 */
	private function sequencePad(array $object, array $args): int {
		if (array_key_exists('pad', $args) === false) {
			return 4;
		}

		$pad = $this->evaluateNode(object: $object, expression: $args['pad']);
		if (is_numeric($pad) === true) {
			return (int)$pad;
		}

		return 4;
	}//end sequencePad()

	/**
	 * Whether an expression tree contains a `sequence` node.
	 *
	 * A `sequence` reserves its running number exactly once, on create, through
	 * the per-save SequenceContext. Off that path {@see self::sequence()}
	 * deliberately returns null — which is correct for the evaluator but
	 * catastrophic for a caller that PERSISTS the result: `concat` renders the
	 * null as an empty string, so a declared identifier such as
	 * `concat(year(startDate), "-", sequence(...))` recomputes to "2026-" and
	 * overwrites the number assigned at create.
	 *
	 * Every caller that materialises a calculation must therefore skip a
	 * sequence-bearing expression while no context is active, leaving the
	 * stored value untouched. See openregister#3075.
	 *
	 * @param mixed $expression Expression AST (any node).
	 *
	 * @return bool True when a `sequence` operator appears anywhere in the tree.
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-save-time-evaluation
	 */
	public function expressionUsesSequence(mixed $expression): bool {
		if (is_array($expression) === false) {
			return false;
		}

		foreach ($expression as $key => $value) {
			if ($key === 'sequence') {
				return true;
			}

			if ($this->expressionUsesSequence(expression: $value) === true) {
				return true;
			}
		}

		return false;
	}//end expressionUsesSequence()

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
		if ($v instanceof DateTimeInterface) {
			return $this->immutableDate(date: $v);
		}

		if (is_string($v) === true && $v !== '') {
			return $this->dateFromString(value: $v);
		}

		return null;
	}//end toDateOrNull()

	/**
	 * A date as an immutable one.
	 *
	 * @param DateTimeInterface $date The date.
	 *
	 * @return DateTimeImmutable
	 */
	private function immutableDate(DateTimeInterface $date): DateTimeImmutable {
		if ($date instanceof DateTimeImmutable) {
			return $date;
		}

		return DateTimeImmutable::createFromInterface($date);
	}//end immutableDate()

	/**
	 * Parse a date string.
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null The date, or null when it does not parse or names only a timezone.
	 */
	private function dateFromString(string $value): ?DateTimeImmutable {
		if ($this->namesOnlyATimezone(value: $value) === true) {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end dateFromString()

	/**
	 * Whether a string holds a timezone and no date or time.
	 *
	 * PHP reads such a string, like any single letter (a military timezone), as
	 * the current moment in that zone.
	 *
	 * @param string $value The date string.
	 *
	 * @return bool
	 */
	private function namesOnlyATimezone(string $value): bool {
		$parsed = date_parse($value);

		return ($parsed['zone_type'] ?? 0) !== 0
			&& isset($parsed['relative']) === false
			&& array_filter([$parsed['year'], $parsed['month'], $parsed['day'], $parsed['hour']], 'is_int') === [];
	}//end namesOnlyATimezone()

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
	 * Max(...) over N numeric operands; null operands are skipped.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Operand list.
	 *
	 * @return int|float|null The largest value, or null when every operand is null.
	 *
	 * @throws EvaluationException When args is not a list or an operand is non-numeric.
	 */
	private function maxOf(array $object, mixed $args): int|float|null {
		$result = null;
		foreach ($this->presentNumbers(object: $object, args: $args) as $num) {
			if ($result === null || $num > $result) {
				$result = $num;
			}
		}

		return $result;
	}//end maxOf()

	/**
	 * Min(...) over N numeric operands; null operands are skipped.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Operand list.
	 *
	 * @return int|float|null The smallest value, or null when every operand is null.
	 *
	 * @throws EvaluationException When args is not a list or an operand is non-numeric.
	 */
	private function minOf(array $object, mixed $args): int|float|null {
		$result = null;
		foreach ($this->presentNumbers(object: $object, args: $args) as $num) {
			if ($result === null || $num < $result) {
				$result = $num;
			}
		}

		return $result;
	}//end minOf()

	/**
	 * The numeric values of max/min operands, without the null ones.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args Operand list.
	 *
	 * @return array<int, int|float> The numbers.
	 *
	 * @throws EvaluationException When args is not a list or an operand is non-numeric.
	 */
	private function presentNumbers(array $object, mixed $args): array {
		if (is_array($args) === false) {
			throw new EvaluationException('max/min requires an array of operands.');
		}

		$numbers = [];
		foreach ($args as $a) {
			$v = $this->evaluateNode(object: $object, expression: $a);
			if ($v !== null) {
				$numbers[] = $this->numberOf(value: $v, message: 'max/min operand is not numeric.');
			}
		}

		return $numbers;
	}//end presentNumbers()

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

		return abs($this->numberOf(value: $v, message: 'abs operand is not numeric.'));
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

		$number = $this->numberOf(value: $v, message: 'round value is not numeric.');

		return round($number, $this->roundPrecision(object: $object, args: $args));
	}//end roundVal()

	/**
	 * The decimals of a round: its second operand, default 0.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param array<int, mixed>    $args   `[value]` or `[value, precision]`.
	 *
	 * @return int The precision.
	 *
	 * @throws EvaluationException When the precision is not an integer.
	 */
	private function roundPrecision(array $object, array $args): int {
		if (array_key_exists(1, $args) === false) {
			return 0;
		}

		$precision = $this->evaluateNode(object: $object, expression: $args[1]);
		if (is_int($precision) === false) {
			throw new EvaluationException('round precision must be an integer.');
		}

		return $precision;
	}//end roundPrecision()

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
		$this->requireTwoOperands(args: $args, message: 'monthsElapsed requires (later, earlier).');
		$dates = $this->resolvedDates(object: $object, expressions: [$args[1], $args[0]]);
		if ($dates === null) {
			return null;
		}

		return $this->calendarDiff(dates: $dates)['months'];
	}//end monthsElapsed()

	/**
	 * Graduated([quantity, bands, cap?]): the amount accrued over a rate schedule.
	 *
	 * Null-safe like the other arithmetic operators: a quantity or a schedule
	 * that resolves to null yields null, so a record that has not started
	 * accruing yet has no amount rather than an invented zero.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args `[quantity, bands]` or `[quantity, bands, cap]`.
	 *
	 * @return int|float|null The amount, or null when an operand is null.
	 *
	 * @throws EvaluationException When an operand is missing or not numeric, or the schedule is malformed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function graduated(array $object, mixed $args): int|float|null {
		$this->requireRateOperands(args: $args);

		$cap = null;
		if (isset($args[2]) === true) {
			$cap = $this->evaluateNode(object: $object, expression: $args[2]);
		}

		return $this->rates->evaluate(
			quantity: $this->evaluateNode(object: $object, expression: $args[0]),
			bands: $this->evaluateNode(object: $object, expression: $args[1]),
			cap: $cap,
			cumulative: true
		);
	}//end graduated()

	/**
	 * BandRate([unit, bands]): the rate of the band a value falls in; null-safe like graduated.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param mixed $args `[unit, bands]`.
	 *
	 * @return int|float|null The rate, or null when an operand is null.
	 *
	 * @throws EvaluationException When an operand is missing or not numeric, or the schedule is malformed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function bandRate(array $object, mixed $args): int|float|null {
		$this->requireRateOperands(args: $args);

		return $this->rates->evaluate(
			quantity: $this->evaluateNode(object: $object, expression: $args[0]),
			bands: $this->evaluateNode(object: $object, expression: $args[1]),
			cap: null,
			cumulative: false
		);
	}//end bandRate()

	/**
	 * Refuse rate-schedule arguments that are not a list of at least two operands.
	 *
	 * @param mixed $args The operator's arguments.
	 *
	 * @return void
	 *
	 * @throws EvaluationException When the shape is wrong.
	 */
	private function requireRateOperands(mixed $args): void {
		if (is_array($args) === false || array_is_list($args) === false || count($args) < 2) {
			throw new EvaluationException('graduated/bandRate requires (quantity, bands).');
		}
	}//end requireRateOperands()

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
	/**
	 * The properties an expression reads, derived from the expression itself.
	 *
	 * A `dependsOn` list written beside an expression is a second source of
	 * truth, and it fails silently: a dependency the author forgot is a value
	 * that never refreshes. The AST is a tree, so the answer is derivable, and
	 * this is the one place that derives it. The annotation validator's cycle
	 * check runs on the list this produces.
	 *
	 * Names are returned exactly as the expression writes them, including the
	 * `@self.`, `@ref.` and `@aggregate.` prefixes, in first-seen order with
	 * duplicates removed.
	 *
	 * @param mixed $expression Expression AST (any node).
	 *
	 * @return array<int, string> The property names the expression reads.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function referencedProperties(mixed $expression): array {
		return array_values(array_unique($this->referencesIn(expression: $expression)));
	}//end referencedProperties()

	/**
	 * Every `prop` reference beneath a node, in reading order.
	 *
	 * @param mixed $expression Expression AST (any node).
	 *
	 * @return array<int, string> The property names, duplicates included.
	 */
	private function referencesIn(mixed $expression): array {
		if (is_array($expression) === false) {
			return [];
		}

		$names = [];
		foreach ($expression as $key => $value) {
			if ($key === 'prop') {
				$names = array_merge($names, $this->referenceName(value: $value));
				continue;
			}

			$names = array_merge($names, $this->referencesIn(expression: $value));
		}

		return $names;
	}//end referencesIn()

	/**
	 * The name a `prop` value references, as a list of none or one.
	 *
	 * @param mixed $value The `prop` node's value.
	 *
	 * @return array<int, string> The name, when it is a non-empty string.
	 */
	private function referenceName(mixed $value): array {
		$name = $value;
		if (is_array($value) === true) {
			$name = ($value[0] ?? null);
		}

		if (is_string($name) === true && $name !== '') {
			return [$name];
		}

		return [];
	}//end referenceName()

}//end class
