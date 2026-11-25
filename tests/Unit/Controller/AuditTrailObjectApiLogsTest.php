<?php

/**
 * GET /api/objects/{register}/{schema}/{id}/logs, served by AuditTrailController::objectLogs().
 *
 * The object API's history route moved from ObjectsController::logs() to the Compliance controller
 * that already serves the audit-trails route. These are the tests that pinned it on ObjectsController,
 * retargeted unchanged: an administrator reads the stored log (masked for explicit-reveal values), a
 * reader gets the page the reader history builds, and the 404 / 409 answers stay as they were.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Support\AdminFollowsGroups;
use Exception;
use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditTrailControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DBException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The object API's history route on AuditTrailController.
 */
class AuditTrailObjectApiLogsTest extends TestCase {
	use AdminFollowsGroups;


	private IRequest&MockObject $request;

	private ObjectService&MockObject $objectService;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private LoggerInterface&MockObject $logger;

	private ObjectHistoryForReaders&MockObject $history;

	private ?ExplicitRevealTrailMasker $masker = null;

	/**
	 * The mocks the route reads.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->adminFollowsGroups(groupManager: $this->groupManager);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->history = $this->createMock(ObjectHistoryForReaders::class);
	}//end setUp()

	/**
	 * The controller, built when the test asks for it so a test can set the masker first.
	 *
	 * @return AuditTrailController
	 */
	private function controller(): AuditTrailController {
		return AuditTrailControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(LogService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(AuditHashService::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(ReadableAuditTrailLister::class),
			$this->createMock(AuditTrailPageService::class),
			$this->history,
			$this->masker,
			$this->logger
		);
	}//end controller()

	/**
	 * Sign in a member of the admin group.
	 *
	 * @return void
	 */
	private function setupAdminUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
	}//end setupAdminUser()

	/**
	 * Sign in a reader who is not an administrator.
	 *
	 * @return void
	 */
	private function signInReader(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signInReader()

	/**
	 * The request the reader tests send.
	 *
	 * @return void
	 */
	private function readerRequest(): void {
		$this->signInReader();
		$this->request->method('getParams')->willReturn(['limit' => 5, '_search' => 'x', 'action' => 'update', 'order' => ['created' => 'ASC']]);
	}//end readerRequest()

	/**
	 * A non-admin gets the redacted page the reader history builds, and the raw log read is never made.
	 *
	 * @return void
	 */
	public function testANonAdminGetsTheRedactedHistory(): void {
		$this->readerRequest();
		$this->groupManager->method('getUserGroupIds')->willReturn(['staff']);
		$page = ['results' => [['action' => 'update', 'changed' => ['title' => ['old' => 'a', 'new' => 'b']]]], 'total' => 1, 'page' => null, 'pages' => 1.0, 'limit' => 5, 'offset' => null];
		$this->history->expects($this->once())->method('page')
			->with(new ObjectRef(register: 'reg', schema: 'sch', id: 'obj-1'), $this->callback(static fn (array $params): bool => $params['limit'] === 5 && $params['search'] === 'x' && ($params['filters']['action'] ?? null) === 'update' && $params['sort'] === ['created' => 'ASC']))
			->willReturn($page);
		$this->objectService->expects($this->never())->method('getLogs');

		$response = $this->controller()->objectLogs(id: 'obj-1', register: 'reg', schema: 'sch', objectService: $this->objectService);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($page, $response->getData());
		$this->assertArrayNotHasKey('ipAddress', $response->getData()['results'][0]);
	}//end testANonAdminGetsTheRedactedHistory()

	/**
	 * An object the caller may not read answers 404, like the audit-trails route and the object GET.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectIs404(): void {
		$this->readerRequest();
		$this->groupManager->method('getUserGroupIds')->willReturn(['staff']);
		$this->history->method('page')->willReturn(null);
		$this->objectService->expects($this->never())->method('getLogs');

		$response = $this->controller()->objectLogs(id: 'obj-1', register: 'reg', schema: 'sch', objectService: $this->objectService);

		$this->assertSame(404, $response->getStatus());
	}//end testAnUnreadableObjectIs404()

	/**
	 * A slug two objects of the register and schema hold is the 409 an administrator
	 * gets on this route, not a 500 (#4037).
	 *
	 * @return void
	 */
	public function testAnAmbiguousSlugIs409ForAReader(): void {
		$this->readerRequest();
		$this->groupManager->method('getUserGroupIds')->willReturn(['staff']);
		$this->history->method('page')->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('two'));

		$response = $this->controller()->objectLogs(id: 'dubbel', register: 'reg', schema: 'sch', objectService: $this->objectService);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('ambiguous-identifier', $response->getData()['error']);
	}//end testAnAmbiguousSlugIs409ForAReader()

	/**
	 * An administrator still reads the stored history as before: the reader history is not asked.
	 *
	 * @return void
	 */
	public function testAnAdministratorKeepsTheStoredHistory(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->setupAdminUser();
		$this->history->expects($this->never())->method('page');
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller()->objectLogs(id: 'obj-1', register: 'reg', schema: 'sch', objectService: $this->objectService);

		$this->assertSame(404, $response->getStatus());
	}//end testAnAdministratorKeepsTheStoredHistory()

