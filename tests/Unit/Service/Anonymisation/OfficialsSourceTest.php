<?php

/**
 * Unit tests for OfficialsSource.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Anonymisation\CapacityPolicy;
use OCA\OpenRegister\Service\Anonymisation\OfficialsSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A stored officials source is read back in one canonical shape, or not at all.
 */
#[CoversClass(OfficialsSource::class)]
final class OfficialsSourceTest extends TestCase {

	public function testANonArrayOrAnUnknownTypeIsNoSource(): void {
		$this->assertNull(OfficialsSource::normalise(null));
		$this->assertNull(OfficialsSource::normalise('schema'));
		$this->assertNull(OfficialsSource::normalise([]));
		$this->assertNull(OfficialsSource::normalise(['type' => 'ldap', 'register' => 'r', 'schema' => 's']));
	}//end testANonArrayOrAnUnknownTypeIsNoSource()

	public function testASchemaSourceIsTrimmedAndGetsItsDefaultProperties(): void {
		$source = OfficialsSource::normalise(
			[
				'type' => OfficialsSource::SCHEMA,
				'register' => '  officials  ',
				'schema' => 'person ',
				'nameProperty' => '   ',
				'extra' => 'dropped',
			]
		);

		$this->assertSame(
			[
				'type' => OfficialsSource::SCHEMA,
				'register' => 'officials',
				'schema' => 'person',
				'nameProperty' => 'name',
				'capacityProperty' => 'capacity',
			],
			$source
		);
	}//end testASchemaSourceIsTrimmedAndGetsItsDefaultProperties()

	public function testASchemaSourceKeepsTheNamedProperties(): void {
		$source = OfficialsSource::normalise(
			[
				'type' => OfficialsSource::SCHEMA,
				'register' => 5,
				'schema' => 12,
				'nameProperty' => 'fullName',
				'capacityProperty' => 'role',
			]
		);

		$this->assertSame(
			['type' => 'schema', 'register' => '5', 'schema' => '12', 'nameProperty' => 'fullName', 'capacityProperty' => 'role'],
			$source
		);
	}//end testASchemaSourceKeepsTheNamedProperties()

	public function testASchemaSourceWithoutRegisterOrSchemaIsNoSource(): void {
		$this->assertNull(OfficialsSource::normalise(['type' => OfficialsSource::SCHEMA, 'schema' => 'person']));
		$this->assertNull(OfficialsSource::normalise(['type' => OfficialsSource::SCHEMA, 'register' => 'officials', 'schema' => ' ']));
	}//end testASchemaSourceWithoutRegisterOrSchemaIsNoSource()

	public function testAGroupsSourceKeepsOnlyNamedGroupsWithAKnownCapacity(): void {
		$source = OfficialsSource::normalise(
			[
				'type' => OfficialsSource::GROUPS,
				'groups' => [
					' raad '     => CapacityPolicy::PUBLIC_OFFICE,
					'ambtenaren' => CapacityPolicy::PROFESSIONAL,
					'burgers'    => 'citizen',
					'   '        => CapacityPolicy::PRIVATE,
					7            => CapacityPolicy::UNKNOWN,
				],
			]
		);

		$this->assertSame(
			[
				'type' => OfficialsSource::GROUPS,
				'groups' => ['raad' => 'public-office', 'ambtenaren' => 'professional'],
			],
			$source
		);
	}//end testAGroupsSourceKeepsOnlyNamedGroupsWithAKnownCapacity()

	public function testAGroupsSourceWithNoUsableGroupIsNoSource(): void {
		$this->assertNull(OfficialsSource::normalise(['type' => OfficialsSource::GROUPS]));
		$this->assertNull(OfficialsSource::normalise(['type' => OfficialsSource::GROUPS, 'groups' => ['raad' => 'mayor']]));
	}//end testAGroupsSourceWithNoUsableGroupIsNoSource()
}//end class
