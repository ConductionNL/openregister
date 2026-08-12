<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\AnonymisationBackendController}.
 *
 * Verifies admin gating (200 for admins, 403 for non-admins / no session),
 * method validation (400), and that the openanonymiser probe is delegated to the
 * service (which resolves via AppAPI, issuing no HTTP request).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymiser-backend-selection/tasks.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AnonymisationBackendController;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\BackendInfo;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\Anonymisation\ProbeResult;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AnonymisationBackendControllerTest.
 */
class AnonymisationBackendControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private AnonymisationBackendService&MockObject $service;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = $this->createMock(AnonymisationBackendService::class);
	}

	private function makeController(): AnonymisationBackendController {
		return new AnonymisationBackendController(
			'openregister',
			$this->request,
			$this->userSession,
			$this->groupManager,
			$this->service
		);
	}

	private function loginAs(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($admin);
	}

	private function sampleState(): BackendState {
		return new BackendState(
			entityRecognitionEnabled: true,
			activeMethod: BackendState::METHOD_OPENANONYMISER,
			effectiveMethod: BackendState::METHOD_OPENANONYMISER,
			backends: [
				BackendState::METHOD_REGEX => new BackendInfo(BackendState::METHOD_REGEX, true, true, '2026-06-15T00:00:00+00:00', 0),
			]
		);
	}

	public function testGetBackendStateReturnsStateForAdmin(): void {
		$this->loginAs('admin', true);
		$this->service->method('getState')->willReturn($this->sampleState());

		$response = $this->makeController()->getBackendState();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(BackendState::METHOD_OPENANONYMISER, $response->getData()['activeMethod']);
	}

	public function testGetBackendStateForbiddenForNonAdmin(): void {
		$this->loginAs('bob', false);

		$response = $this->makeController()->getBackendState();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testGetBackendStateForbiddenWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->makeController()->getBackendState();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testTestConnectionRejectsInvalidMethod(): void {
		$this->loginAs('admin', true);
		$this->request->method('getParam')->with('method', '')->willReturn('nonsense');

		$response = $this->makeController()->testConnection();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testTestConnectionDelegatesForOpenAnonymiser(): void {
		$this->loginAs('admin', true);
		$this->request->method('getParam')->with('method', '')->willReturn(BackendState::METHOD_OPENANONYMISER);

		$probe = new ProbeResult(reachable: true, latencyMs: 0, error: null, probedAt: '2026-06-15T00:00:00+00:00');
		$this->service->expects($this->once())
			->method('testConnection')
			->with(BackendState::METHOD_OPENANONYMISER)
			->willReturn($probe);

		$response = $this->makeController()->testConnection();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['reachable']);
		// latencyMs 0 reflects the AppAPI path (no HTTP round-trip).
		$this->assertSame(0, $response->getData()['latencyMs']);
	}
}
