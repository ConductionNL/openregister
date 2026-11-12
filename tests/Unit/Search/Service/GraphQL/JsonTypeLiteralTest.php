<?php

/**
 * Characterisation tests for JsonType::parseLiteral().
 *
 * Pins how every AST literal kind becomes a PHP value: strings, ints, floats
 * and booleans as themselves, objects as keyed arrays, lists as lists, both
 * recursively, and anything else (null, enum, variable) as null.
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

use GraphQL\Language\Parser;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\JsonType;
use PHPUnit\Framework\TestCase;

class JsonTypeLiteralTest extends TestCase {

	private static function literal(string $source): mixed {
		return (new JsonType())->parseLiteral(Parser::parseValue($source));
	}

	public function testScalars(): void {
		$this->assertSame('a', self::literal('"a"'));
		$this->assertSame(42, self::literal('42'));
		$this->assertSame(1.5, self::literal('1.5'));
		$this->assertTrue(self::literal('true'));
		$this->assertFalse(self::literal('false'));
	}

	public function testNestedObjectsAndLists(): void {
		$this->assertSame(
			['side' => 'debit', 'n' => [1, 2.5, ['deep' => true]], 'empty' => [], 'none' => null],
			self::literal('{side: "debit", n: [1, 2.5, {deep: true}], empty: [], none: null}')
		);
	}

	public function testOtherLiteralsAreNull(): void {
		$this->assertNull(self::literal('null'));
		$this->assertNull(self::literal('SOME_ENUM'));
		$this->assertNull(self::literal('$var'));
		$this->assertSame([], self::literal('{}'));
	}
}
