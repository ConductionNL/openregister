<?php

/**
 * Tagging an object is a change to it, so it needs the update right (openregister#4096).
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

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\TagsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\File\TaggingHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A reader who may not update an object may not tag or untag it either.
 *
 * Before openregister#4096 add() and remove() loaded the object through the
 * read path and wrote the tag, so the only right they checked was `read`.
 */
class TagsControllerUpdateRightTest extends TestCase {

	private TagsController $controller;

	private ObjectService&MockObject $objectService;

	private TaggingHandler&MockObject $taggingHandler;

	private PermissionHandler&MockObject $permissionHandler;

	private IRequest&MockObject $request;

	private Schema $schema;

	private ObjectEntity $object;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['tag' => 'urgent']);

		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setTitle('Zaak');

		$this->object = new ObjectEntity();
		$this->object->setUuid('zaak-1');
		$this->object->setOwner('someone-else');
		$this->object->setSchema('7');

		$this->permissionHandler = $this->createMock(PermissionHandler::class);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('getObject')->willReturn($this->object);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($this->schema);
		$this->objectService->method('getPermissionHandler')->willReturn($this->permissionHandler);

		$this->taggingHandler = $this->createMock(TaggingHandler::class);
		$this->taggingHandler->method('getObjectTags')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('reader');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = new TagsController(
			'openregister',
			$this->request,
			$this->objectService,
			$this->createMock(FileService::class),
			$this->taggingHandler,
			$session
		);
	}//end setUp()

	/**
	 * Answer the update question for this object.
	 *
	 * @param bool $mayUpdate The verdict.
	 *
	 * @return void
	 */
	private function updateRight(bool $mayUpdate): void {
		$this->permissionHandler->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null) use ($mayUpdate): bool {
				$this->assertSame('update', $action, 'Tagging must ask for the update right.');
				$this->assertSame($this->object, $object, 'The update right is decided on the object being tagged.');
				return $mayUpdate;
			}
		);
	}//end updateRight()

	/**
	 * A reader without update gets 403 on add, and no tag is written.
	 *
	 * @return void
	 */
	public function testAddingATagWithoutUpdateIsRefused(): void {
		$this->updateRight(mayUpdate: false);
		$this->taggingHandler->expects($this->never())->method('addObjectTag');

		$response = $this->controller->add('zaken', 'zaak', 'zaak-1');

		$this->assertSame(403, $response->getStatus());
	}//end testAddingATagWithoutUpdateIsRefused()

	/**
	 * A reader without update gets 403 on remove, and no tag is removed.
	 *
	 * @return void
	 */
	public function testRemovingATagWithoutUpdateIsRefused(): void {
		$this->updateRight(mayUpdate: false);
		$this->taggingHandler->expects($this->never())->method('removeObjectTag');

		$response = $this->controller->remove('zaken', 'zaak', 'zaak-1', 'urgent');

		$this->assertSame(403, $response->getStatus());
	}//end testRemovingATagWithoutUpdateIsRefused()

	/**
	 * With the update right the tag is written as before.
	 *
	 * @return void
	 */
	public function testAddingATagWithUpdateWritesIt(): void {
		$this->updateRight(mayUpdate: true);
		$this->taggingHandler->expects($this->once())->method('addObjectTag')->with('zaak-1', 'urgent');

		$this->assertSame(201, $this->controller->add('zaken', 'zaak', 'zaak-1')->getStatus());
	}//end testAddingATagWithUpdateWritesIt()

	/**
	 * With the update right the tag is removed as before.
	 *
	 * @return void
	 */
	public function testRemovingATagWithUpdateRemovesIt(): void {
		$this->updateRight(mayUpdate: true);
		$this->taggingHandler->expects($this->once())->method('removeObjectTag')->with('zaak-1', 'urgent');

		$this->assertSame(200, $this->controller->remove('zaken', 'zaak', 'zaak-1', 'urgent')->getStatus());
	}//end testRemovingATagWithUpdateRemovesIt()
}//end class
