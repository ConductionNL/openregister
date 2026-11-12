<?php

/**
 * Wiring test for the ObjectService constructor bundles.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Every service in an ObjectService bundle lands in the ObjectService property of the same name.
 */
class ObjectServiceCollaboratorsWiringTest extends TestCase {
	/**
	 * Each leaf service of the bundles is held by ObjectService under its own name.
	 *
	 * @return void
	 */
	public function testEveryBundledServiceReachesObjectService(): void {
		$leaves = [];
		$args   = [];
		foreach ((new ReflectionClass(ObjectService::class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$args[] = $this->standIn(type: $type->getName(), leaves: $leaves);
		}

		$service    = new ObjectService(...$args);
		$reflection = new ReflectionClass(ObjectService::class);

		$this->assertCount(expectedCount: 40, haystack: $leaves);
		foreach ($leaves as $name => $leaf) {
			$this->assertTrue(condition: $reflection->hasProperty($name), message: 'ObjectService has no property ' . $name);
			$this->assertSame(expected: $leaf, actual: $reflection->getProperty($name)->getValue($service), message: 'ObjectService loses ' . $name);
		}
	}//end testEveryBundledServiceReachesObjectService()

	/**
	 * A double for a constructor argument; a final bundle is built from doubles of its own arguments.
	 *
	 * @param class-string          $type   The argument type.
	 * @param array<string, object> $leaves Collects every service double a bundle holds, by field.
	 *
	 * @return object
	 */
	private function standIn(string $type, array &$leaves): object {
		$reflection = new ReflectionClass($type);
		if ($reflection->isFinal() === false) {
			return $this->createMock(originalClassName: $type);
		}

		$args = [];
		foreach ($reflection->getConstructor()->getParameters() as $param) {
			$paramType = $param->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $paramType);
			$value  = $this->standIn(type: $paramType->getName(), leaves: $leaves);
			$args[] = $value;
			if ((new ReflectionClass($paramType->getName()))->isFinal() === false) {
				$leaves[$param->getName()] = $value;
			}
		}

		return $reflection->newInstanceArgs($args);
	}//end standIn()
}//end class
