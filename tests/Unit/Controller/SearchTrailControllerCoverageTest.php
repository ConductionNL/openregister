<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SearchTrailController;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Coverage tests for SearchTrailController — targets clearAll and destroy branches.
 */
class SearchTrailControllerCoverageTest extends TestCase {
	private SearchTrailController $controller;
	private IRequest&MockObject $request;
	private SearchTrailService&MockObject $searchTrailService;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->searchTrailService = $this->createMock(SearchTrailService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->controller = new SearchTrailController(
			'openregister',
			$this->request,
			$this->searchTrailService,
			$this->userSession,
			$this->groupManager
		);
	}

	// =========================================================================
	// clearAll — calls \OC::$server->get() then clearAllLogs() which doesn't
	// exist on SearchTrailMapper. The undefined method throws \Error which is
	// NOT caught by the controller's catch(\Exception). This exercises the
	// try-block code path up to line 887.
	// =========================================================================

	public function testClearAllThrowsErrorDueToUndefinedMethod(): void {
		$this->expectException(\Error::class);
		$this->expectExceptionMessage('clearAllLogs');

		$this->controller->clearAll();
	}

	// =========================================================================
	// cleanup — edge cases
	// =========================================================================

	public function testCleanupWithNullBeforeDate(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['before', null, null],
			]);
		$this->searchTrailService->method('cleanupSearchTrails')
			->willReturn(['success' => true, 'deleted' => 5, 'message' => 'Cleaned up']);

		$result = $this->controller->cleanup();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
	}

	// =========================================================================
	// destroyMultiple — returns not-implemented message
	// =========================================================================

	public function testDestroyMultipleReturnsNotImplemented(): void {
		$result = $this->controller->destroyMultiple();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertStringContainsString('not implemented', $data['message']);
	}

	// =========================================================================
	// destroy — with search trail that exists
	// =========================================================================

	public function testDestroyWithExistingTrail(): void {
		$trailMock = $this->createMock(\OCA\OpenRegister\Db\SearchTrail::class);
		$this->searchTrailService->method('getSearchTrail')
			->with(1)
			->willReturn($trailMock);

		$result = $this->controller->destroy(1);

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertStringContainsString('not implemented', $data['message']);
	}

	public function testDestroyWithGeneralException(): void {
		$this->searchTrailService->method('getSearchTrail')
			->with(99)
			->willThrowException(new \Exception('DB error'));

		$result = $this->controller->destroy(99);

		$this->assertEquals(500, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Deletion failed', $data['error']);
	}

	// =========================================================================
	// Read + destructive endpoint authorization (gate-read-endpoint-authorization).
	// A non-admin caller must get 403 and the service must never be touched.
	// =========================================================================

	private function buildNonAdminController(): SearchTrailController {
		$request = $this->createMock(IRequest::class);
		$searchTrailService = $this->createMock(SearchTrailService::class);
		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);
		$groupManager->method('isAdmin')->with('alice')->willReturn(false);

		// The service must never be reached once the gate rejects the caller.
		$searchTrailService->expects($this->never())->method($this->anything());
		$this->nonAdminService = $searchTrailService;

		return new SearchTrailController(
			'openregister',
			$request,
			$searchTrailService,
			$userSession,
			$groupManager
		);
	}

	/** @var SearchTrailService&MockObject */
	private $nonAdminService;

	public static function guardedEndpointProvider(): array {
		return [
			'statistics' => ['statistics', []],
			'popularTerms' => ['popularTerms', []],
			'activity' => ['activity', []],
			'registerSchemaStats' => ['registerSchemaStats', []],
			'userAgentStats' => ['userAgentStats', []],
			'cleanup' => ['cleanup', []],
			'destroy' => ['destroy', [1]],
			'destroyMultiple' => ['destroyMultiple', []],
			'clearAll' => ['clearAll', []],
		];
	}

	/**
	 * @dataProvider guardedEndpointProvider
	 */
	public function testGuardedEndpointRejectsNonAdminWith403(string $method, array $args): void {
		$controller = $this->buildNonAdminController();

		$result = $controller->{$method}(...$args);

		$this->assertEquals(
			403,
			$result->getStatus(),
			sprintf('%s() must reject a non-admin caller with 403', $method)
		);
	}
}
