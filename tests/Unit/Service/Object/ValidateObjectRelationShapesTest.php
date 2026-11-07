<?php

/**
 * ValidateObject keeps the shapes of a related object's union and of array-decoded nested properties.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 */
class ValidateObjectRelationShapesTest extends TestCase {

	private function transform(string $method, object $schema): void {
		$handler = new ValidateObject(
			$this->createStub(IAppConfig::class),
			$this->createStub(MagicMapperInterface::class),
			$this->createStub(SchemaMapperInterface::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(LoggerInterface::class),
			$this->createStub(IUserManager::class)
		);

		(new ReflectionMethod(ValidateObject::class, $method))->invoke($handler, $schema);
	}//end transform()

	public function testTheObjectSideOfAUnionHoldsOnlyWhatWasDeclared(): void {
		$schema = (object) ['inversedBy' => 'parent', 'properties' => [], 'required' => [], '$ref' => ''];

		$this->transform('transformToUuidProperty', $schema);

		$this->assertEquals((object) ['type' => 'object'], $schema->oneOf[0]);
	}//end testTheObjectSideOfAUnionHoldsOnlyWhatWasDeclared()

	public function testTheObjectSideOfAUnionKeepsItsStructure(): void {
		$schema = (object) ['inversedBy' => 'parent', 'properties' => ['a' => 1], 'required' => ['a'], '$ref' => 'thing'];

		$this->transform('transformToUuidProperty', $schema);

		$this->assertEquals((object) ['type' => 'object', 'properties' => ['a' => 1], 'required' => ['a'], '$ref' => 'thing'], $schema->oneOf[0]);
	}//end testTheObjectSideOfAUnionKeepsItsStructure()

	public function testAnArrayDecodedNestedPropertyIsWrittenBackTransformed(): void {
		$schema = (object) ['type' => 'object', 'properties' => (object) ['a' => ['type' => 'string', '$ref' => 'thing']]];

		$this->transform('transformPropertyForOpenRegister', $schema);

		$this->assertEquals((object) ['type' => 'string'], $schema->properties->a);
	}//end testAnArrayDecodedNestedPropertyIsWrittenBackTransformed()
}//end class
