<?php

namespace Unit\Middleware;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Middleware\TenantQuotaMiddleware;
use OCA\OpenRegister\Middleware\TenantStatusException;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TenantQuotaMiddlewareTest extends TestCase {
	/** @var OrganisationService&MockObject */
	private OrganisationService $organisationService;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private TenantQuotaMiddleware $middleware;

	protected function setUp(): void {
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->middleware = new TenantQuotaMiddleware(
			$this->organisationService,
			$this->userSession,
			$this->groupManager,
			$this->logger
		);
	}

	public function testSkipsForUnauthenticatedRequests(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationService->expects($this->never())->method('getActiveOrganisation');

		$this->middleware->beforeController('TestController', 'index');
	}

	public function testSkipsWhenNoActiveOrganisation(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->organisationService->expects($this->once())->method('getActiveOrganisation')->willReturn(null);
		$this->groupManager->expects($this->never())->method('isAdmin');

		$this->middleware->beforeController('TestController', 'index');
	}

	public function testBlocksSuspendedOrganisation(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$org = new Organisation();
		$org->setStatus('suspended');
		$this->organisationService->method('getActiveOrganisation')->willReturn($org);

		$this->expectException(TenantStatusException::class);
		$this->expectExceptionCode(403);

		$this->middleware->beforeController('TestController', 'index');
	}

	public function testBlocksDeprovisioningOrganisation(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$org = new Organisation();
		$org->setStatus('deprovisioning');
		$this->organisationService->method('getActiveOrganisation')->willReturn($org);

		$this->expectException(TenantStatusException::class);
		$this->expectExceptionCode(403);

		$this->middleware->beforeController('TestController', 'index');
	}

	public function testBlocksRetainedOrganisation(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$org = new Organisation();
		$org->setStatus('retained');
		$this->organisationService->method('getActiveOrganisation')->willReturn($org);

		$this->expectException(TenantStatusException::class);
		$this->expectExceptionCode(403);
		$this->expectExceptionMessage('Organisation access has ended and its data is retained');

		$this->middleware->beforeController('TestController', 'index');
	}

	public function testAllowsActiveOrganisation(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$org = new Organisation();
		$org->setStatus('active');
		$org->setUuid('test-uuid');
		$this->organisationService->method('getActiveOrganisation')->willReturn($org);

		// Quota is null, so only the status can refuse this request.
		$this->middleware->beforeController('TestController', 'index');

		$org->setStatus('suspended');
		$this->expectException(TenantStatusException::class);
		$this->middleware->beforeController('TestController', 'index');
	}

	public function testProvisioningAllowsAdminOnly(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$org = new Organisation();
		$org->setStatus('provisioning');
		$this->organisationService->method('getActiveOrganisation')->willReturn($org);

		$this->expectException(TenantStatusException::class);
		$this->expectExceptionCode(403);

		$this->middleware->beforeController('TestController', 'index');
	}

	public function testProvisioningAllowsAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->expects($this->exactly(2))->method('isAdmin')->with('admin-user')
			->willReturnOnConsecutiveCalls(true, false);

		$org = new Organisation();
		$org->setStatus('provisioning');
		$org->setUuid('test-uuid');
		$this->organisationService->method('getActiveOrganisation')->willReturn($org);

		$this->middleware->beforeController('TestController', 'index');

		$this->expectException(TenantStatusException::class);
		$this->middleware->beforeController('TestController', 'index');
	}
}
