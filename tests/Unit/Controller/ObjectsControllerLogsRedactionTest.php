<?php

/**
 * GET /api/objects/{register}/{schema}/{id}/logs answers a non-admin through the same redaction as the audit-trails route.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The legacy /logs route no longer returns the raw audit rows to a reader who is not an administrator.
 */
class ObjectsControllerLogsRedactionTest extends TestCase {

	private ObjectsController $controller;

	private ObjectHistoryForReaders&MockObject $history;

	private ObjectService&MockObject $objectService;

	private IGroupManager&MockObject $groupManager;

	/**
	 * Wire the controller around a mocked reader history.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->history = $this->createMock(ObjectHistoryForReaders::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): ?object => ($id === ObjectHistoryForReaders::class ? $this->history : null)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['limit' => 5, '_search' => 'x', 'action' => 'update', 'order' => ['created' => 'ASC']]);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = new ObjectsController(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$session,
			$this->groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A non-admin gets the redacted page the reader history builds, and the raw log read is never made.
	 *
	 * @return void
	 */
	public function testANonAdminGetsTheRedactedHistory(): void {
		$this->groupManager->method('getUserGroupIds')->willReturn(['staff']);
		$page = ['results' => [['action' => 'update', 'changed' => ['title' => ['old' => 'a', 'new' => 'b']]]], 'total' => 1, 'page' => null, 'pages' => 1.0, 'limit' => 5, 'offset' => null];
		$this->history->expects($this->once())->method('page')
			->with('reg', 'sch', 'obj-1', $this->callback(static fn (array $params): bool => $params['limit'] === 5 && $params['search'] === 'x' && ($params['filters']['action'] ?? null) === 'update' && $params['sort'] === ['created' => 'ASC']))
			->willReturn($page);
		$this->objectService->expects($this->never())->method('getLogs');

		$response = $this->controller->logs(id: 'obj-1', register: 'reg', schema: 'sch', objectService: $this->objectService);

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
		$this->groupManager->method('getUserGroupIds')->willReturn(['staff']);
		$this->history->method('page')->willReturn(null);
		$this->objectService->expects($this->never())->method('getLogs');

		$response = $this->controller->logs(id: 'obj-1', register: 'reg', schema: 'sch', objectService: $this->objectService);

		$this->assertSame(404, $response->getStatus());
	}//end testAnUnreadableObjectIs404()

	/**
	 * A slug two objects of the register and schema hold is the 409 an administrator
	 * gets on this route, not a 500 (#4037).
	 *
	 * @return void
	 */
	public function testAnAmbiguousSlugIs409ForAReader(): void {
		$this->history->method('page')->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('two'));

		$response = $this->controller->logs(id: 'dubbel', register: 'reg', schema: 'sch', objectService: $this->objectService);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('ambiguous-identifier', $response->getData()['error']);
	}//end testAnAmbiguousSlugIs409ForAReader()

	/**
	 * An administrator still reads the stored history as before: the reader history is not asked.
	 *
	 * @return void
	 */
	public function testAnAdministratorKeepsTheStoredHistory(): void {
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
		$this->history->expects($this->never())->method('page');
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller->logs(id: 'obj-1', register: 'reg', schema: 'sch', objectService: $this->objectService);

		$this->assertSame(404, $response->getStatus());
	}//end testAnAdministratorKeepsTheStoredHistory()
}//end class
