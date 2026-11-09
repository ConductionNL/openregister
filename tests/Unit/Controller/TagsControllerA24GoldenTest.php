<?php

/**
 * Golden outcomes of TagsController's access paths, pinned before a refactor.
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

use OCA\OpenRegister\Controller\Support\TagsControllerServices;
use OCA\OpenRegister\Controller\TagsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Files\Service\File\TaggingHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins status + body of every allowed and refused tag outcome.
 */
class TagsControllerA24GoldenTest extends TestCase {

	/**
	 * objectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * taggingHandler.
	 *
	 * @var TaggingHandler&MockObject
	 */
	private TaggingHandler&MockObject $taggingHandler;

	/**
	 * permissionHandler.
	 *
	 * @var PermissionHandler&MockObject
	 */
	private PermissionHandler&MockObject $permissionHandler;

	/**
	 * fileService.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $fileService;

	/**
	 * request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * object.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->object = new ObjectEntity();
		$this->object->setUuid('obj-1');
		$this->object->setOwner('owner-1');

		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('getPermissionHandler')->willReturn($this->permissionHandler);
		$this->taggingHandler = $this->createMock(TaggingHandler::class);
		$this->taggingHandler->method('getObjectTags')->willReturn(['a', 'b']);
		$this->fileService = $this->createMock(FileService::class);
	}//end setUp()

	/**
	 * Build the controller with or without a signed-in user.
	 *
	 * @param bool $signedIn Whether a user is in the session.
	 *
	 * @return TagsController
	 */
	private function controller(bool $signedIn = true): TagsController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		return new TagsController($this->request, $this->objectService, new TagsControllerServices($this->fileService, $this->taggingHandler), $session);
	}//end controller()

	/**
	 * Make the object load and the schema resolve, with the given update verdict.
	 *
	 * @param bool|null $mayUpdate Update verdict; null for "schema not resolvable".
	 *
	 * @return void
	 */
	private function objectLoads(?bool $mayUpdate): void {
		$this->objectService->method('getObject')->willReturn($this->object);
		$schema = null;
		if ($mayUpdate !== null) {
			$schema = new Schema();
			$this->permissionHandler->method('hasPermission')->willReturnCallback(
				function (
					Schema $s,
					string $action,
					?string $userId = null,
					?string $objectOwner = null,
					bool $_rbac = true,
					?ObjectEntity $object = null
				) use ($mayUpdate): bool {
					$this->assertSame('update', $action);
					$this->assertSame('owner-1', $objectOwner);
					$this->assertSame($this->object, $object);
					return $mayUpdate;
				}
			);
		}

		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schema);
	}//end objectLoads()

	/**
	 * All tags anonymous is refused.
	 *
	 * @return void
	 */
	public function testAllTagsAnonymousIsRefused(): void {
		$this->fileService->expects($this->never())->method('getAllTags');
		$response = $this->controller(false)->getAllTags();
		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication is required'], $response->getData());
	}

	/**
	 * All tags signed in is allowed.
	 *
	 * @return void
	 */
	public function testAllTagsSignedInIsAllowed(): void {
		$this->fileService->method('getAllTags')->willReturn(['x']);
		$response = $this->controller()->getAllTags();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['x'], $response->getData());
	}

	/**
	 * Index outcomes.
	 *
	 * @return void
	 */
	public function testIndexOutcomes(): void {
		$this->objectService->method('getObject')->willReturnOnConsecutiveCalls(
			$this->object,
			null,
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new \Exception('boom'))
		);
		$c = $this->controller();
		$r = $c->index('r', 's', 'obj-1');
		$this->assertSame([200, ['a', 'b']], [$r->getStatus(), $r->getData()]);
		$r = $c->index('r', 's', 'obj-1');
		$this->assertSame([404, ['error' => 'Object not found']], [$r->getStatus(), $r->getData()]);
		$r = $c->index('r', 's', 'obj-1');
		$this->assertSame([404, ['error' => 'Object not found']], [$r->getStatus(), $r->getData()]);
		$r = $c->index('r', 's', 'obj-1');
		$this->assertSame([400, ['error' => 'boom']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Add allowed.
	 *
	 * @return void
	 */
	public function testAddAllowed(): void {
		$this->objectLoads(true);
		$this->request->method('getParams')->willReturn(['tag' => 'urgent']);
		$this->taggingHandler->expects($this->once())->method('addObjectTag')->with('obj-1', 'urgent');
		$r = $this->controller()->add('r', 's', 'obj-1');
		$this->assertSame([201, ['a', 'b']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Add refused without update right.
	 *
	 * @return void
	 */
	public function testAddRefusedWithoutUpdateRight(): void {
		$this->objectLoads(false);
		$this->request->expects($this->never())->method('getParams');
		$this->taggingHandler->expects($this->never())->method('addObjectTag');
		$r = $this->controller()->add('r', 's', 'obj-1');
		$this->assertSame(403, $r->getStatus());
		$this->assertSame(['error' => 'You may read this object but not change it, so you cannot change its tags.'], $r->getData());
	}

	/**
	 * Add refused when schema unresolvable.
	 *
	 * @return void
	 */
	public function testAddRefusedWhenSchemaUnresolvable(): void {
		$this->objectLoads(null);
		$this->permissionHandler->expects($this->never())->method('hasPermission');
		$this->taggingHandler->expects($this->never())->method('addObjectTag');
		$this->assertSame(403, $this->controller()->add('r', 's', 'obj-1')->getStatus());
	}

	/**
	 * Add without tag is400 after the right check.
	 *
	 * @return void
	 */
	public function testAddWithoutTagIs400AfterTheRightCheck(): void {
		$this->objectLoads(true);
		$this->request->method('getParams')->willReturn(['tag' => '']);
		$r = $this->controller()->add('r', 's', 'obj-1');
		$this->assertSame([400, ['error' => 'Tag name is required']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Add error mapping.
	 *
	 * @return void
	 */
	public function testAddErrorMapping(): void {
		$this->objectService->method('getObject')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new NotAuthorizedException('nope')),
			$this->throwException(new \Exception('boom'))
		);
		$c = $this->controller();
		$expected = [
			[404, ['error' => 'Object not found']],
			[404, ['error' => 'Object not found']],
			[403, ['error' => 'nope']],
			[400, ['error' => 'boom']],
		];
		foreach ($expected as $want) {
			$r = $c->add('r', 's', 'obj-1');
			$this->assertSame($want, [$r->getStatus(), $r->getData()]);
		}
	}

	/**
	 * Remove allowed.
	 *
	 * @return void
	 */
	public function testRemoveAllowed(): void {
		$this->objectLoads(true);
		$this->taggingHandler->expects($this->once())->method('removeObjectTag')->with('obj-1', 'urgent');
		$r = $this->controller()->remove('r', 's', 'obj-1', 'urgent');
		$this->assertSame([200, ['a', 'b']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Remove refused without update right.
	 *
	 * @return void
	 */
	public function testRemoveRefusedWithoutUpdateRight(): void {
		$this->objectLoads(false);
		$this->taggingHandler->expects($this->never())->method('removeObjectTag');
		$r = $this->controller()->remove('r', 's', 'obj-1', 'urgent');
		$this->assertSame(403, $r->getStatus());
		$this->assertSame(['error' => 'You may read this object but not change it, so you cannot change its tags.'], $r->getData());
	}

	/**
	 * Remove error mapping.
	 *
	 * @return void
	 */
	public function testRemoveErrorMapping(): void {
		$this->objectService->method('getObject')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new NotAuthorizedException('nope')),
			$this->throwException(new \Exception('boom'))
		);
		$c = $this->controller();
		$expected = [
			[404, ['error' => 'Object not found']],
			[404, ['error' => 'Object not found']],
			[403, ['error' => 'nope']],
			[400, ['error' => 'boom']],
		];
		foreach ($expected as $want) {
			$r = $c->remove('r', 's', 'obj-1', 't');
			$this->assertSame($want, [$r->getStatus(), $r->getData()]);
		}
	}

	/**
	 * Object is selected in order.
	 *
	 * @return void
	 */
	public function testObjectIsSelectedInOrder(): void {
		$calls = [];
		$this->objectService->method('setRegister')->willReturnCallback(function ($v) use (&$calls) {
			$calls[] = 'register:' . $v;
			return $this->objectService;
		});
		$this->objectService->method('setSchema')->willReturnCallback(function ($v) use (&$calls) {
			$calls[] = 'schema:' . $v;
			return $this->objectService;
		});
		$this->objectService->method('setObject')->willReturnCallback(function ($v) use (&$calls) {
			$calls[] = 'object:' . $v;
			return $this->objectService;
		});
		$this->objectService->method('getObject')->willReturn(null);
		$this->controller()->remove('reg', 'sch', 'id-9', 't');
		$this->assertSame(['register:reg', 'schema:sch', 'object:id-9'], $calls);
	}
}//end class
