<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a save keeps of the schemas and registers it has seen.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Drives the private lookups of a SaveObject and counts the mapper calls behind them.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceStrictness
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RegisterReferenceLookup
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 */
class SaveObjectLookupsGoldenTest extends TestCase {

	private SaveObject $handler;

	private SchemaMapper&MockObject $schemas;

	private RegisterMapper&MockObject $registers;

	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$reflection = new ReflectionClass(SaveObject::class);
		$this->handler = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('schemaMapper')->setValue($this->handler, $this->schemas);
		$reflection->getProperty('registerMapper')->setValue($this->handler, $this->registers);
	}//end setUp()

	/**
	 * Call a private method of the handler.
	 *
	 * @param string $name The method.
	 * @param array  $args Its arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args): mixed {
		return (new ReflectionMethod(SaveObject::class, $name))->invokeArgs($this->handler, $args);
	}//end call()

	public function testASlugIsKeptInEveryCaseAReferenceUses(): void {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('Contact');
		$this->schemas->expects($this->once())->method('findAll')->willReturn([$schema]);

		$this->assertSame('7', $this->call('resolveSchemaReference', ['#/components/schemas/Contact']));
		$this->assertSame('7', $this->call('resolveSchemaReference', ['contact']));
		$this->assertSame('7', $this->call('resolveSchemaReference', ['Contact']));
	}

	public function testASchemaAndRegisterTheCallerHoldsAreNotLookedUpAgain(): void {
		$schema = new Schema();
		$schema->setId(7);
		$register = new Register();
		$register->setId(3);
		$this->schemas->expects($this->never())->method('find');
		$this->registers->expects($this->never())->method('find');

		$this->assertSame([$schema, 7, $register, 3], $this->call('resolveSchemaAndRegister', [$schema, $register]));
		$this->assertSame($schema, $this->call('getCachedSchema', [7]));
		$this->assertSame($register, $this->call('getCachedRegister', ['3']));
	}

	public function testAReferenceNothingAnswersToIsRefusedByName(): void {
		$this->schemas->method('findAll')->willReturn([]);
		$this->schemas->method('find')->willThrowException(new \Exception('none'));

		$this->expectExceptionMessage('Could not resolve schema reference: gone');
		$this->call('resolveSchemaAndRegister', ['gone', null]);
	}

	public function testARegisterReferenceNothingAnswersToIsRefusedByName(): void {
		$schema = new Schema();
		$schema->setId(7);
		$this->registers->method('findAll')->willReturn([]);
		$this->registers->method('find')->willThrowException(new \Exception('none'));

		$this->expectExceptionMessage('Could not resolve register reference: gone');
		$this->call('resolveSchemaAndRegister', [$schema, 'gone']);
	}

	public function testAnIntegerIdIsReadThroughTheMapper(): void {
		$schema = new Schema();
		$schema->setId(7);
		$register = new Register();
		$register->setId(3);
		$this->schemas->expects($this->once())->method('find')->willReturn($schema);
		$this->registers->expects($this->once())->method('find')->willReturn($register);

		$this->assertSame([$schema, 7, $register, 3], $this->call('resolveSchemaAndRegister', [7, 3]));
	}
}//end class
