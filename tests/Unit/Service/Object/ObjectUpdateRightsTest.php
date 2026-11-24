<?php

/**
 * ObjectUpdateRights unit tests.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ObjectUpdateRights;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\WriteCause;
use PHPUnit\Framework\TestCase;

/**
 * ObjectUpdateRights.
 *
 * @covers \OCA\OpenRegister\Service\Object\ObjectUpdateRights
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\CauseFrame
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */
class ObjectUpdateRightsTest extends TestCase {

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('lead-1');
		$object->setSchema('12');
		$object->setOwner('owner');
		return $object;
	}//end object()

	public function testAsksUpdateOnTheObjectsSchemaWithOwnerAndObject(): void {
		$object  = $this->object();
		$schema  = new Schema();
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$causes  = [];
		$objects->expects($this->once())->method('find')->with('lead-1')->willReturnCallback(
			function () use ($object, &$causes): ObjectEntity {
				$causes[] = WriteCause::current()['cause'];
				return $object;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->once())->method('find')->with('12')->willReturn($schema);
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->expects($this->once())->method('hasPermission')->with(
			$this->callback(static function (PermissionAsk $ask) use ($schema, $object): bool {
				$check = $ask->check();

				return $check->schema === $schema
					&& $check->action === 'update'
					&& $check->userId === 'sales'
					&& $check->objectOwner === 'owner'
					&& $check->object === $object;
			})
		)->willReturn(true);

		$rights = new ObjectUpdateRights(objects: $objects, schemas: $schemas, permissions: $permissions);

		$this->assertTrue($rights->mayUpdateObject(uuid: 'lead-1', userId: 'sales'));
		$this->assertSame([WriteCause::LOOKUP], $causes);
	}//end testAsksUpdateOnTheObjectsSchemaWithOwnerAndObject()

	public function testARefusedUpdateIsFalse(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn($this->object());
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->method('hasPermission')->willReturn(false);

		$rights = new ObjectUpdateRights(objects: $objects, schemas: $schemas, permissions: $permissions);

		$this->assertFalse($rights->mayUpdateObject(uuid: 'lead-1', userId: 'sales'));
	}//end testARefusedUpdateIsFalse()

	public function testAnObjectThatDoesNotLoadIsFalseWithoutAPermissionCheck(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn(null);
		$schemas     = $this->createMock(SchemaMapperInterface::class);
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$schemas->expects($this->never())->method('find');
		$permissions->expects($this->never())->method('hasPermission');

		$rights = new ObjectUpdateRights(objects: $objects, schemas: $schemas, permissions: $permissions);

		$this->assertFalse($rights->mayUpdateObject(uuid: 'gone', userId: 'sales'));
	}//end testAnObjectThatDoesNotLoadIsFalseWithoutAPermissionCheck()

	public function testALoadFailurePropagates(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willThrowException(new Exception('Object not found'));
		$rights = new ObjectUpdateRights(
			objects: $objects,
			schemas: $this->createMock(SchemaMapperInterface::class),
			permissions: $this->createMock(PermissionCheckInterface::class)
		);

		$this->expectException(Exception::class);
		$rights->mayUpdateObject(uuid: 'gone', userId: 'sales');
	}//end testALoadFailurePropagates()
}//end class
