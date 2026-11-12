<?php

/**
 * Behaviour tests for ObjectReadAccess failure paths and helpers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An ambiguous identifier, an unreadable object and the helper lookups.
 *
 * @covers \OCA\OpenRegister\Service\Object\ObjectReadAccess
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ObjectReadAccessBehaviourTest extends TestCase {

	/**
	 * Access with a signed-in caller and a find() that throws.
	 *
	 * @param \Throwable $failure What find() throws.
	 *
	 * @return ObjectReadAccess
	 */
	private function failingFind(\Throwable $failure): ObjectReadAccess {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('setRegister')->with('5');
		$objects->expects($this->once())->method('setSchema')->with('7');
		$objects->method('find')->willThrowException($failure);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new ObjectReadAccess(objectService: $objects, userSession: $session, schemaMapper: $this->createMock(SchemaMapperInterface::class));
	}//end failingFind()

	/**
	 * An identifier matching several objects is surfaced by readableOrAmbiguous().
	 *
	 * @return void
	 */
	public function testAmbiguousIdentifierIsSurfaced(): void {
		$this->expectException(MultipleObjectsReturnedException::class);
		$this->failingFind(failure: new MultipleObjectsReturnedException('two'))
			->readableOrAmbiguous(register: '5', schema: '7', id: 'slug');
	}//end testAmbiguousIdentifierIsSurfaced()

	/**
	 * readable() turns an ambiguous identifier into "not readable".
	 *
	 * @return void
	 */
	public function testReadableTreatsAmbiguityAsNotReadable(): void {
		$this->assertNull(
			$this->failingFind(failure: new MultipleObjectsReturnedException('two'))->readable(register: '5', schema: '7', id: 'slug')
		);
	}//end testReadableTreatsAmbiguityAsNotReadable()

	/**
	 * A missing or forbidden object is not readable.
	 *
	 * @return void
	 */
	public function testMissingObjectIsNotReadable(): void {
		$this->assertNull(
			$this->failingFind(failure: new DoesNotExistException('none'))->readableOrAmbiguous(register: '5', schema: '7', id: 'x')
		);
	}//end testMissingObjectIsNotReadable()

	/**
	 * schemaOf() finds the object's schema, or answers null when it cannot be read.
	 *
	 * @return void
	 */
	public function testSchemaOfResolvesOrAnswersNull(): void {
		$schema = new Schema();
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			static fn ($id): Schema => $id === '7' ? $schema : throw new RuntimeException('gone')
		);
		$access = new ObjectReadAccess(
			objectService: $this->createMock(ObjectOperationsInterface::class),
			userSession: $this->createMock(IUserSession::class),
			schemaMapper: $mapper
		);

		$object = new ObjectEntity();
		$object->setSchema('7');
		$this->assertSame($schema, $access->schemaOf(object: $object));
		$object->setSchema('8');
		$this->assertNull($access->schemaOf(object: $object));
	}//end testSchemaOfResolvesOrAnswersNull()

	/**
	 * registerIdOf() answers a numeric register as int and anything else as null.
	 *
	 * @return void
	 */
	public function testRegisterIdOfOnlyAnswersNumericRegisters(): void {
		$access = new ObjectReadAccess(
			objectService: $this->createMock(ObjectOperationsInterface::class),
			userSession: $this->createMock(IUserSession::class),
			schemaMapper: $this->createMock(SchemaMapperInterface::class)
		);

		$object = new ObjectEntity();
		$object->setRegister('12');
		$this->assertSame(12, $access->registerIdOf(object: $object));
		$object->setRegister('my-register');
		$this->assertNull($access->registerIdOf(object: $object));
	}//end testRegisterIdOfOnlyAnswersNumericRegisters()

	/**
	 * The refusal is a 404 that does not reveal whether the object exists.
	 *
	 * @return void
	 */
	public function testNotReadableIsANotFound(): void {
		$access = new ObjectReadAccess(
			objectService: $this->createMock(ObjectOperationsInterface::class),
			userSession: $this->createMock(IUserSession::class),
			schemaMapper: $this->createMock(SchemaMapperInterface::class)
		);

		$response = $access->notReadable();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Object not found'], $response->getData());
	}//end testNotReadableIsANotFound()
}//end class
