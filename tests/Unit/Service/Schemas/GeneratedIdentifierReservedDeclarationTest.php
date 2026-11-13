<?php

/**
 * GeneratedIdentifierDeclaration: the `reserved` list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierException;
use PHPUnit\Framework\TestCase;

/**
 * Exact values and /patterns/ are reserved; a bad list or a broken pattern is refused at schema save.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration
 * @uses   \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierException
 */
final class GeneratedIdentifierReservedDeclarationTest extends TestCase {

	/**
	 * A declaration with this reserved list.
	 *
	 * @param mixed $reserved The list.
	 *
	 * @return GeneratedIdentifierDeclaration|null
	 */
	private static function declared(mixed $reserved): ?GeneratedIdentifierDeclaration {
		return GeneratedIdentifierDeclaration::fromProperty(
			property: ['type' => 'string', 'x-openregister-generated' => ['sequence' => 'case', 'format' => 'Z-{seq:4}', 'reserved' => $reserved]],
			path: 'identifier'
		);
	}//end declared()

	/**
	 * Exact entries match only themselves; slash-wrapped entries are patterns.
	 *
	 * @return void
	 */
	public function testValuesAndPatternsAreReserved(): void {
		$declaration = self::declared(reserved: ['Z-0666', '/^Z-00(00|13)$/']);

		$this->assertTrue(condition: $declaration?->isReserved(value: 'Z-0666'));
		$this->assertTrue(condition: $declaration?->isReserved(value: 'Z-0013'));
		$this->assertTrue(condition: $declaration?->isReserved(value: 'Z-0000'));
		$this->assertFalse(condition: $declaration?->isReserved(value: 'Z-06660'));
		$this->assertFalse(condition: $declaration?->isReserved(value: 'Z-0014'));
		$this->assertFalse(condition: self::declared(reserved: [])?->isReserved(value: 'Z-0666'));
		$this->assertTrue(condition: self::declared(reserved: ['/'])?->isReserved(value: '/'));

		$plain = GeneratedIdentifierDeclaration::fromProperty(
			property: ['type' => 'string', 'x-openregister-generated' => ['sequence' => 'case', 'format' => 'Z-{seq}']],
			path: 'identifier'
		);
		$this->assertFalse(condition: $plain?->isReserved(value: 'Z-1'));
	}//end testValuesAndPatternsAreReserved()

	/**
	 * Unusable lists are refused, naming the property.
	 *
	 * @return void
	 */
	public function testUnusableListsAreRefused(): void {
		$notAList = "x-openregister-generated.reserved at 'identifier' must be a list of values or /patterns/.";
		$notAString = "x-openregister-generated.reserved at 'identifier' holds an entry that is not a non-empty string.";
		$cases = [
			['Z-0666', $notAList],
			[['a' => 'Z-1'], $notAList],
			[[''], $notAString],
			[[7], $notAString],
			[['/(unclosed/'], "x-openregister-generated.reserved at 'identifier' holds '/(unclosed/', which is not a valid pattern."],
		];
		foreach ($cases as [$reserved, $message]) {
			try {
				self::declared(reserved: $reserved);
				$this->fail(message: 'expected a refusal for '.json_encode($reserved));
			} catch (GeneratedIdentifierException $e) {
				$this->assertSame(expected: $message, actual: $e->getMessage());
			}
		}
	}//end testUnusableListsAreRefused()
}//end class
