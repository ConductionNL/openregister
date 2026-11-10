<?php

/**
 * ChecksObjectUpdateRight behaviour tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Trait
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Trait;

use OCA\OpenRegister\Controller\Trait\ChecksObjectUpdateRight;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Editing an object needs the schema's `update` right for that object and its
 * owner; an unreadable schema denies.
 */
class ChecksObjectUpdateRightTest extends TestCase {

	/**
	 * The object operations double.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The permission handler double.
	 *
	 * @var PermissionHandlerInterface&MockObject
	 */
	private PermissionHandlerInterface&MockObject $permissions;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
	}//end setUp()

	/**
	 * Ask the trait through a host holding the two collaborators.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function mayUpdate(ObjectEntity $object): bool {
		$host = new class($this->objects, $this->permissions) {
			use ChecksObjectUpdateRight;

			/**
			 * Constructor.
			 *
			 * @param ObjectOperationsInterface  $objectService The object operations.
			 * @param PermissionHandlerInterface $permissions   The permission handler.
			 */
			public function __construct(
				private ObjectOperationsInterface $objectService,
				private PermissionHandlerInterface $permissions,
			) {
			}//end __construct()

			/**
			 * Expose the trait's check.
			 *
			 * @param ObjectEntity $object The object.
			 *
			 * @return bool
			 */
			public function ask(ObjectEntity $object): bool {
				return $this->mayUpdate(object: $object);
			}//end ask()
		};
		return $host->ask($object);
	}//end mayUpdate()

	/**
	 * An object owned by jan.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setOwner('jan');
		return $object;
	}//end object()

	/**
	 * The update right is asked for that schema, owner and object, and decides.
	 *
	 * @return void
	 */
	public function testTheUpdateRightDecides(): void {
		$schema = new Schema();
		$object = $this->object();
		$this->objects->method('getCurrentSchemaEntity')->willReturn($schema);
		$this->permissions->expects($this->exactly(2))
			->method('hasPermission')
			->with($schema, 'update', null, 'jan', true, $object)
			->willReturnOnConsecutiveCalls(true, false);

		$this->assertTrue($this->mayUpdate($object));
		$this->assertFalse($this->mayUpdate($object));
	}//end testTheUpdateRightDecides()

	/**
	 * Without a readable schema the edit is denied and no right is asked.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaDenies(): void {
		$this->objects->method('getCurrentSchemaEntity')->willReturn(null);
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->mayUpdate($this->object()));
	}//end testAnUnreadableSchemaDenies()
}//end class
