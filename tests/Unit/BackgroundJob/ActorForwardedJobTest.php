<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Concrete probe subclass recording execute() invocations.
 */
class ProbeActorForwardedJob extends ActorForwardedJob {
	/** @var DeferredListenerContext[] */
	public array $executed = [];

	/** @var callable|null */
	public $onExecute = null;

	protected function runDeferred(DeferredListenerContext $context): void {
		$this->executed[] = $context;
		if ($this->onExecute !== null) {
			($this->onExecute)($context);
		}
	}
}

/**
 * The identity contract: impersonate the captured actor, ALWAYS restore.
 */
class ActorForwardedJobTest extends TestCase {
	private IUserSession&MockObject $userSession;
	private IUserManager&MockObject $userManager;
	private OrganisationService&MockObject $organisation;
	private LoggerInterface&MockObject $logger;
	private ProbeActorForwardedJob $job;

	/** @var array<int, mixed> */
	private array $setUserCalls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->organisation = $this->createMock(OrganisationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->setUserCalls = [];
		$this->userSession->method('setUser')
			->willReturnCallback(function ($user): void {
				$this->setUserCalls[] = $user;
			});

		$this->job = new ProbeActorForwardedJob(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->userSession,
			userManager: $this->userManager,
			organisation: $this->organisation,
			logger: $this->logger,
		);
	}

	private function runJob(array $argument): void {
		$method = (new \ReflectionClass($this->job))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, $argument);
	}

	private function arguments(?string $userId, ?string $orgUuid = null): array {
		return (new DeferredListenerContext(
			userId: $userId,
			orgUuid: $orgUuid,
			entries: [['uuid' => 'u1', 'register' => 'r', 'schema' => 's']],
		))->toJobArguments();
	}

	public function testImpersonatesCapturedUserAndRestoresPreviousSession(): void {
		$alice = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($alice);
		// Cron worker has no session user.
		$this->userSession->method('getUser')->willReturn(null);

		$this->runJob($this->arguments('alice'));

		$this->assertCount(1, $this->job->executed);
		// setUser(alice) then setUser(null) — restore ALWAYS happens.
		$this->assertSame([$alice, null], $this->setUserCalls);
	}

	public function testSessionIsRestoredWhenExecuteThrows(): void {
		$alice = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($alice);
		$this->userSession->method('getUser')->willReturn(null);
		$this->job->onExecute = static function (): void {
			throw new \RuntimeException('boom');
		};

		try {
			$this->runJob($this->arguments('alice'));
			$this->fail('Expected the execute() exception to propagate');
		} catch (\RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		// The finally block restored the previous (null) session user.
		$this->assertSame([$alice, null], $this->setUserCalls);
	}

	public function testUnresolvableUserSkipsWithoutImpersonation(): void {
		$this->userManager->method('get')->with('ghost')->willReturn(null);
		$this->logger->expects($this->once())->method('warning');

		$this->runJob($this->arguments('ghost'));

		$this->assertCount(0, $this->job->executed);
		$this->assertSame([], $this->setUserCalls);
	}

	public function testNullActorRunsWithoutImpersonation(): void {
		$this->userManager->expects($this->never())->method('get');
		$this->userSession->method('getUser')->willReturn(null);

		$this->runJob($this->arguments(null));

		$this->assertCount(1, $this->job->executed);
		// Only the finally restore fires (back to the previous null user).
		$this->assertSame([null], $this->setUserCalls);
	}

	public function testEmptyEntriesIsANoOp(): void {
		$this->userManager->expects($this->never())->method('get');

		$this->runJob((new DeferredListenerContext(userId: 'alice', orgUuid: null, entries: []))->toJobArguments());

		$this->assertCount(0, $this->job->executed);
		$this->assertSame([], $this->setUserCalls);
	}

	public function testOrganisationDriftIsLoggedAndJobProceeds(): void {
		$alice = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($alice);
		$this->userSession->method('getUser')->willReturn(null);

		$currentOrg = new Organisation();
		$currentOrg->setUuid('org-NEW');
		$this->organisation->method('getActiveOrganisation')->willReturn($currentOrg);

		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('drifted'), $this->anything());

		$this->runJob($this->arguments('alice', 'org-OLD'));

		$this->assertCount(1, $this->job->executed);
	}
}
