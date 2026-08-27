<?php

/**
 * OpenRegister MetricExpressionLexer
 *
 * Turns a derived-metric expression into tokens, refusing any character outside
 * the grammar {@see MetricExpressionEvaluator} parses.
 *
 * Separate from the evaluator because lexing and parsing are different jobs and
 * the combined class exceeded phpmd's complexity budget — the split is the fix
 * phpmd was asking for, not a way around it.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use RuntimeException;

/**
 * Tokenises a derived-metric expression.
 */
class MetricExpressionLexer {

	/**
	 * Split the source into tokens, refusing any character outside the grammar.
	 *
	 * @param string $expression The expression source.
	 *
	 * @return array<int, array{type: string, value: string}> The tokens.
	 *
	 * @throws RuntimeException On a character the grammar does not contain.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function tokenise(string $expression): array {
		$tokens = [];
		$len    = strlen($expression);
		$pos    = 0;

		while ($pos < $len) {
			$ch = $expression[$pos];

			if (trim($ch) === '') {
				$pos++;
				continue;
			}

			if (str_contains('+-*/(),', $ch) === true) {
				$tokens[] = ['type' => $ch, 'value' => $ch];
				$pos++;
				continue;
			}

			if (ctype_digit($ch) === true || $ch === '.') {
				$end      = $this->scanNumber(expression: $expression, from: $pos, len: $len);
				$tokens[] = ['type' => 'number', 'value' => substr($expression, $pos, ($end - $pos))];
				$pos      = $end;
				continue;
			}

			if (ctype_alpha($ch) === true || $ch === '_') {
				$end      = $this->scanIdentifier(expression: $expression, from: $pos, len: $len);
				$tokens[] = ['type' => 'ident', 'value' => substr($expression, $pos, ($end - $pos))];
				$pos      = $end;
				continue;
			}

			throw new RuntimeException(
				sprintf(
					'Metric expression contains "%s", which is not part of the grammar. '
					.'Allowed: metric aliases, numbers, + - * / ( ) and min()/max().',
					$ch
				)
			);
		}//end while

		return $tokens;
	}//end tokenise()

	/**
	 * Advance past a numeric literal and return the end offset.
	 *
	 * @param string $expression The source.
	 * @param int    $from       Offset of the first digit or dot.
	 * @param int    $len        Length of the source.
	 *
	 * @return int Offset one past the literal.
	 */
	private function scanNumber(string $expression, int $from, int $len): int {
		$end = $from;
		while ($end < $len && (ctype_digit($expression[$end]) === true || $expression[$end] === '.')) {
			$end++;
		}

		return $end;
	}//end scanNumber()

	/**
	 * Advance past an identifier and return the end offset.
	 *
	 * @param string $expression The source.
	 * @param int    $from       Offset of the first character.
	 * @param int    $len        Length of the source.
	 *
	 * @return int Offset one past the identifier.
	 */
	private function scanIdentifier(string $expression, int $from, int $len): int {
		$end = $from;
		while ($end < $len && (ctype_alnum($expression[$end]) === true || $expression[$end] === '_')) {
			$end++;
		}

		return $end;
	}//end scanIdentifier()
}//end class
