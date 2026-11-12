<?php

/**
 * Unit tests for UuidReference.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\UuidReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The UUID found in plain and structured relation values.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\UuidReference
 */
class UuidReferenceTest extends TestCase {

	/**
	 * Values and the UUID each holds.
	 *
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function values(): array {
		return [
			'string' => ['u-1', 'u-1'],
			'empty string' => ['', null],
			'zero string' => ['0', null],
			'value before id' => [['id' => 'b', 'value' => 'a'], 'a'],
			'id' => [['id' => 'b'], 'b'],
			'uuid' => [['uuid' => 'c'], 'c'],
			'self id' => [['@self.id' => 'd'], 'd'],
			'non string skipped' => [['value' => 5, 'id' => 'b'], 'b'],
			'no key' => [['name' => 'x'], null],
			'integer' => [5, null],
			'null' => [null, null],
		];
	}//end values()

	/**
	 * Extract the UUID.
	 *
	 * @param mixed $value The value.
	 * @param string|null $expected The UUID.
	 *
	 * @return void
	 */
	#[DataProvider('values')]
	public function testUuid(mixed $value, ?string $expected): void {
		$this->assertSame($expected, (new UuidReference($value))->uuid());
	}//end testUuid()
}//end class
