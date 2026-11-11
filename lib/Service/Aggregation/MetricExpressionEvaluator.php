<?php

/**
 * OpenRegister MetricExpressionEvaluator
 *
 * Evaluates a DERIVED metric: a small arithmetic expression over the aliases of
 * the other metrics computed in the same aggregation.
 *
 * ## Why this exists
 *
 * A trial balance needs `vatBalance = totalVATPaid - totalVATCollected`, and a
 * revenue waterfall needs `remaining = transactionPriceAllocated -
 * cumulativeRecognised`. Both figures are already computed by the aggregation;
 * only the arithmetic between them was missing, so 41 declarations across the
 * shillinq registers carried an `expression` key the engine never read. They
 * did not fail — they simply produced nothing for that figure.
 *
 * Declaring a second aggregation to do the subtraction is not equivalent: it
 * scans the table again, and the two results can disagree if a row is written
 * between the calls. A derived metric reads the numbers this aggregation just
 * produced, so it cannot disagree with them.
 *
 * ## Why a parser and not eval()
 *
 * These expressions come from register descriptors, which are data. `eval()` on
 * data is arbitrary code execution, and no amount of "we control the registers"
 * survives the first app that imports a descriptor from somewhere else. This is
 * a recursive-descent parser over a closed grammar:
 *
 *     expr    := term (('+' | '-') term)*
 *     term    := factor (('*' | '/') factor)*
 *     factor  := NUMBER | IDENT | '(' expr ')' | ('min'|'max') '(' expr ',' expr ')'
 *              | '-' factor
 *
 * Anything outside it is refused by name. There is no variable assignment, no
 * function call beyond min/max, no property access and no string literal.
 *
 * ## What it refuses, and why refusing is the point
 *
 * - An IDENTIFIER THAT NAMES NO ALIAS throws. Resolving it to 0 would turn a
 *   typo into a plausible number — `a - typo` would silently return `a`, which
 *   is exactly the failure mode this whole effort removes.
 * - DIVISION BY ZERO yields null rather than INF or NAN. A JSON-encoded INF is
 *   not valid JSON, and NAN compares false against everything, so both travel
 *   further than they should before anyone notices.
 * - A NON-NUMERIC alias value throws, naming the alias.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use RuntimeException;

/**
 * Evaluates arithmetic over the aliases of already-computed metrics.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
class MetricExpressionEvaluator {

	/**
	 * The refusal of an identifier that names no alias: the name, then the aliases that exist.
	 *
	 * @var string
	 */
	private const UNKNOWN_ALIAS = 'Metric expression references "%s", which is not a metric in this aggregation. '
		.'Available: %s. A derived metric can only read figures computed BEFORE it, so '
		.'order the `metrics` list accordingly.';

	/**
	 * The token list for the expression currently being evaluated.
	 *
	 * @var array<int, array{type: string, value: string}>
	 */
	private array $tokens = [];

	/**
	 * Read position within {@see $tokens}.
	 *
	 * @var int
	 */
	private int $pos = 0;

	/**
	 * Number of tokens, cached so the parse loops do not call count() per turn.
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Alias => value for the metrics already computed in this aggregation.
	 *
	 * @var array<string, mixed>
	 */
	private array $scope = [];

	/**
	 * Turns the source into tokens. Split out so the lexer's character-level
	 * branching does not count against the parser's complexity budget — they
	 * are genuinely different jobs and phpmd was right to say so.
	 *
	 * @var MetricExpressionLexer
	 */
	private readonly MetricExpressionLexer $lexer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->lexer = new MetricExpressionLexer();
	}//end __construct()

	/**
	 * Evaluate one expression against the already-computed metric values.
	 *
	 * @param string               $expression The expression source.
	 * @param array<string, mixed> $scope      Alias => value of the metrics computed so far.
	 *
	 * @return float|null The result, or null when a division by zero made it undefined.
	 *
	 * @throws RuntimeException When the expression is malformed or names an unknown alias.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function evaluate(string $expression, array $scope): ?float {
		$this->tokens = $this->lexer->tokenise($expression);
		$this->pos    = 0;
		$this->count  = count($this->tokens);
		$this->scope  = $scope;

		if ($this->tokens === []) {
			throw new RuntimeException('Metric expression is empty.');
		}

		$value = $this->parseExpression();
		$this->refuseLeftover(expression: $expression);

		return $value;
	}//end evaluate()

	/**
	 * Refuse a token the grammar left unconsumed.
	 *
	 * @param string $expression The expression source, for the message.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a token is left after the expression.
	 */
	private function refuseLeftover(string $expression): void {
		if ($this->pos >= $this->count) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'Unexpected "%s" in metric expression "%s". The grammar is arithmetic over '
				.'metric aliases: + - * / parentheses, and min()/max() of two arguments.',
				$this->tokens[$this->pos]['value'],
				$expression
			)
		);
	}//end refuseLeftover()

	/**
	 * expr := term (('+' | '-') term)*
	 *
	 * @return float|null The value, or null once a division by zero poisoned it.
	 */
	private function parseExpression(): ?float {
		$value = $this->parseTerm();
		$op    = $this->takeOperator(first: '+', second: '-');

		while ($op !== null) {
			$value = $this->combine(op: $op, lhs: $value);
			$op    = $this->takeOperator(first: '+', second: '-');
		}

		return $value;
	}//end parseExpression()

	/**
	 * term := factor (('*' | '/') factor)*
	 *
	 * @return float|null The value, or null on a division by zero.
	 */
	private function parseTerm(): ?float {
		$value = $this->parseFactor();
		$op    = $this->takeOperator(first: '*', second: '/');

		while ($op !== null) {
			$value = $this->combine(op: $op, lhs: $value);
			$op    = $this->takeOperator(first: '*', second: '/');
		}

		return $value;
	}//end parseTerm()

	/**
	 * Consume the next token when it is one of two operators.
	 *
	 * @param string $first  One operator the production accepts.
	 * @param string $second The other operator the production accepts.
	 *
	 * @return string|null The consumed operator, or null when the next token is neither.
	 */
	private function takeOperator(string $first, string $second): ?string {
		if ($this->pos >= $this->count) {
			return null;
		}

		$type = $this->tokens[$this->pos]['type'];
		if ($type !== $first && $type !== $second) {
			return null;
		}

		$this->pos++;
		return $type;
	}//end takeOperator()

	/**
	 * Apply one binary operator to the running value and the operand that follows it.
	 *
	 * The right operand is parsed first, so a null left side still consumes its
	 * tokens. A null on either side poisons the result.
	 *
	 * @param string     $op  One of + - * /.
	 * @param float|null $lhs The left operand: the value computed so far.
	 *
	 * @return float|null The result, or null when an operand is null or the divisor is zero.
	 */
	private function combine(string $op, float|null $lhs): float|null {
		$rhs = $this->rightOperand(op: $op);
		if ($lhs === null || $rhs === null) {
			return null;
		}

		return $this->arithmetic(op: $op, operands: new MetricOperands(lhs: $lhs, rhs: $rhs));
	}//end combine()

	/**
	 * Parse the right operand of a binary operator: a term after + or -, a factor after * or /.
	 *
	 * @param string $op One of + - * /.
	 *
	 * @return float|null The operand, or null when it is undefined.
	 */
	private function rightOperand(string $op): ?float {
		if ($op === '+' || $op === '-') {
			return $this->parseTerm();
		}

		return $this->parseFactor();
	}//end rightOperand()

	/**
	 * Apply one binary operator to two known operands.
	 *
	 * @param string         $op       One of + - * /.
	 * @param MetricOperands $operands The left and right operand.
	 *
	 * @return float|null The result, or null when the divisor is zero.
	 */
	private function arithmetic(string $op, MetricOperands $operands): ?float {
		return match ($op) {
			'+' => ($operands->lhs + $operands->rhs),
			'-' => ($operands->lhs - $operands->rhs),
			'/' => $this->divide(dividend: $operands->lhs, divisor: $operands->rhs),
			default => ($operands->lhs * $operands->rhs),
		};
	}//end arithmetic()

	/**
	 * Divide, yielding null on a zero divisor.
	 *
	 * DIVISION BY ZERO YIELDS NULL, not INF and not NAN. json_encode()
	 * refuses INF outright, and NAN compares false against everything,
	 * so either one travels a long way before anybody notices.
	 *
	 * @param float $dividend The dividend.
	 * @param float $divisor  The divisor.
	 *
	 * @return float|null The quotient, or null when the divisor is zero.
	 */
	private function divide(float $dividend, float $divisor): ?float {
		if ($divisor === 0.0) {
			return null;
		}

		return ($dividend / $divisor);
	}//end divide()

	/**
	 * factor := NUMBER | IDENT | '(' expr ')' | ('min'|'max') '(' expr ',' expr ')' | '-' factor
	 *
	 * @return float|null The value.
	 *
	 * @throws RuntimeException On a malformed factor or an unknown alias.
	 */
	private function parseFactor(): ?float {
		if ($this->pos >= $this->count) {
			throw new RuntimeException('Metric expression ended where a value was expected.');
		}

		$token = $this->tokens[$this->pos];

		return match ($token['type']) {
			'-' => $this->parseNegation(),
			'number' => $this->parseNumber(value: $token['value']),
			'(' => $this->parseGroup(),
			'ident' => $this->parseIdentifier(name: $token['value']),
			default => throw new RuntimeException(
				sprintf('Unexpected "%s" where a value was expected in a metric expression.', $token['value'])
			),
		};
	}//end parseFactor()

	/**
	 * '-' factor
	 *
	 * @return float|null The negated value, or null when the operand is null.
	 */
	private function parseNegation(): ?float {
		$this->pos++;
		$inner = $this->parseFactor();
		if ($inner === null) {
			return null;
		}

		return (0 - $inner);
	}//end parseNegation()

	/**
	 * NUMBER
	 *
	 * @param string $value The literal.
	 *
	 * @return float The number.
	 */
	private function parseNumber(string $value): float {
		$this->pos++;
		return (float)$value;
	}//end parseNumber()

	/**
	 * '(' expr ')'
	 *
	 * @return float|null The value inside the parentheses.
	 */
	private function parseGroup(): ?float {
		$this->pos++;
		$inner = $this->parseExpression();
		$this->expect(type: ')');
		return $inner;
	}//end parseGroup()

	/**
	 * IDENT, or the min/max call an identifier starts.
	 *
	 * @param string $name The identifier.
	 *
	 * @return float|null The value.
	 */
	private function parseIdentifier(string $name): ?float {
		if ($name === 'min' || $name === 'max') {
			return $this->parseMinMax(name: $name);
		}

		$this->pos++;
		return $this->resolveAlias(name: $name);
	}//end parseIdentifier()

	/**
	 * ('min'|'max') '(' expr ',' expr ')'
	 *
	 * @param string $name Either `min` or `max`.
	 *
	 * @return float|null The chosen value, or null when either argument is null.
	 */
	private function parseMinMax(string $name): ?float {
		$this->pos++;
		$this->expect(type: '(');
		$left = $this->parseExpression();
		$this->expect(type: ',');
		$right = $this->parseExpression();
		$this->expect(type: ')');

		if ($left === null || $right === null) {
			return null;
		}

		if ($name === 'min') {
			return min($left, $right);
		}

		return max($left, $right);
	}//end parseMinMax()

	/**
	 * Resolve an identifier against the already-computed metric aliases.
	 *
	 * An unknown alias THROWS. Resolving it to 0 would turn a typo into a
	 * plausible number: `a - typo` would quietly return `a`.
	 *
	 * @param string $name The alias.
	 *
	 * @return float|null The alias value, or null when the alias itself is null.
	 *
	 * @throws RuntimeException When the alias is unknown or not numeric.
	 */
	private function resolveAlias(string $name): ?float {
		if (array_key_exists($name, $this->scope) === false) {
			throw $this->unknownAlias(name: $name);
		}

		$value = $this->scope[$name];

		if ($value === null) {
			return null;
		}

		return $this->numeric(name: $name, value: $value);
	}//end resolveAlias()

	/**
	 * An alias value as a number, or a refusal naming the alias.
	 *
	 * @param string $name  The alias.
	 * @param mixed  $value Its non-null value.
	 *
	 * @return float The value.
	 *
	 * @throws RuntimeException When the value is not an int or a float.
	 */
	private function numeric(string $name, mixed $value): float {
		if (is_int($value) === false && is_float($value) === false) {
			throw new RuntimeException(
				sprintf(
					'Metric expression references "%s", whose value is %s rather than a number.',
					$name,
					get_debug_type($value)
				)
			);
		}

		return (float)$value;
	}//end numeric()

	/**
	 * The refusal for an identifier that names no alias in scope.
	 *
	 * @param string $name The alias.
	 *
	 * @return RuntimeException The refusal, naming the aliases that do exist.
	 */
	private function unknownAlias(string $name): RuntimeException {
		$known = array_keys($this->scope);
		sort($known);
		$available = '(none)';
		if ($known !== []) {
			$available = implode(', ', $known);
		}

		return new RuntimeException(sprintf(self::UNKNOWN_ALIAS, $name, $available));
	}//end unknownAlias()

	/**
	 * Consume the expected token type or refuse.
	 *
	 * @param string $type The token type expected next.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the next token is not the expected one.
	 */
	private function expect(string $type): void {
		if ($this->pos >= $this->count || $this->tokens[$this->pos]['type'] !== $type) {
			$found = 'end of expression';
			if ($this->pos < $this->count) {
				$found = $this->tokens[$this->pos]['value'];
			}
			throw new RuntimeException(
				sprintf('Expected "%s" in a metric expression, found "%s".', $type, $found)
			);
		}

		$this->pos++;
	}//end expect()
}//end class
