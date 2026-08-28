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

		if ($this->pos < $this->count) {
			$leftover = $this->tokens[$this->pos]['value'];
			throw new RuntimeException(
				sprintf(
					'Unexpected "%s" in metric expression "%s". The grammar is arithmetic over '
					.'metric aliases: + - * / parentheses, and min()/max() of two arguments.',
					$leftover,
					$expression
				)
			);
		}

		return $value;
	}//end evaluate()


	/**
	 * expr := term (('+' | '-') term)*
	 *
	 * @return float|null The value, or null once a division by zero poisoned it.
	 */
	private function parseExpression(): ?float {
		$value = $this->parseTerm();

		while ($this->pos < $this->count
			&& ($this->tokens[$this->pos]['type'] === '+' || $this->tokens[$this->pos]['type'] === '-')
		) {
			$op = $this->tokens[$this->pos]['type'];
			$this->pos++;
			$rhs = $this->parseTerm();

			if ($value === null || $rhs === null) {
				$value = null;
				continue;
			}

			$value = ($op === '+') ? ($value + $rhs) : ($value - $rhs);
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

		while ($this->pos < $this->count
			&& ($this->tokens[$this->pos]['type'] === '*' || $this->tokens[$this->pos]['type'] === '/')
		) {
			$op = $this->tokens[$this->pos]['type'];
			$this->pos++;
			$rhs = $this->parseFactor();

			if ($value === null || $rhs === null) {
				$value = null;
				continue;
			}

			if ($op === '*') {
				$value = ($value * $rhs);
				continue;
			}

			// DIVISION BY ZERO YIELDS NULL, not INF and not NAN. json_encode()
			// refuses INF outright, and NAN compares false against everything,
			// so either one travels a long way before anybody notices.
			if ($rhs === 0.0) {
				$value = null;
				continue;
			}

			$value = ($value / $rhs);
		}//end while

		return $value;
	}//end parseTerm()

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

		if ($token['type'] === '-') {
			$this->pos++;
			$inner = $this->parseFactor();
			if ($inner === null) {
				return null;
			}

			return (0 - $inner);
		}

		if ($token['type'] === 'number') {
			$this->pos++;
			return (float)$token['value'];
		}

		if ($token['type'] === '(') {
			$this->pos++;
			$inner = $this->parseExpression();
			$this->expect(type: ')');
			return $inner;
		}

		if ($token['type'] === 'ident') {
			$name = $token['value'];

			if ($name === 'min' || $name === 'max') {
				return $this->parseMinMax(name: $name);
			}

			$this->pos++;
			return $this->resolveAlias(name: $name);
		}

		throw new RuntimeException(
			sprintf('Unexpected "%s" where a value was expected in a metric expression.', $token['value'])
		);
	}//end parseFactor()

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
			$known = array_keys($this->scope);
			sort($known);
			$available = '(none)';
			if ($known !== []) {
				$available = implode(', ', $known);
			}
			throw new RuntimeException(
				sprintf(
					'Metric expression references "%s", which is not a metric in this aggregation. '
					.'Available: %s. A derived metric can only read figures computed BEFORE it, so '
					.'order the `metrics` list accordingly.',
					$name,
					$available
				)
			);
		}

		$value = $this->scope[$name];

		if ($value === null) {
			return null;
		}

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
	}//end resolveAlias()

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