	public function testLogsReturns404WhenObjectNotFound(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(1);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')->willReturn(null);

		$result = $this->controller()->objectLogs('uuid-123', 'reg1', 'schema1', $this->objectService);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(404, $result->getStatus());
	}

	/**
	 * @return array<string, array{0: \Exception, 1: int}>
	 */
	public static function logsLookupFailures(): array {
		return [
			'database' => [new DBException('Connection lost'), 1],
			'other' => [new Exception('Not found'), 1],
			'plain miss' => [new \OCP\AppFramework\Db\DoesNotExistException('Gone'), 0],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('logsLookupFailures')]
	public function testLogsAnswers404WhenFindFails(Exception $failure, int $errorLines): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$this->request->method('getParams')->willReturn([]);

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('find')->willThrowException($failure);
		$this->logger->expects($this->exactly($errorLines))->method('error');

		$result = $this->controller()->objectLogs('uuid-123', 'reg1', 'schema1', $this->objectService);

		$this->assertSame(404, $result->getStatus());
		$this->assertSame(['message' => 'Object not found'], $result->getData());
	}

	public function testLogsLeavesAnExternalSourceFailureToTheMiddleware(): void {
		$this->setupAdminUser();
		$failure = new \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException('source down', 503);
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('find')->willThrowException($failure);

		$this->expectExceptionObject($failure);
		$this->controller()->objectLogs('uuid-123', 'reg1', 'schema1', $this->objectService);
	}

