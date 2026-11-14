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
use OCA\OpenRegister\Controller\ObjectCollectionsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the collections route.
 */
#[CoversClass(ObjectCollectionsController::class)]
class ObjectCollectionsControllerTest extends TestCase {

	/**
	 * The controller over the given read path, collections and caller.
	 *
	 * @param ObjectOperationsInterface $objects     The read path.
	 * @param ObjectCollections         $collections The collections reader.
	 * @param IUser|null                $caller      The signed-in user.
	 *
	 * @return ObjectCollectionsController
	 */
	private function controller(ObjectOperationsInterface $objects, ObjectCollections $collections, ?IUser $caller): ObjectCollectionsController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($caller);

		return new ObjectCollectionsController(
			request: $this->createMock(IRequest::class),
			objects: $objects,
			collections: $collections,
			userSession: $session
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
}
