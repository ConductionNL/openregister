<?php

/**
 * Golden test for every value PartyDefinition::fromAnnotation() reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use OCA\OpenRegister\Service\Party\PartyDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Every declared and defaulted value, pinned.
 */
class PartyDefinitionAnnotationGoldenTest extends TestCase {

	/**
	 * Every value one annotation reads as.
	 *
	 * @param array<string, mixed> $annotation The annotation.
	 *
	 * @return array<string, mixed> The values.
	 */
	private static function values(array $annotation): array {
		$definition = PartyDefinition::fromAnnotation(annotation: $annotation);

		return [
			'kind' => $definition->kind(),
			'name' => $definition->nameProperty(),
			'addresses' => $definition->addressesProperty(),
			'indicators' => $definition->indicatorsProperty(),
			'parent' => $definition->parentProperty(),
			'maxDepth' => $definition->maxDepth(),
			'surviving' => $definition->survivingProperties(),
			'union' => $definition->unionAddresses(),
		];
	}//end values()

	public function testAFullAnnotation(): void {
		$this->assertSame(
			['kind' => 'organisation', 'name' => 'naam', 'addresses' => 'adressen', 'indicators' => 'kenmerken', 'parent' => 'moeder', 'maxDepth' => 3, 'surviving' => ['kvk', 'naam'], 'union' => false],
			self::values(
				[
					'kind' => 'organisation',
					'nameProperty' => 'naam',
					'addressesProperty' => 'adressen',
					'indicatorsProperty' => 'kenmerken',
					'parentProperty' => 'moeder',
					'maxDepth' => 3,
					'merge' => ['survivingProperties' => [' kvk ', '', 'naam'], 'unionAddresses' => false],
				]
			)
		);
	}//end testAFullAnnotation()

	public function testDefaultsAndLooseValues(): void {
		$empty = self::values([]);
		$this->assertSame([], $empty['surviving']);
		$this->assertTrue($empty['union']);
		$this->assertSame('addresses', $empty['addresses']);
		$this->assertSame('indicators', $empty['indicators']);
		$this->assertNull($empty['name']);

		$this->assertSame(self::values([]), self::values(['merge' => 'yes']));
		$this->assertSame(['solo'], self::values(['merge' => ['survivingProperties' => 'solo']])['surviving']);
		$this->assertTrue(self::values(['merge' => ['unionAddresses' => 0]])['union']);
	}//end testDefaultsAndLooseValues()
}//end class
