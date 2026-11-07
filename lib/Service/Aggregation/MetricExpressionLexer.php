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
	 * The characters trim() strips by default, which the lexer skips between tokens.
	 */
	private const BLANK = " \t\n\r\0\x0B";

	/**
	 * The characters of a numeric literal: the digits that ctype_digit() accepts, and the dot.
	 */
	private const NUMBER_CHARACTERS = '0123456789.';

	/**
	 * The refusal for a character outside the grammar; `%s` is the character.
	 */
	private const OUTSIDE_GRAMMAR = 'Metric expression contains "%s", which is not part of the grammar. '
		.'Allowed: metric aliases, numbers, + - * / ( ) and min()/max().';

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
		$pos    = $this->skipBlank(expression: $expression, from: 0);

		while ($pos < strlen($expression)) {
			$token    = $this->tokenAt(expression: $expression, pos: $pos);
			$tokens[] = $token;
			$pos      = $this->skipBlank(expression: $expression, from: ($pos + strlen($token['value'])));
		}

		return $tokens;
	}//end tokenise()

	/**
	 * Advance past whitespace and return the first non-blank offset.
	 *
	 * @param string $expression The source.
	 * @param int    $from       Offset to start from.
	 *
	 * @return int The first non-blank offset, or the length of the source.
	 */
	private function skipBlank(string $expression, int $from): int {
		return ($from + strspn($expression, self::BLANK, $from));
	}//end skipBlank()

	/**
	 * Read the token that starts at a non-blank offset.
	 *
	 * @param string $expression The source.
	 * @param int    $pos        Offset of the token's first character.
	 *
	 * @return array{type: string, value: string} The token.
	 *
	 * @throws RuntimeException On a character the grammar does not contain.
	 */
	private function tokenAt(string $expression, int $pos): array {
		$ch = $expression[$pos];
		if (str_contains('+-*/(),', $ch) === true) {
			return ['type' => $ch, 'value' => $ch];
		}

		$literal = $this->literalAt(expression: $expression, pos: $pos);
		if ($literal === null) {
			throw new RuntimeException(sprintf(self::OUTSIDE_GRAMMAR, $ch));
		}

		return $literal;
	}//end tokenAt()

	/**
	 * Read the number or identifier that starts at an offset, if one does.
	 *
	 * @param string $expression The source.
	 * @param int    $pos        Offset of the literal's first character.
	 *
	 * @return array{type: string, value: string}|null The token, or null when no literal starts here.
	 */
	private function literalAt(string $expression, int $pos): ?array {
		$ch = $expression[$pos];
		if (ctype_digit($ch) === true || $ch === '.') {
			$end = $this->scanNumber(expression: $expression, from: $pos);
			return ['type' => 'number', 'value' => substr($expression, $pos, ($end - $pos))];
		}

		if (ctype_alpha($ch) === true || $ch === '_') {
			$end = $this->scanIdentifier(expression: $expression, from: $pos);
			return ['type' => 'ident', 'value' => substr($expression, $pos, ($end - $pos))];
		}

		return null;
	}//end literalAt()

	/**
	 * Advance past a numeric literal and return the end offset.
	 *
	 * @param string $expression The source.
	 * @param int    $from       Offset of the first digit or dot.
	 *
	 * @return int Offset one past the literal.
	 */
	private function scanNumber(string $expression, int $from): int {
		return ($from + strspn($expression, self::NUMBER_CHARACTERS, $from));
	}//end scanNumber()

	/**
	 * Advance past an identifier and return the end offset.
	 *
	 * @param string $expression The source.
	 * @param int    $from       Offset of the first character.
	 *
	 * @return int Offset one past the identifier.
	 */
	private function scanIdentifier(string $expression, int $from): int {
		$end = $from;
		while ($end < strlen($expression) && (ctype_alnum($expression[$end]) === true || $expression[$end] === '_')) {
			$end++;
		}

		return $end;
	}//end scanIdentifier()
}//end class
