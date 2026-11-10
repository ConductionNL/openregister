<?php

/**
 * Golden test for MetricExpressionLexer::tokenise().
 *
 * Pins the exact token list for every token class the lexer knows and the
 * exact refusal message for a character outside the grammar, ahead of a
 * parameter refactor of the scanners.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\MetricExpressionLexer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden pins for the derived-metric lexer.
 */
class MetricExpressionLexerGoldenTest extends TestCase {

	/**
	 * Render tokens as `type=value` pairs joined by spaces.
	 *
	 * @param array<int, array{type: string, value: string}> $tokens The tokens.
	 *
	 * @return string The rendering.
	 */
	private function render(array $tokens): string {
		return implode(' ', array_map(fn (array $token): string => $token['type'] . '=' . $token['value'], $tokens));
	}//end render()

	/**
	 * Expressions with their pinned token rendering.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function tokenProvider(): array {
		return [
			'empty'               => ['', ''],
			'whitespace only'     => [" \t\n ", ''],
			'single digit'        => ['7', 'number=7'],
			'decimal'             => ['12.50', 'number=12.50'],
			'leading dot'         => ['.5', 'number=.5'],
			'dots run together'   => ['1..2.', 'number=1..2.'],
			'identifier'          => ['_total_2', 'ident=_total_2'],
			'ident then number'   => ['a1 2b', 'ident=a1 number=2 ident=b'],
			'number then ident'   => ['3x', 'number=3 ident=x'],
			'operators'           => ['+-*/(),', '+=+ -=- *=* /=/ (=( )=) ,=,'],
			'full expression'     => [
				'max(revenue, 0) / (orders + 1.5)*2',
				'ident=max (=( ident=revenue ,=, number=0 )=) /=/ (=( ident=orders +=+ number=1.5 )=) *=* number=2',
			],
			'no spaces'           => ['a+b', 'ident=a +=+ ident=b'],
			'trailing whitespace' => ["x \r\n", 'ident=x'],
		];
	}//end tokenProvider()

	/**
	 * Every expression lexes to the pinned tokens.
	 *
	 * @param string $expression The source.
	 * @param string $expected   The rendered tokens.
	 *
	 * @return void
	 */
	#[DataProvider('tokenProvider')]
	public function testTokenisesToThePinnedTokens(string $expression, string $expected): void {
		$tokens = (new MetricExpressionLexer())->tokenise(expression: $expression);

		$this->assertSame($expected, $this->render(tokens: $tokens));
	}//end testTokenisesToThePinnedTokens()

	/**
	 * Characters outside the grammar, with the character the refusal names.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusalProvider(): array {
		return [
			'percent'         => ['a % b', '%'],
			'after a number'  => ['1;', ';'],
			'after an ident'  => ['abc$', '$'],
			'first character' => ['#', '#'],
			'caret'           => ['2^3', '^'],
		];
	}//end refusalProvider()

	/**
	 * Every refusal keeps its pinned message.
	 *
	 * @param string $expression The source.
	 * @param string $character  The character the message names.
	 *
	 * @return void
	 */
	#[DataProvider('refusalProvider')]
	public function testRefusesWithThePinnedMessage(string $expression, string $character): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'Metric expression contains "' . $character . '", which is not part of the grammar. '
			.'Allowed: metric aliases, numbers, + - * / ( ) and min()/max().'
		);

		(new MetricExpressionLexer())->tokenise(expression: $expression);
	}//end testRefusesWithThePinnedMessage()
}//end class