	/**
	 * Test logs() returns 404 when register does not match (with message key check).
	 */
	public function testLogsReturns404WhenRegisterDoesNotMatchWithMessage(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('99'); // different register
		$objectEntity->setSchema('2');

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(2);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')->willReturn($objectEntity);

		$result = $this->controller()->objectLogs('uuid-123', '1', '2', $this->objectService);

		$this->assertSame(404, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('message', $data);
		$this->assertStringContainsString('does not belong', $data['message']);
	}

	/**
	 * Test logs() returns 404 when schema does not match (with message content).
	 */
	public function testLogsReturns404WhenSchemaDoesNotMatchWithMessage(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('1');
		$objectEntity->setSchema('99'); // different schema

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(2);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')->willReturn($objectEntity);

		$result = $this->controller()->objectLogs('uuid-123', '1', '2', $this->objectService);

		$this->assertSame(404, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('message', $data);
	}

	public function testLogsReturnsPaginatedLogsOnSuccess(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('1');
		$objectEntity->setSchema('2');
		$objectEntity->setObject(['title' => 'Test']);

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(1);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')->willReturn($objectEntity);
		$this->objectService->method('getLogs')->willReturn([
			['action' => 'create', 'timestamp' => '2024-01-01'],
		]);

		$result = $this->controller()->objectLogs('uuid-123', '1', '2', $this->objectService);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('results', $data);
		$this->assertArrayHasKey('total', $data);
	}

	public function testLogsThrowsWhenRegisterNotFound(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$this->request->method('getParams')->willReturn([]);

		$this->objectService->method('setRegister')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->controller()->objectLogs('uuid-123', '99', '2', $this->objectService);
	}

	public function testLogsThrowsWhenSchemaNotFound(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$this->request->method('getParams')->willReturn([]);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->controller()->objectLogs('uuid-123', '1', '99', $this->objectService);
	}

	/**
	 * logs() returns 200 when the schema matches, on a REAL entity.
	 *
	 * The four schema-shape tests above are skipped because they reach `logs()`
	 * through a mock returning an array or a stdClass, which `ObjectEntity` — a
	 * `?string` field — can never produce (openregister#2501). Their 404
	 * siblings already use a real entity; this is the matching 200 case, so the
	 * REACHABLE branch of the same comparison is covered by something that
	 * cannot go stale the way an impossible mock did.
	 *
	 * @return void
	 */
	public function testLogsReturns200WhenSchemaMatchesAsString(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('1');
		$objectEntity->setSchema('2');
		$objectEntity->setObject(['title' => 'Test']);

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(1);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')->willReturn($objectEntity);
		$this->objectService->method('getLogs')->willReturn([]);

		$result = $this->controller()->objectLogs('uuid-123', '1', '2', $this->objectService);

		$this->assertSame(200, $result->getStatus());
	}//end testLogsReturns200WhenSchemaMatchesAsString()

	public function testLogsReturns404WhenSchemaDoesNotMatch(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('1');
		$objectEntity->setSchema('5'); // different schema
		$objectEntity->setObject(['title' => 'Test']);

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(1);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')->willReturn($objectEntity);

		$result = $this->controller()->objectLogs('uuid-123', '1', '2', $this->objectService);

		$this->assertSame(404, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('does not belong', $data['message']);
	}

	public function testLogsReturns404WhenRegisterDoesNotMatch(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('99'); // different register
		$objectEntity->setSchema('2');
		$objectEntity->setObject(['title' => 'Test']);

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(1);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')->willReturn($objectEntity);

		$result = $this->controller()->objectLogs('uuid-123', '1', '2', $this->objectService);

		$this->assertSame(404, $result->getStatus());
	}

	public function testLogsWithPaginationParams(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('1');
		$objectEntity->setSchema('2');
		$objectEntity->setObject(['title' => 'Test']);

		$this->request->method('getParams')->willReturn([
			'_limit' => '5',
			'_offset' => '10',
			'_page' => '3',
		]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs?_page=3');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(1);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')->willReturn($objectEntity);
		$this->objectService->method('getLogs')->willReturn([
			['action' => 'update', 'timestamp' => '2024-01-02'],
		]);

		$result = $this->controller()->objectLogs('uuid-123', '1', '2', $this->objectService);

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('limit', $data);
	}

	public function testLogsReturns404OnDbException(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$this->request->method('getParams')->willReturn([]);

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(1);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')
			->willThrowException(new DBException('Database error'));

		$result = $this->controller()->objectLogs('uuid-123', '1', '2', $this->objectService);

		$this->assertSame(404, $result->getStatus());
	}

	public function testLogsWithLegacyParams(): void {
		// The stored history is the administrator's; a reader gets the redacted one (the reader tests below).
		$this->setupAdminUser();
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('1');
		$objectEntity->setSchema('2');
		$objectEntity->setObject(['title' => 'Test']);

		$this->request->method('getParams')->willReturn([
			'offset' => '10',
			'page' => '2',
			'limit' => '5',
			'_search' => 'test',
			'order' => ['created' => 'desc'],
		]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid/logs?page=2');

		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('getSchema')->willReturn(1);
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('find')->willReturn($objectEntity);
		$this->objectService->method('getLogs')->willReturn([]);

		$result = $this->controller()->objectLogs('uuid-123', '1', '2', $this->objectService);

		$this->assertSame(200, $result->getStatus());
	}

	/**
	 * An administrator's object log reads through the explicit-reveal masker.
	 *
	 * @return void
	 */
	public function testLogsAreMaskedForAnAdministrator(): void {
		$this->setupAdminUser();
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setRegister('1');
		$object->setSchema('2');
		$this->objectService->method('find')->willReturn($object);
		$this->objectService->method('getLogs')->willReturn(['plain']);
		$this->request->method('getParams')->willReturn([]);

		$masker = $this->createMock(\OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker::class);
		$masker->expects($this->once())->method('mask')->with(['plain'])->willReturn(['masked']);
		$this->masker = $masker;

		$result = $this->controller()->objectLogs('uuid', '1', '2', $this->objectService);

		$this->assertSame(['masked'], $result->getData()['results']);
	}

	/**
	 * An object stored in a register and schema, on a route that names them by slug.
	 *
	 * @param int $registerId The id the route's register resolves to.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse The answer.
	 */
	private function logsBySlug(int $registerId): \OCP\AppFramework\Http\JSONResponse {
		$this->setupAdminUser();
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setRegister('1');
		$object->setSchema('2');
		$register = new \OCA\OpenRegister\Db\Register();
		$register->setId($registerId);
		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setId(2);
		$this->objectService->method('find')->willReturn($object);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schema);
		$this->objectService->method('getLogs')->willReturn([]);
		$this->request->method('getParams')->willReturn([]);

		return $this->controller()->objectLogs('uuid', 'zaken', 'persoon', $this->objectService);
	}

	/**
	 * An administrator reads an object's log through the slugs of its register and schema.
	 *
	 * @return void
	 */
	public function testLogsAnswerAnAdministratorOnASlugRoute(): void {
		$this->assertSame(200, $this->logsBySlug(1)->getStatus());
	}

	/**
	 * A slug route of another register still answers that the object is not there.
	 *
	 * @return void
	 */
	public function testLogsRefuseASlugRouteOfAnotherRegister(): void {
		$this->assertSame(404, $this->logsBySlug(9)->getStatus());
	}

	/**
	 * An administrator's slug two objects of the register and schema hold is the 409 the object routes
	 * answer (#4037), as it was on ObjectsController (moved from ObjectsControllerAmbiguousSlugTest).
	 *
	 * @return void
	 */
	public function testAnAmbiguousSlugIs409ForAnAdministrator(): void {
		$this->setupAdminUser();
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->method('find')->willThrowException(
			new \OCP\AppFramework\Db\MultipleObjectsReturnedException('Identifier "hello-world" matches more than one object in this register and schema')
		);

		$response = $this->controller()->objectLogs('hello-world', 'zaken', 'persoon', $this->objectService);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('ambiguous-identifier', $response->getData()['error']);
		$this->assertSame('Identifier "hello-world" matches more than one object in this register and schema', $response->getData()['message']);
	}//end testAnAmbiguousSlugIs409ForAnAdministrator()
}//end class
