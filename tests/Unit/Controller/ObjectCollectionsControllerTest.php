<?php

/**
 * ObjectCollectionsController: the collections route's answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Collaboration\ObjectCollections;
use OCA\OpenRegister\Collaboration\ObjectMembership;
use OCA\OpenRegister\Controller\ObjectCollectionsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the collections route.
 */
#[CoversClass(ObjectCollectionsController::class)]
#[UsesClass(\OCA\OpenRegister\Db\ObjectEntity::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\SaveActor::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy::class)]
class ObjectCollectionsControllerTest extends TestCase {

	/**
	 * The controller over the given read path, collections and caller.
	 *
	 * @param ObjectOperationsInterface $objects     The read path.
	 * @param ObjectCollections         $collections The collections reader.
	 * @param IUser|null                $caller      The signed-in user.
	 * @param ObjectMembership|null     $membership  The membership changer; a mock when omitted.
	 *
	 * @return ObjectCollectionsController
	 */
	private function controller(ObjectOperationsInterface $objects, ObjectCollections $collections, ?IUser $caller, ?ObjectMembership $membership = null): ObjectCollectionsController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($caller);

		return new ObjectCollectionsController(
			request: $this->createMock(IRequest::class),
			objects: $objects,
			collections: $collections,
			userSession: $session,
			membership: $membership ?? $this->createMock(ObjectMembership::class)
		);
	}//end controller()

	/**
	 * The object is read as the caller with RBAC and multitenancy on, and its collections answered.
	 *
	 * @return void
	 */
	public function testAReadableObjectAnswersItsCollections(): void {
		$caller = $this->createMock(IUser::class);
		$object = new ObjectEntity();
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('find')->with(
			'case-1',
			$this->callback(static fn (ObjectFindOptions $options): bool => $options->register === 'zaken' && $options->schema === 'zaak' && $options->audit === false),
			true,
			true
		)->willReturn($object);
		$collections = $this->createMock(ObjectCollections::class);
		$collections->expects($this->once())->method('forObject')->with($object, $caller)
			->willReturn([['id' => 12, 'name' => 'Dossier', 'members' => []]]);

		$response = $this->controller(objects: $objects, collections: $collections, caller: $caller)->index(register: 'zaken', schema: 'zaak', id: 'case-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [['id' => 12, 'name' => 'Dossier', 'members' => []]]], $response->getData());
	}

	/**
	 * An object the caller may not read, or a failing read, answers 404 and no collection is read.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectIs404(): void {
		$caller = $this->createMock(IUser::class);
		$collections = $this->createMock(ObjectCollections::class);
		$collections->expects($this->never())->method('forObject');

		$missing = $this->createMock(ObjectOperationsInterface::class);
		$missing->method('find')->willReturn(null);
		$failing = $this->createMock(ObjectOperationsInterface::class);
		$failing->method('find')->willThrowException(new RuntimeException('denied'));

		$this->assertSame(404, $this->controller(objects: $missing, collections: $collections, caller: $caller)->index('zaken', 'zaak', 'x')->getStatus());
		$this->assertSame(404, $this->controller(objects: $failing, collections: $collections, caller: $caller)->index('zaken', 'zaak', 'x')->getStatus());
	}

	/**
	 * Nobody signed in: 401 and nothing is read.
	 *
	 * @return void
	 */
	public function testNobodySignedInIs401(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->never())->method('find');

		$this->assertSame(401, $this->controller(objects: $objects, collections: $this->createMock(ObjectCollections::class), caller: null)->index('zaken', 'zaak', 'x')->getStatus());
	}

	/**
	 * An updater's add goes to the membership with the object read as them, and answers the collections after it.
	 *
	 * @return void
	 */
	public function testAnAddAnswersTheCollectionsAfterTheChange(): void {
		$caller = $this->createMock(IUser::class);
		$object = new ObjectEntity();
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('find')->with('case-1', $this->isInstanceOf(ObjectFindOptions::class), true, true)->willReturn($object);
		$membership = $this->createMock(ObjectMembership::class);
		$membership->expects($this->once())->method('add')->with($object, 12, $caller)->willReturn(ObjectMembership::CHANGED);
		$membership->expects($this->never())->method('remove');
		$collections = $this->createMock(ObjectCollections::class);
		$collections->method('forObject')->with($object, $caller)->willReturn([['id' => 12, 'name' => 'Dossier', 'members' => []]]);

		$response = $this->controller(objects: $objects, collections: $collections, caller: $caller, membership: $membership)
			->create(register: 'zaken', schema: 'zaak', id: 'case-1', collectionId: 12);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [['id' => 12, 'name' => 'Dossier', 'members' => []]]], $response->getData());
	}

	/**
	 * Each refusal of the membership maps to its own status: 403, 400 and 404.
	 *
	 * @return void
	 */
	public function testARefusedRemovalMapsToItsStatus(): void {
		$caller = $this->createMock(IUser::class);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn(new ObjectEntity());
		$collections = $this->createMock(ObjectCollections::class);
		$collections->expects($this->never())->method('forObject');
		$membership = $this->createMock(ObjectMembership::class);
		$membership->method('remove')->willReturnOnConsecutiveCalls(
			ObjectMembership::FORBIDDEN,
			ObjectMembership::NOT_JOINABLE,
			ObjectMembership::NO_COLLECTION
		);
		$controller = $this->controller(objects: $objects, collections: $collections, caller: $caller, membership: $membership);

		$this->assertSame(403, $controller->destroy('zaken', 'zaak', 'case-1', 12)->getStatus());
		$this->assertSame(400, $controller->destroy('zaken', 'zaak', 'case-1', 12)->getStatus());
		$this->assertSame(404, $controller->destroy('zaken', 'zaak', 'case-1', 12)->getStatus());
	}

	/**
	 * An object the caller may not read is 404 before membership is asked; no collection id is 400; nobody is 401.
	 *
	 * @return void
	 */
	public function testAChangeOnAnUnreadableObjectOrWithoutCollectionIsRefusedFirst(): void {
		$caller = $this->createMock(IUser::class);
		$membership = $this->createMock(ObjectMembership::class);
		$membership->expects($this->never())->method('add');
		$membership->expects($this->never())->method('remove');
		$collections = $this->createMock(ObjectCollections::class);

		$missing = $this->createMock(ObjectOperationsInterface::class);
		$missing->method('find')->willReturn(null);
		$found = $this->createMock(ObjectOperationsInterface::class);
		$found->method('find')->willReturn(new ObjectEntity());
		$nobody = $this->createMock(ObjectOperationsInterface::class);
		$nobody->expects($this->never())->method('find');

		$this->assertSame(404, $this->controller(objects: $missing, collections: $collections, caller: $caller, membership: $membership)->destroy('zaken', 'zaak', 'x', 12)->getStatus());
		$this->assertSame(400, $this->controller(objects: $found, collections: $collections, caller: $caller, membership: $membership)->create('zaken', 'zaak', 'x')->getStatus());
		$this->assertSame(401, $this->controller(objects: $nobody, collections: $collections, caller: null, membership: $membership)->create('zaken', 'zaak', 'x', 12)->getStatus());
	}
}
