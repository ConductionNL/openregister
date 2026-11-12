<?php

/**
 * Characterisation tests for the DateTime scalar's accepted forms and error messages.
 *
 * ScalarTypesTest pins that DateTime accepts and refuses; this pins the exact
 * error message of every refusal and each accepted spelling, so the scalar can
 * share the string-scalar base without a client seeing a different error.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use GraphQL\Error\Error;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\StringValueNode;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\DateTimeType;
use PHPUnit\Framework\TestCase;

class DateTimeTypeMessagesTest extends TestCase {

	private static function errorOf(callable $call): string {
		try {
			$call();
		} catch (Error $e) {
			return $e->getMessage();
		}

		return 'no error';
	}

	public function testTheNameAndDescription(): void {
		$scalar = new DateTimeType();

		$this->assertSame('DateTime', $scalar->name);
		$this->assertSame('ISO 8601 date-time string (e.g., 2025-01-15T10:30:00+00:00).', $scalar->description);
	}

	public function testSerializeSpellsDatesAsAtomAndPassesStringsThrough(): void {
		$scalar = new DateTimeType();

		$this->assertSame('2025-06-15T10:30:00+02:00', $scalar->serialize(new \DateTime('2025-06-15T10:30:00+02:00')));
		$this->assertSame('not checked', $scalar->serialize('not checked'));
		$this->assertSame('DateTime cannot represent non-date value: 5', self::errorOf(fn () => $scalar->serialize(5)));
		$this->assertSame('DateTime cannot represent non-date value: null', self::errorOf(fn () => $scalar->serialize(null)));
	}

	public function testParseValueAcceptsAtomLocalAndDateOnly(): void {
		$scalar = new DateTimeType();

		$this->assertSame('2025-06-15T10:30:00+00:00', $scalar->parseValue('2025-06-15T10:30:00+00:00'));
		$this->assertSame('2025-06-15T10:30:00', $scalar->parseValue('2025-06-15T10:30:00'));
		$this->assertSame('2025-06-15', $scalar->parseValue('2025-06-15'));
	}

	public function testParseValueMessages(): void {
		$scalar = new DateTimeType();

		$this->assertSame('DateTime cannot represent non-string value: 7', self::errorOf(fn () => $scalar->parseValue(7)));
		$this->assertSame('DateTime cannot represent invalid date-time value: gisteren', self::errorOf(fn () => $scalar->parseValue('gisteren')));
	}

	public function testParseLiteralMessages(): void {
		$scalar = new DateTimeType();

		$this->assertSame('2025-06-15', $scalar->parseLiteral(new StringValueNode(['value' => '2025-06-15'])));
		$this->assertSame('DateTime cannot represent non-string value', self::errorOf(fn () => $scalar->parseLiteral(new IntValueNode(['value' => '5']))));
		$this->assertSame(
			'DateTime cannot represent invalid date-time value: x',
			self::errorOf(fn () => $scalar->parseLiteral(new StringValueNode(['value' => 'x'])))
		);
	}
}
