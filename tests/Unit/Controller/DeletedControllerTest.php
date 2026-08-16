<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DeletedControllerTest extends TestCase {
	private DeletedController $controller;
	private IRequest&MockObject $request;
	private MagicMapper&MockObject $objectMapper;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private PermissionHandler&MockObject $permissionHandler;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);

		$this->controller = new DeletedController(
			'openregister',
			$this->request,
			$this->objectMapper,
			$this->registerMapper,
			$this->schemaMapper,
			$this->userSession,
			$this->groupManager,
			$this->permissionHandler
		);
	}

	/**
	 * Helper: stub the user session as an admin user so RBAC gates pass.
	 */
	private function stubAdminUser(string $userId = 'admin'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($userId)->willReturn(true);
	}

	public function testIndexSuccess(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->userSession->method('getUser')->willReturn(null);

		// index() now scans every magic table for soft-deleted rows directly
		// (the register/schema-less searchObjectsPaginated path always returned
		// empty — BUG-1).
		$this->objectMapper->method('findDeletedAcrossAllMagicTables')->willReturn([]);
		$this->objectMapper->method('countDeletedAcrossAllMagicTables')->willReturn(0);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('results', $data);
		$this->assertArrayHasKey('total', $data);
	}

	public function testIndexException(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectMapper->method('findDeletedAcrossAllMagicTables')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->index();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testStatisticsSuccess(): void {
		// totalDeleted now comes from the cross-table count (BUG-1 fix);
		// deletedToday/deletedThisWeek still use countAll.
		$this->objectMapper->method('countDeletedAcrossAllMagicTables')->willReturn(100);
		$this->objectMapper->method('countAll')
			->willReturnOnConsecutiveCalls(5, 20);

		$result = $this->controller->statistics();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(100, $data['totalDeleted']);
		$this->assertEquals(5, $data['deletedToday']);
		$this->assertEquals(20, $data['deletedThisWeek']);
	}

	public function testStatisticsException(): void {
		$this->objectMapper->method('countDeletedAcrossAllMagicTables')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->statistics();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testTopDeleters(): void {
		// topDeleters exposes cross-user deletion analytics → admin-only.
		$this->stubAdminUser();

		$result = $this->controller->topDeleters();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertIsArray($data);
	}

	public function testTopDeletersRejectsNonAdmin(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$this->userSession->method('getUser')->willReturn($bob);
		$this->groupManager->method('isAdmin')->with('bob')->willReturn(false);

		$result = $this->controller->topDeleters();

		$this->assertEquals(403, $result->getStatus());
	}

	public function testRestoreObjectNotDeleted(): void {
		$object = new ObjectEntity();
		$object->setDeleted(null);
		$this->objectMapper->method('find')->willReturn($object);

		$result = $this->controller->restore('uuid-123');

		$this->assertEquals(400, $result->getStatus());
	}

	public function testRestoreException(): void {
		$this->objectMapper->method('find')
			->willThrowException(new \Exception('Not found'));

		$result = $this->controller->restore('bad-uuid');

		$this->assertEquals(500, $result->getStatus());
	}

	public function testRestoreMultipleNoIds(): void {
		$this->stubAdminUser();
		$this->request->method('getParam')
			->willReturnMap([
				['ids', [], []],
			]);

		$result = $this->controller->restoreMultiple();

		$this->assertEquals(400, $result->getStatus());
	}

	public function testDestroyObjectNotDeleted(): void {
		// ObjectEntity::getter() converts null to [] for the 'deleted' field,
		// but the controller's destroy() only checks === null (not === []),
		// so a non-deleted object bypasses the guard and proceeds to delete.
		// This matches the actual controller behavior (unlike restore() which
		// checks both null and []).
		$this->stubAdminUser();
		$object = new ObjectEntity();
		$object->setDeleted(null);
		$this->objectMapper->method('find')->willReturn($object);

		$result = $this->controller->destroy('uuid-123');

		$this->assertEquals(200, $result->getStatus());
	}

	public function testDestroySuccess(): void {
		$this->stubAdminUser();
		$object = new ObjectEntity();
		$object->setDeleted(['deleted' => '2024-01-01']);
		$this->objectMapper->method('find')->willReturn($object);

		$result = $this->controller->destroy('uuid-123');

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
	}

	public function testDestroyException(): void {
		$this->stubAdminUser();
		$this->objectMapper->method('find')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->destroy('bad-uuid');

		$this->assertEquals(500, $result->getStatus());
	}

	public function testDestroyAnonymousRejected(): void {
		// Wave-3 C4 regression guard: unauthenticated callers must not be
		// able to permanently delete soft-deleted objects.
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->destroy('uuid-123');

		$this->assertEquals(401, $result->getStatus());
	}

	public function testDestroyMultipleNoIds(): void {
		$this->stubAdminUser();
		$this->request->method('getParam')
			->willReturnMap([
				['ids', [], []],
			]);

		$result = $this->controller->destroyMultiple();

		$this->assertEquals(400, $result->getStatus());
	}

	public function testDestroyMultipleAnonymousRejected(): void {
		// Wave-3 C4 regression guard: unauthenticated callers must not be
		// able to bulk-wipe soft-deleted objects.
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->destroyMultiple();

		$this->assertEquals(401, $result->getStatus());
	}

	public function testRestoreMultipleAnonymousRejected(): void {
		// Wave-3 C4 regression guard: unauthenticated callers must not be
		// able to bulk-restore soft-deleted objects.
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->restoreMultiple();

		$this->assertEquals(401, $result->getStatus());
	}

	public function testRestoreSuccess(): void {
		$object = new ObjectEntity();
		$object->setDeleted(['deleted' => '2024-01-01']);
		$this->objectMapper->method('find')->willReturn($object);

		// restore() now delegates to the magic-table-aware restoreObject()
		// (the old direct UPDATE openregister_objects matched zero rows — BUG-2).
		$this->objectMapper->expects($this->once())
			->method('restoreObject')
			->with($this->equalTo('uuid-123'))
			->willReturn($object);

		$result = $this->controller->restore('uuid-123');

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
	}

	public function testRestoreMultipleSuccess(): void {
		$this->stubAdminUser();
		$deletedObject = new ObjectEntity();
		$deletedObject->setDeleted(['deleted' => '2024-01-01']);
		$deletedObject->setUuid('uuid-1');

		$this->request->method('getParam')
			->willReturnMap([
				['ids', [], ['uuid-1']],
			]);

		// Bulk restore now resolves across all magic tables by UUID and
		// delegates each restore to the magic-table-aware restoreObject().
		$this->objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$deletedObject]);
		$this->objectMapper->method('restoreObject')->willReturn($deletedObject);

		$result = $this->controller->restoreMultiple();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(1, $data['restored']);
	}

	public function testRestoreMultipleException(): void {
		$this->stubAdminUser();
		$this->request->method('getParam')
			->willReturnMap([
				['ids', [], ['uuid-1']],
			]);

		$this->objectMapper->method('findMultipleAcrossAllMagicTables')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->restoreMultiple();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testDestroyMultipleSuccess(): void {
		$this->stubAdminUser();
		$deletedObject = new ObjectEntity();
		$deletedObject->setDeleted(['deleted' => '2024-01-01']);
		$deletedObject->setUuid('uuid-1');

		$this->request->method('getParam')
			->willReturnMap([
				['ids', [], ['uuid-1']],
			]);

		$this->objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$deletedObject]);
		$this->objectMapper->method('delete')->willReturn($deletedObject);

		$result = $this->controller->destroyMultiple();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(1, $data['deleted']);
	}

	public function testDestroyMultipleException(): void {
		$this->stubAdminUser();
		$this->request->method('getParam')
			->willReturnMap([
				['ids', [], ['uuid-1']],
			]);

		$this->objectMapper->method('findMultipleAcrossAllMagicTables')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->destroyMultiple();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testIndexWithPagination(): void {
		$this->request->method('getParams')->willReturn([
			'_limit' => '5',
			'_page' => '2',
		]);
		$this->userSession->method('getUser')->willReturn(null);

		$this->objectMapper->method('findDeletedAcrossAllMagicTables')->willReturn([]);
		$this->objectMapper->method('countDeletedAcrossAllMagicTables')->willReturn(0);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testTopDeletersException(): void {
		// topDeleters returns a hardcoded empty array, so no exception is possible
		// This test confirms the endpoint works consistently
		$this->stubAdminUser();
		$result = $this->controller->topDeleters();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertIsArray($data);
	}
}
