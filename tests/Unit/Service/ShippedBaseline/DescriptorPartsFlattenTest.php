<?php

/**
 * Characterises DescriptorParts::flatten(): dotted paths, leaves, lists and the depth bound.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use PHPUnit\Framework\TestCase;

/**
 * Pins the parts a descriptor flattens to.
 */
class DescriptorPartsFlattenTest extends TestCase {

	public function testMapsBecomeDottedPathsAndListsAndEmptyMapsAreLeaves(): void {
		$parts = (new DescriptorParts())->flatten(
			descriptor: [
				'title' => 'Zaak',
				'properties' => ['naam' => ['type' => 'string', 'enum' => ['b', 'a']], 'leeg' => []],
				'required' => ['naam'],
				7 => true,
			]
		);

		$this->assertSame(
			[
				'title' => 'Zaak',
				'properties.naam.type' => 'string',
				'properties.naam.enum' => ['a', 'b'],
				'properties.leeg' => [],
				'required' => ['naam'],
				'7' => true,
			],
			$parts
		);
	}

	public function testAMapPastTheDepthBoundIsOneNormalisedLeaf(): void {
		$deep = ['z' => 1, 'a' => 2];
		for ($i = 0; $i <= DescriptorParts::MAX_DEPTH; $i++) {
			$deep = ['k' => $deep];
		}

		$parts = (new DescriptorParts())->flatten(descriptor: $deep);

		$path = implode('.', array_fill(0, (DescriptorParts::MAX_DEPTH + 1), 'k'));
		$this->assertSame([$path => ['a' => 2, 'z' => 1]], $parts);
	}

	public function testAnEarlierPathWinsOverALaterDuplicate(): void {
		$parts = (new DescriptorParts())->flatten(descriptor: ['a.b' => 1, 'a' => ['b' => 2, 'c' => 3]]);

		$this->assertSame(['a.b' => 1, 'a.c' => 3], $parts);
	}
}//end class
