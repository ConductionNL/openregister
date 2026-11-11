<?php

/**
 * Golden outcomes of RegistrySubscriptionController's update-right guard, pinned before a refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RegistrySubscriptionController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\RegistrySubscriptionControllerFactory;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins: the update right is asked for the caller on the object, BEFORE any subscription write.
 */
class RegistrySubscriptionControllerA24GoldenTest extends TestCase {

	/**
	 * objects.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * schemas.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemas;

	/**
	 * permissions.
	 *
	 * @var PermissionHandler&MockObject
	 */
	private PermissionHandler&MockObject $permissions;

	/**
	 * subscriptions.
	 *
	 * @var RegistrySubscriptionService&MockObject
	 */
	private RegistrySubscriptionService&MockObject $subscriptions;

	/**
	 * object.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * schema.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->object = new ObjectEntity();
		$this->object->setUuid('o-1');
		$this->object->setOwner('owner-1');
		$this->object->setSchema('5');
		$this->schema = new Schema();
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->with(...FindCallShape::findArgs('o-1', [], false, 'reg', 'sch', true, true, false))->willReturn($this->object);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->schemas->method('find')->with('5')->willReturn($this->schema);
		$this->permissions = $this->createMock(PermissionHandler::class);
		$this->subscriptions = $this->createMock(RegistrySubscriptionService::class);
	}//end setUp()

	/**
	 * Build the controller; the permission verdict is recorded and checked.
	 *
	 * @param bool $allowed The update verdict.
	 *
	 * @return RegistrySubscriptionController
	 */
	private function controller(bool $allowed): RegistrySubscriptionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($this->schema, 'update', 'caller', 'owner-1', true, $this->object)
			->willReturn($allowed);

		return RegistrySubscriptionControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->objects,
			$this->schemas,
			$this->permissions,
			$session,
			$this->subscriptions,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * Subscribe refused without update.
	 *
	 * @return void
	 */
	public function testSubscribeRefusedWithoutUpdate(): void {
		$this->subscriptions->expects($this->never())->method('requestSubscription');
		$r = $this->controller(false)->subscribe('reg', 'sch', 'o-1');
		$this->assertSame(403, $r->getStatus());
		$this->assertSame(['message' => 'You do not have permission to update this object.', 'error' => 'not-authorized'], $r->getData());
	}

	/**
	 * Unsubscribe refused without update.
	 *
	 * @return void
	 */
	public function testUnsubscribeRefusedWithoutUpdate(): void {
		$this->subscriptions->expects($this->never())->method('endSubscription');
		$r = $this->controller(false)->unsubscribe('reg', 'sch', 'o-1');
		$this->assertSame(403, $r->getStatus());
		$this->assertSame(['message' => 'You do not have permission to update this object.', 'error' => 'not-authorized'], $r->getData());
	}

	/**
	 * Subscribe allowed.
	 *
	 * @return void
	 */
	public function testSubscribeAllowed(): void {
		$row = new RegistrySubscription();
		$this->subscriptions->expects($this->once())->method('requestSubscription')->with($this->object, $this->schema)->willReturn($row);
		$r = $this->controller(true)->subscribe('reg', 'sch', 'o-1');
		$this->assertSame([200, $row->toSelfMirror()], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Unsubscribe allowed.
	 *
	 * @return void
	 */
	public function testUnsubscribeAllowed(): void {
		$row = new RegistrySubscription();
		$this->subscriptions->expects($this->once())->method('endSubscription')->with($this->object)->willReturn($row);
		$r = $this->controller(true)->unsubscribe('reg', 'sch', 'o-1');
		$this->assertSame([200, $row->toSelfMirror()], [$r->getStatus(), $r->getData()]);
	}
}//end class
