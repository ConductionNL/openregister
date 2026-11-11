<?php

/**
 * Golden tests for the identifiers the bulk transform stamps on @self.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SaveObjects;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins generateObjectIdentifiers: which id wins, when a uuid is generated, and the register/schema fallbacks.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 */
class SaveObjectsObjectIdentifiersGoldenTest extends TestCase {

	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/**
	 * Run generateObjectIdentifiers.
	 *
	 * @param array $selfData The @self data.
	 * @param array $object   The object.
	 *
	 * @return array The @self data afterwards.
	 */
	private function identify(array $selfData, array $object): array {
		$handler = (new ReflectionClass(SaveObjects::class))->newInstanceWithoutConstructor();
		return (new ReflectionMethod(SaveObjects::class, 'generateObjectIdentifiers'))->invoke($handler, $selfData, $object);
	}//end identify()

	public function testTheObjectIdWinsOverTheSelfIdAndRegisterAndSchemaFallBackToTheObject(): void {
		$this->assertSame(
			['id' => 'csv-id', 'owner' => 'x', 'uuid' => 'csv-id', 'register' => 3, 'schema' => 'four'],
			$this->identify(['id' => 'self-id', 'owner' => 'x'], ['id' => 'csv-id', 'register' => 3, 'schema' => 'four'])
		);
	}//end testTheObjectIdWinsOverTheSelfIdAndRegisterAndSchemaFallBackToTheObject()

	public function testTheSelfIdIsUsedWhenTheObjectHasNoneAndSelfRegisterAndSchemaAreKept(): void {
		$this->assertSame(
			['register' => 1, 'schema' => 2, 'id' => 'self-id', 'uuid' => 'self-id'],
			$this->identify(['register' => 1, 'schema' => 2, 'id' => 'self-id'], ['register' => 3, 'schema' => 4])
		);
	}//end testTheSelfIdIsUsedWhenTheObjectHasNoneAndSelfRegisterAndSchemaAreKept()

	public function testNoBlankOrZeroIdGetsAGeneratedUuidAndMissingContextIsNull(): void {
		foreach ([[[], []], [['id' => '  '], []], [[], ['id' => '0']], [['id' => 'kept'], ['id' => '']]] as [$selfData, $object]) {
			$result = $this->identify($selfData, $object);

			$this->assertMatchesRegularExpression(self::UUID_PATTERN, $result['uuid']);
			$this->assertSame($result['uuid'], $result['id']);
			$this->assertSame(array_values(array_unique(array_merge(array_keys($selfData), ['uuid', 'id', 'register', 'schema']))), array_keys($result));
			$this->assertNull($result['register']);
			$this->assertNull($result['schema']);
		}

		$this->assertSame(['id', 'uuid', 'register', 'schema'], array_keys($this->identify(['id' => 'kept'], ['id' => ''])));
		$this->assertSame(['uuid', 'id', 'register', 'schema'], array_keys($this->identify([], [])));
	}//end testNoBlankOrZeroIdGetsAGeneratedUuidAndMissingContextIsNull()
}//end class
