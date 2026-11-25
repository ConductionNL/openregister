<?php

/**
 * Normalising the configured source of officials for the capacity policy.
 *
 * A schema source keeps register and schema and defaults its two property
 * names; a groups source keeps only groups mapped to a known capacity; anything
 * else is no source at all.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Anonymisation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Kernel\Contracts\CapacityPolicy;
use OCA\OpenRegister\Service\Anonymisation\OfficialsSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OfficialsSource::class)]
final class OfficialsSourceTest extends TestCase {

	public function testASchemaSourceIsTrimmedAndItsPropertyNamesDefault(): void {
		self::assertSame(
			[
				'type' => OfficialsSource::SCHEMA,
				'register' => 'hr',
				'schema' => 'employee',
				'nameProperty' => 'name',
				'capacityProperty' => 'capacity',
			],
			OfficialsSource::normalise(['type' => 'schema', 'register' => ' hr ', 'schema' => 'employee', 'nameProperty' => '  '])
		);
	}//end testASchemaSourceIsTrimmedAndItsPropertyNamesDefault()

	public function testASchemaSourceKeepsItsOwnPropertyNames(): void {
		$source = OfficialsSource::normalise(
			['type' => 'schema', 'register' => 'hr', 'schema' => 'employee', 'nameProperty' => 'fullName', 'capacityProperty' => 'role', 'extra' => 'dropped']
		);

		self::assertSame('fullName', $source['nameProperty']);
		self::assertSame('role', $source['capacityProperty']);
		self::assertArrayNotHasKey('extra', $source);
	}//end testASchemaSourceKeepsItsOwnPropertyNames()

	public function testAGroupsSourceKeepsOnlyNamedGroupsWithAKnownCapacity(): void {
		self::assertSame(
			[
				'type' => OfficialsSource::GROUPS,
				'groups' => ['raad' => CapacityPolicy::PUBLIC_OFFICE, 'staff' => CapacityPolicy::PROFESSIONAL],
			],
			OfficialsSource::normalise(
				[
					'type' => 'groups',
					'groups' => [
						' raad ' => CapacityPolicy::PUBLIC_OFFICE,
						'staff' => CapacityPolicy::PROFESSIONAL,
						'guests' => 'visitor',
						'   ' => CapacityPolicy::PRIVATE,
						0 => CapacityPolicy::UNKNOWN,
					],
				]
			)
		);
	}//end testAGroupsSourceKeepsOnlyNamedGroupsWithAKnownCapacity()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function noSource(): array {
		return [
			'not an array' => ['schema'],
			'no type' => [['register' => 'hr', 'schema' => 'employee']],
			'unknown type' => [['type' => 'ldap', 'register' => 'hr', 'schema' => 'employee']],
			'schema without register' => [['type' => 'schema', 'register' => ' ', 'schema' => 'employee']],
			'schema without schema' => [['type' => 'schema', 'register' => 'hr']],
			'groups without groups' => [['type' => 'groups']],
			'groups none valid' => [['type' => 'groups', 'groups' => ['guests' => 'visitor']]],
		];
	}//end noSource()

	#[DataProvider('noSource')]
	public function testAnythingElseIsNoSource(mixed $value): void {
		self::assertNull(OfficialsSource::normalise($value));
	}//end testAnythingElseIsNoSource()
}//end class
