<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditTrailControllerTest extends TestCase {
	private AuditTrailController $controller;
	private IRequest&MockObject $request;
	private LogService&MockObject $logService;
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private AuditHashService&MockObject $auditHashService;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->logService = $this->createMock(LogService::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditHashService = $this->createMock(AuditHashService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		// Default: an authenticated admin so the requireAdmin() gate on
		// export()/clearAll() lets the happy-path assertions through. Tests
		// that exercise non-admin rejection can override these expectations.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->controller = new AuditTrailController(
			'openregister',
			$this->request,
			$this->logService,
			$this->auditTrailMapper,
			$this->auditHashService,
			$this->userSession,
			$this->groupManager
		);
	}

	public function testIndexSuccess(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->logService->method('getAllLogs')->willReturn([]);
		$this->logService->method('countAllLogs')->willReturn(0);

		$result = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('results', $data);
		$this->assertArrayHasKey('total', $data);
	}

	public function testShowSuccess(): void {
		$log = ['id' => 1, 'action' => 'create'];
		$this->logService->method('getLog')->willReturn($log);

		$result = $this->controller->show(1);

		$this->assertEquals(200, $result->getStatus());
	}

	public function testShowNotFound(): void {
		$this->logService->method('getLog')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->show(999);

		$this->assertEquals(404, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals('Audit trail not found', $data['error']);
	}

	public function testObjectsSuccess(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->logService->method('getLogs')->willReturn([]);
		$this->logService->method('count')->willReturn(0);

		$result = $this->controller->objects('reg1', 'schema1', 'obj1');

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('results', $data);
		$this->assertArrayHasKey('total', $data);
	}

	public function testObjectsInvalidArgument(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->logService->method('getLogs')
			->willThrowException(new \InvalidArgumentException('Bad input'));

		$result = $this->controller->objects('reg1', 'schema1', 'obj1');

		$this->assertEquals(400, $result->getStatus());
	}

	public function testObjectsNotFound(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->logService->method('getLogs')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->objects('reg1', 'schema1', 'obj1');

		$this->assertEquals(404, $result->getStatus());
	}

	public function testExportSuccess(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')
			->willReturnMap([
				['format', 'csv', 'csv'],
				['includeChanges', true, true],
				['includeMetadata', false, false],
			]);

		$this->logService->method('exportLogs')->willReturn([
			'content' => 'csv-content',
			'filename' => 'export.csv',
			'contentType' => 'text/csv',
		]);

		$result = $this->controller->export();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
	}

	public function testExportInvalidFormat(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')
			->willReturnMap([
				['format', 'csv', 'invalid'],
				['includeChanges', true, true],
				['includeMetadata', false, false],
			]);

		$this->logService->method('exportLogs')
			->willThrowException(new \InvalidArgumentException('Bad format'));

		$result = $this->controller->export();

		$this->assertEquals(400, $result->getStatus());
	}

	public function testExportGeneralException(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')
			->willReturnMap([
				['format', 'csv', 'csv'],
				['includeChanges', true, true],
				['includeMetadata', false, false],
			]);

		$this->logService->method('exportLogs')
			->willThrowException(new \Exception('Export error'));

		$result = $this->controller->export();

		$this->assertEquals(500, $result->getStatus());
	}

	// ── Immutability enforcement tests ──

	public function testUpdateReturns405(): void {
		$result = $this->controller->update(1);

		$this->assertEquals(Http::STATUS_METHOD_NOT_ALLOWED, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals('Audit trail entries cannot be modified', $data['error']);
	}

	public function testDestroyReturns405(): void {
		$result = $this->controller->destroy(1);

		$this->assertEquals(Http::STATUS_METHOD_NOT_ALLOWED, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals('Audit trail entries cannot be deleted', $data['error']);
	}

	// ── Verification endpoint tests ──

	public function testVerifySuccess(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['from', null, null],
				['to', null, null],
			]);

		$this->auditHashService->method('verifyChain')->willReturn([
			'valid' => true,
			'entriesVerified' => 50,
			'brokenAt' => null,
			'skippedNullHashes' => 0,
		]);

		$result = $this->controller->verify();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['valid']);
		$this->assertEquals(50, $data['entriesVerified']);
	}

	public function testVerifyWithRange(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['from', null, '10'],
				['to', null, '20'],
			]);

		$this->auditHashService->method('verifyChain')->willReturn([
			'valid' => true,
			'entriesVerified' => 11,
			'brokenAt' => null,
			'skippedNullHashes' => 0,
			'range' => ['from' => 10, 'to' => 20],
		]);

		$result = $this->controller->verify();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['valid']);
	}

	public function testVerifyDetectsTamper(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['from', null, null],
				['to', null, null],
			]);

		$this->auditHashService->method('verifyChain')->willReturn([
			'valid' => false,
			'entriesVerified' => 49,
			'brokenAt' => 50,
			'skippedNullHashes' => 0,
		]);

		$result = $this->controller->verify();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['valid']);
		$this->assertEquals(50, $data['brokenAt']);
	}

	// ── Verwerkingsregister tests ──

	public function testVerwerkingsregisterSuccess(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['organisationId', null, null],
			]);

		$activities = [
			[
				'processingActivityId' => 'pa-001',
				'entryCount' => 10,
				'firstSeen' => '2025-01-01',
				'lastSeen' => '2025-12-31',
			],
		];

		$this->auditTrailMapper->method('getProcessingActivities')->willReturn($activities);

		$result = $this->controller->verwerkingsregister();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertCount(1, $data);
		$this->assertEquals('pa-001', $data[0]['processingActivityId']);
	}

	public function testVerwerkingsregisterEmpty(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['organisationId', null, null],
			]);

		$this->auditTrailMapper->method('getProcessingActivities')->willReturn([]);

		$result = $this->controller->verwerkingsregister();

		$this->assertEquals(200, $result->getStatus());
		$this->assertSame([], $result->getData());
	}

	// ── Inzageverzoek tests ──

	public function testInzageverzoekSuccess(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['identifier', null, '123456789'],
			]);

		$this->auditTrailMapper->method('findByIdentifier')->willReturn([
			'results' => [['schemaUuid' => 'schema-1', 'entries' => []]],
			'totalEntries' => 5,
		]);

		$result = $this->controller->inzageverzoek();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(5, $data['totalEntries']);
	}

	public function testInzageverzoekMissingIdentifier(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['identifier', null, null],
			]);

		$result = $this->controller->inzageverzoek();

		$this->assertEquals(400, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals('identifier parameter is required', $data['error']);
	}

	public function testInzageverzoekEmptyIdentifier(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['identifier', null, ''],
			]);

		$result = $this->controller->inzageverzoek();

		$this->assertEquals(400, $result->getStatus());
	}

	// ── extractRequestParameters branch coverage ──

	public function testIndexWithUnderscoreLimitAndOffset(): void {
		$this->request->method('getParams')->willReturn([
			'_limit' => '5',
			'_offset' => '10',
		]);
		$this->logService->method('getAllLogs')->willReturn([]);
		$this->logService->method('countAllLogs')->willReturn(0);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(5, $data['limit']);
		$this->assertEquals(10, $data['offset']);
	}

	public function testIndexWithPageCalculatesOffset(): void {
		$this->request->method('getParams')->willReturn([
			'page' => '3',
		]);
		$this->logService->method('getAllLogs')->willReturn([]);
		$this->logService->method('countAllLogs')->willReturn(0);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(40, $data['offset']);
		$this->assertEquals(3, $data['page']);
	}

	public function testIndexWithUnderscorePageParam(): void {
		$this->request->method('getParams')->willReturn([
			'_page' => '2',
		]);
		$this->logService->method('getAllLogs')->willReturn([]);
		$this->logService->method('countAllLogs')->willReturn(0);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(20, $data['offset']);
	}

	public function testIndexWithSortParam(): void {
		$this->request->method('getParams')->willReturn([
			'sort' => 'updated',
			'order' => 'ASC',
		]);
		$this->logService->method('getAllLogs')->willReturn([]);
		$this->logService->method('countAllLogs')->willReturn(0);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testIndexWithUnderscoreSortParam(): void {
		$this->request->method('getParams')->willReturn([
			'_sort' => 'action',
			'_order' => 'DESC',
		]);
		$this->logService->method('getAllLogs')->willReturn([]);
		$this->logService->method('countAllLogs')->willReturn(0);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testIndexWithSearchParam(): void {
		$this->request->method('getParams')->willReturn([
			'_search' => 'create',
		]);
		$this->logService->method('getAllLogs')->willReturn([]);
		$this->logService->method('countAllLogs')->willReturn(0);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testDestroyMultipleAlwaysReturns405Immutable(): void {
		// Audit trails are immutable: bulk-delete endpoint must return
		// 405 regardless of input. Replaces the legacy success/exception
		// tests that asserted 200/500 — production was changed to enforce
		// immutability per retrofit-annotate-openregister-2026-04-23 task 8.
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')
			->willReturnMap([
				['ids', null, '1,2,3'],
			]);

		$result = $this->controller->destroyMultiple();

		$this->assertEquals(405, $result->getStatus());
		$this->assertArrayHasKey('error', $result->getData());
	}

	public function testDestroyMultipleException(): void {
		$this->markTestSkipped(
			'Production destroyMultiple() always returns 405 (audit trails '
			. 'immutable); no exception path remains. Covered by '
			. 'testDestroyMultipleAlwaysReturns405Immutable.'
		);
	}

	public function testStatisticsSuccess(): void {
		$counts = [
			'total' => 7,
			'create' => 4,
			'update' => 2,
			'delete' => 1,
			'read' => 0,
		];
		$this->auditTrailMapper->expects($this->once())
			->method('getActionCounts')
			->with(null, null)
			->willReturn($counts);

		$result = $this->controller->statistics();

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($counts, $result->getData());
	}

	public function testStatisticsPassesRegisterAndSchemaScope(): void {
		$this->auditTrailMapper->expects($this->once())
			->method('getActionCounts')
			->with(3, 5)
			->willReturn(
				[
					'total' => 0,
					'create' => 0,
					'update' => 0,
					'delete' => 0,
					'read' => 0,
				]
			);

		$result = $this->controller->statistics(3, 5);

		$this->assertEquals(200, $result->getStatus());
	}

	public function testClearAllSuccess(): void {
		$this->auditTrailMapper->method('clearAllLogs')->willReturn(true);

		$result = $this->controller->clearAll();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
	}

	public function testClearAllNoExpired(): void {
		$this->auditTrailMapper->method('clearAllLogs')->willReturn(false);

		$result = $this->controller->clearAll();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
	}

	public function testClearAllException(): void {
		$this->auditTrailMapper->method('clearAllLogs')
			->willThrowException(new \Exception('Clear failed'));

		$result = $this->controller->clearAll();

		$this->assertEquals(500, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
	}

	public function testDestroyMultipleWithArrayIds(): void {
		// Same immutability semantics — array form ids[] also gets 405.
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')
			->willReturnMap([
				['ids', null, ['1', '2', '3']],
			]);

		$result = $this->controller->destroyMultiple();

		$this->assertEquals(405, $result->getStatus());
	}

	// ── Wave-3 C6 admin-only gate (cross-tenant audit-trail leak) ──

	/**
	 * Build a fresh controller with a non-admin user for gate tests.
	 *
	 * The shared `setUp()` wires an admin user so the rest of the suite
	 * exercises the happy path. C6 tests need a non-admin (or anon)
	 * user, so we rebuild the controller with overridden mocks rather
	 * than fight the sticky `willReturn` already wired in setUp().
	 *
	 * @param IUser|null $user The user the session returns; null => anon.
	 * @param bool $isAdmin Whether $user is in the admin group.
	 */
	private function makeControllerWithUser(?IUser $user, bool $isAdmin): AuditTrailController {
		$session = $this->createMock(IUserSession::class);
		$groupMgr = $this->createMock(IGroupManager::class);
		$session->method('getUser')->willReturn($user);
		if ($user !== null) {
			$groupMgr->method('isAdmin')->with($user->getUID())->willReturn($isAdmin);
		}

		return new AuditTrailController(
			'openregister',
			$this->request,
			$this->logService,
			$this->auditTrailMapper,
			$this->auditHashService,
			$session,
			$groupMgr
		);
	}

	public function testIndexReturns401WhenAnonymous(): void {
		$controller = $this->makeControllerWithUser(null, false);

		$result = $controller->index();

		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals('Authentication required', $result->getData()['error']);
	}

	public function testIndexReturns403WhenNonAdmin(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$result = $controller->index();

		$this->assertEquals(403, $result->getStatus());
		$this->assertStringContainsString('admin-only', $result->getData()['error']);
	}

	public function testStatisticsReturns401WhenAnonymous(): void {
		$controller = $this->makeControllerWithUser(null, false);

		$result = $controller->statistics();

		$this->assertEquals(401, $result->getStatus());
	}

	public function testStatisticsReturns403WhenNonAdmin(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$result = $controller->statistics();

		$this->assertEquals(403, $result->getStatus());
	}

	public function testStatisticsDoesNotInvokeMapperWhenForbidden(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$this->auditTrailMapper->expects($this->never())->method('getActionCounts');

		$controller->statistics();
	}

	public function testShowReturns401WhenAnonymous(): void {
		$controller = $this->makeControllerWithUser(null, false);

		$result = $controller->show(1);

		$this->assertEquals(401, $result->getStatus());
	}

	public function testShowReturns403WhenNonAdmin(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$result = $controller->show(42);

		$this->assertEquals(403, $result->getStatus());
	}

	public function testShowDoesNotInvokeServiceWhenForbidden(): void {
		// Defence-in-depth check: a non-admin call must short-circuit
		// before the service is touched (no DB hit, no log read).
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$this->logService->expects($this->never())->method('getLog');

		$controller->show(42);
	}

	// ── IDOR gate: per-object audit trail read (objects) is admin-only ──

	public function testObjectsReturns401WhenAnonymous(): void {
		$controller = $this->makeControllerWithUser(null, false);

		$result = $controller->objects('reg', 'schema', 'obj-uuid');

		$this->assertEquals(401, $result->getStatus());
	}

	public function testObjectsReturns403WhenNonAdmin(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$result = $controller->objects('reg', 'schema', 'obj-uuid');

		$this->assertEquals(403, $result->getStatus());
	}

	public function testObjectsDoesNotInvokeServiceWhenForbidden(): void {
		// A non-admin must be rejected before any audit log is read —
		// this is the IDOR fix: arbitrary object ids never reach the service.
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$this->logService->expects($this->never())->method('getLogs');

		$controller->objects('reg', 'schema', 'obj-uuid');
	}

	// ── IDOR gate: audit hash-chain verify is admin-only ──

	public function testVerifyReturns401WhenAnonymous(): void {
		$controller = $this->makeControllerWithUser(null, false);

		$result = $controller->verify();

		$this->assertEquals(401, $result->getStatus());
	}

	public function testVerifyReturns403WhenNonAdmin(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$result = $controller->verify();

		$this->assertEquals(403, $result->getStatus());
	}

	public function testVerifyDoesNotInvokeServiceWhenForbidden(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$this->auditHashService->expects($this->never())->method('verifyChain');

		$controller->verify();
	}
}
