<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\AuditQueryController}.
 *
 * Covers admin-allowed happy paths for query()/export(), the non-admin 403
 * gate, the anonymous 401 gate, CSV vs JSON export content-type/shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AuditQueryController;
use OCA\OpenRegister\Service\AuditQueryService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditQueryControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private AuditQueryService&MockObject $auditQueryService;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private AuditQueryController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->auditQueryService = $this->createMock(AuditQueryService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		// Default: authenticated admin, so happy-path tests do not need to
		// configure the gate themselves. Non-admin/anonymous tests build
		// their own controller via makeControllerWithUser().
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($admin);
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->controller = $this->buildController();

	}//end setUp()

	private function buildController(): AuditQueryController {
		return new AuditQueryController(
			'openregister',
			$this->request,
			$this->auditQueryService,
			$this->userSession,
			$this->groupManager
		);

	}//end buildController()

	private function makeControllerWithUser(?IUser $user, bool $isAdmin): AuditQueryController {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		if ($user !== null) {
			$groupManager->method('isAdmin')->with($user->getUID())->willReturn($isAdmin);
		}

		return new AuditQueryController(
			'openregister',
			$this->request,
			$this->auditQueryService,
			$userSession,
			$groupManager
		);

	}//end makeControllerWithUser()

	public function testQueryReturnsJsonForAdmin(): void {
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return match ($key) {
					'limit' => '50',
					'offset' => '0',
					default => $default,
				};
			}
		);

		$this->auditQueryService->method('query')->willReturn(
			[
				'entries' => [['id' => 'e1', 'registerId' => 'procest', 'schemaId' => 'aiAuditEntry']],
				'total' => 1,
				'limit' => 50,
				'offset' => 0,
			]
		);

		$result = $this->controller->query();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertSame(1, $data['total']);
		$this->assertCount(1, $data['entries']);

	}//end testQueryReturnsJsonForAdmin()

	public function testQueryReturns401WhenAnonymous(): void {
		$controller = $this->makeControllerWithUser(null, false);

		$result = $controller->query();

		$this->assertSame(401, $result->getStatus());

	}//end testQueryReturns401WhenAnonymous()

	public function testQueryReturns403WhenNonAdmin(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$result = $controller->query();

		$this->assertSame(403, $result->getStatus());
		$this->assertStringContainsString('admin-only', $result->getData()['error']);

	}//end testQueryReturns403WhenNonAdmin()

	public function testQueryDoesNotInvokeServiceWhenForbidden(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$this->auditQueryService->expects($this->never())->method('query');

		$controller->query();

	}//end testQueryDoesNotInvokeServiceWhenForbidden()

	public function testQueryForwardsFiltersToService(): void {
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return match ($key) {
					'registerId' => 'procest',
					'schemaId' => 'aiAuditEntry',
					'objectId' => 'case-uuid',
					'limit' => '25',
					'offset' => '10',
					default => $default,
				};
			}
		);

		$this->auditQueryService->expects($this->once())
			->method('query')
			->with(
				filters: ['registerId' => 'procest', 'schemaId' => 'aiAuditEntry', 'objectId' => 'case-uuid'],
				limit: 25,
				offset: 10
			)
			->willReturn(['entries' => [], 'total' => 0, 'limit' => 25, 'offset' => 10]);

		$this->controller->query();

	}//end testQueryForwardsFiltersToService()

	public function testExportReturnsCsvDownloadByDefault(): void {
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return match ($key) {
					'format' => 'csv',
					'limit' => '200',
					'offset' => '0',
					default => $default,
				};
			}
		);

		$this->auditQueryService->method('query')->willReturn(
			[
				'entries' => [
					[
						'id' => 'e1',
						'registerId' => 'procest',
						'schemaId' => 'aiAuditEntry',
						'objectId' => 'case-uuid',
						'data' => ['action' => 'rejected'],
						'created' => '2026-07-12T10:00:00+00:00',
						'userId' => 'admin',
					],
				],
				'total' => 1,
				'limit' => 200,
				'offset' => 0,
			]
		);

		$result = $this->controller->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $result);

		// Read the raw (pre-merge) headers via reflection: Response::getHeaders()
		// merges in framework-computed headers (X-Request-Id, CSP, ...) via
		// \OC::$server, which is not available under the pure-mockist unit
		// bootstrap. The Content-Type/Content-Disposition headers we care
		// about are set directly on the private $headers property by
		// DownloadResponse's constructor, so reflection is sufficient and
		// avoids pulling in the full NC runtime for this assertion.
		$headersProperty = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$headersProperty->setAccessible(true);
		$headers = $headersProperty->getValue($result);

		$this->assertStringContainsString('text/csv', $headers['Content-Type']);
		$this->assertStringContainsString('attachment', $headers['Content-Disposition']);

		$csv = $result->render();
		$this->assertStringContainsString('id,registerId,schemaId,objectId,data,created,userId', $csv);
		$this->assertStringContainsString('e1', $csv);
		$this->assertStringContainsString('case-uuid', $csv);

	}//end testExportReturnsCsvDownloadByDefault()

	public function testExportReturnsJsonWhenFormatIsJson(): void {
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return match ($key) {
					'format' => 'json',
					'limit' => '200',
					'offset' => '0',
					default => $default,
				};
			}
		);

		$this->auditQueryService->method('query')->willReturn(
			['entries' => [['id' => 'e1']], 'total' => 1, 'limit' => 200, 'offset' => 0]
		);

		$result = $this->controller->export();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$data = $result->getData();
		$this->assertSame(1, $data['total']);
		$this->assertCount(1, $data['entries']);

	}//end testExportReturnsJsonWhenFormatIsJson()

	public function testExportReturns403WhenNonAdmin(): void {
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$controller = $this->makeControllerWithUser($bob, false);

		$this->auditQueryService->expects($this->never())->method('query');

		$result = $controller->export();

		$this->assertSame(403, $result->getStatus());

	}//end testExportReturns403WhenNonAdmin()
}//end class
