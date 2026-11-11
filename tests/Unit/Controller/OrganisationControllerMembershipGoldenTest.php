<?php

/**
 * Golden tests for OrganisationController::join() and ::leave().
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
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationUsageService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\OrganisationControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins who may join or leave an organisation, and for whom: the anonymous
 * caller (401), the caller acting for themselves, an administrator or the
 * organisation's owner acting for someone else (allowed), and anyone else
 * acting for someone else (403, the service never called); plus the success,
 * failure and exception answers and what each logs.
 */
class OrganisationControllerMembershipGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private OrganisationService&MockObject $service;

	private OrganisationMapper&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	private IGroupManager&MockObject $groupManager;

	/**
	 * The controller with the given caller (null: nobody signed in).
	 *
	 * @param string|null $uid   The caller's id.
	 * @param bool        $admin Whether the caller is an administrator.
	 *
	 * @return OrganisationController
	 */
	private function controller(?string $uid, bool $admin = false): OrganisationController {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(OrganisationService::class);
		$this->mapper = $this->createMock(OrganisationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn($admin);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return OrganisationControllerFactory::create(
			'openregister',
			$this->request,
			$this->service,
			$this->mapper,
			$this->logger,
			$this->createMock(TenantLifecycleService::class),
			$this->createMock(TenantUsageMapper::class),
			$userSession,
			$this->groupManager,
			$this->createMock(OrganisationUsageService::class)
		);
	}

	/**
	 * The two operations and what tells them apart.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public static function operations(): array {
		return [
			'join' => ['join', 'joinOrganisation', 'Only admins or organisation owners may enroll other users', 'Failed to join organisation', '[OrganisationController] Failed to join organisation'],
			'leave' => ['leave', 'leaveOrganisation', 'Only admins or organisation owners may remove other users', 'Failed to leave organisation', '[OrganisationController] Failed to leave organisation'],
		];
	}

	#[DataProvider('operations')]
	public function testAnAnonymousCallerIsRefusedWith401(string $action, string $serviceMethod): void {
		$controller = $this->controller(uid: null);
		$this->request->method('getParams')->willReturn([]);
		$this->service->expects($this->never())->method($serviceMethod);

		$response = $controller->$action('org-1');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}

	#[DataProvider('operations')]
	public function testAnyoneElseActingForAnotherUserIsRefusedWith403(string $action, string $serviceMethod, string $refusal): void {
		$controller = $this->controller(uid: 'mallory');
		$this->request->method('getParams')->willReturn(['userId' => 'bob']);
		$organisation = new Organisation();
		$organisation->setOwner('alice');
		$this->mapper->expects($this->once())->method('findByUuid')->with('org-1')->willReturn($organisation);
		$this->service->expects($this->never())->method($serviceMethod);

		$response = $controller->$action('org-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => $refusal], $response->getData());
	}

	#[DataProvider('operations')]
	public function testAnUnknownOrganisationRefusesActingForAnotherUser(string $action, string $serviceMethod, string $refusal): void {
		$controller = $this->controller(uid: 'mallory');
		$this->request->method('getParams')->willReturn(['userId' => 'bob']);
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->service->expects($this->never())->method($serviceMethod);

		$this->assertSame(403, $controller->$action('org-1')->getStatus());
	}

	#[DataProvider('operations')]
	public function testTheOwnerMayActForAnotherUser(string $action, string $serviceMethod): void {
		$controller = $this->controller(uid: 'alice');
		$this->request->method('getParams')->willReturn(['userId' => 'bob']);
		$organisation = new Organisation();
		$organisation->setOwner('alice');
		$this->mapper->method('findByUuid')->willReturn($organisation);
		$this->service->expects($this->once())->method($serviceMethod)->with('org-1', 'bob')->willReturn(true);

		$this->assertSame(200, $controller->$action('org-1')->getStatus());
	}

	#[DataProvider('operations')]
	public function testAnAdministratorMayActForAnotherUserWithoutAnOwnerLookup(string $action, string $serviceMethod): void {
		$controller = $this->controller(uid: 'root', admin: true);
		$this->request->method('getParams')->willReturn(['userId' => 'bob']);
		$this->mapper->expects($this->never())->method('findByUuid');
		$this->service->expects($this->once())->method($serviceMethod)->with('org-1', 'bob')->willReturn(true);

		$this->assertSame(200, $controller->$action('org-1')->getStatus());
	}

	#[DataProvider('operations')]
	public function testACallerActingForThemselvesNeedsNoRight(string $action, string $serviceMethod): void {
		foreach ([['userId' => 'carol'], []] as $params) {
			$controller = $this->controller(uid: 'carol');
			$this->request->method('getParams')->willReturn($params);
			$this->mapper->expects($this->never())->method('findByUuid');
			$this->groupManager->expects($this->never())->method('isAdmin');
			$this->service->expects($this->once())->method($serviceMethod)->with('org-1', $params['userId'] ?? null)->willReturn(true);

			$this->assertSame(200, $controller->$action('org-1')->getStatus());
		}
	}

	public function testTheSuccessMessages(): void {
		$controller = $this->controller(uid: 'carol');
		$this->request->method('getParams')->willReturnOnConsecutiveCalls([], [], ['userId' => 'carol']);
		$this->service->method('joinOrganisation')->willReturn(true);
		$this->service->method('leaveOrganisation')->willReturn(true);

		$this->assertSame(['message' => 'Successfully joined organisation'], $controller->join('org-1')->getData());
		$this->assertSame(['message' => 'Successfully left organisation'], $controller->leave('org-1')->getData());
		$this->assertSame(['message' => 'Successfully removed user from organisation'], $controller->leave('org-1')->getData());
	}

	#[DataProvider('operations')]
	public function testAServiceThatSaysNoAnswers400(string $action, string $serviceMethod, string $refusal, string $failure): void {
		$controller = $this->controller(uid: 'carol');
		$this->request->method('getParams')->willReturn([]);
		$this->service->method($serviceMethod)->willReturn(false);
		$this->logger->expects($this->never())->method('error');

		$response = $controller->$action('org-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => $failure], $response->getData());
	}

	#[DataProvider('operations')]
	public function testAFailingServiceIsLoggedAndAnswers400(string $action, string $serviceMethod, string $refusal, string $failure, string $logMessage): void {
		foreach ([['userId' => 'carol'], []] as $params) {
			$controller = $this->controller(uid: 'carol');
			$this->request->method('getParams')->willReturn($params);
			$this->service->method($serviceMethod)->willThrowException(new \RuntimeException('database down'));
			$fallback = ($action === 'join') ? 'current_user' : 'current-user';
			$this->logger->expects($this->once())->method('error')->with(
				$logMessage,
				$this->callback(
					fn (array $context): bool => $context['uuid'] === 'org-1'
						&& $context['userId'] === ($params['userId'] ?? $fallback)
						&& $context['error'] === 'database down'
						&& array_keys($context) === ['file', 'line', 'uuid', 'userId', 'error']
				)
			);

			$response = $controller->$action('org-1');

			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'database down'], $response->getData());
		}
	}

	#[DataProvider('operations')]
	public function testAFailureReadingTheRequestLogsTheFallbackUser(string $action, string $serviceMethod, string $refusal, string $failure, string $logMessage): void {
		$controller = $this->controller(uid: 'carol');
		$this->request->method('getParams')->willThrowException(new \RuntimeException('bad body'));
		$fallback = ($action === 'join') ? 'current_user' : 'current-user';
		$this->logger->expects($this->once())->method('error')->with(
			$logMessage,
			$this->callback(fn (array $context): bool => $context['userId'] === $fallback && $context['error'] === 'bad body')
		);

		$this->assertSame(['error' => 'bad body'], $controller->$action('org-1')->getData());
	}
}//end class
