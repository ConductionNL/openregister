<?php

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TenantLifecycleServiceTest extends TestCase {
	/** @var OrganisationMapper&MockObject */
	private OrganisationMapper $organisationMapper;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	/** @var IUserManager&MockObject */
	private IUserManager $userManager;

	/** @var IEventDispatcher&MockObject */
	private IEventDispatcher $eventDispatcher;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private TenantLifecycleService $service;

	protected function setUp(): void {
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new TenantLifecycleService(
			$this->organisationMapper,
			$this->groupManager,
			$this->userManager,
			$this->eventDispatcher,
			$this->logger
		);
	}

	/**
	 * @dataProvider validTransitionsProvider
	 */
	public function testValidTransitions(string $from, string $to): void {
		// Should not throw.
		$this->service->validateTransition($from, $to);
		$this->assertTrue(true);
	}

	public static function validTransitionsProvider(): array {
		return [
			'provisioning to active' => ['provisioning', 'active'],
			'active to suspended' => ['active', 'suspended'],
			'active to deprovisioning' => ['active', 'deprovisioning'],
			'suspended to active' => ['suspended', 'active'],
			'suspended to deprovisioning' => ['suspended', 'deprovisioning'],
			'deprovisioning to archived' => ['deprovisioning', 'archived'],
			'active to retained' => ['active', 'retained'],
			'suspended to retained' => ['suspended', 'retained'],
			'retained to deprovisioning' => ['retained', 'deprovisioning'],
		];
	}

	/**
	 * @dataProvider invalidTransitionsProvider
	 */
	public function testInvalidTransitions(string $from, string $to): void {
		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->service->validateTransition($from, $to);
	}

	public static function invalidTransitionsProvider(): array {
		return [
			'archived to active' => ['archived', 'active'],
			'archived to provisioning' => ['archived', 'provisioning'],
			'provisioning to suspended' => ['provisioning', 'suspended'],
			'active to provisioning' => ['active', 'provisioning'],
			'deprovisioning to active' => ['deprovisioning', 'active'],
			'active to archived' => ['active', 'archived'],
			'retained to active' => ['retained', 'active'],
			'retained to suspended' => ['retained', 'suspended'],
			'retained to archived' => ['retained', 'archived'],
			'retained to provisioning' => ['retained', 'provisioning'],
			'provisioning to retained' => ['provisioning', 'retained'],
			'deprovisioning to retained' => ['deprovisioning', 'retained'],
			'archived to retained' => ['archived', 'retained'],
		];
	}

	public function testSuspendSetsStatusAndTimestamp(): void {
		$org = new Organisation();
		$org->setStatus('active');
		$org->setUuid('test-uuid');

		$this->organisationMapper->expects($this->once())
			->method('update')
			->willReturnCallback(function ($entity) {
				return $entity;
			});

		$result = $this->service->suspend($org);

		$this->assertEquals('suspended', $result->getStatus());
		$this->assertInstanceOf(DateTime::class, $result->getSuspendedAt());
	}

	public function testReactivateClearsSuspendedAt(): void {
		$org = new Organisation();
		$org->setStatus('suspended');
		$org->setUuid('test-uuid');
		$org->setSuspendedAt(new DateTime());

		$this->organisationMapper->expects($this->once())
			->method('update')
			->willReturnCallback(function ($entity) {
				return $entity;
			});

		$result = $this->service->reactivate($org);

		$this->assertEquals('active', $result->getStatus());
		$this->assertNull($result->getSuspendedAt());
	}

	public function testProvisionAddsTheAdminUserToBothDefaultGroups(): void {
		$org = new Organisation();
		$org->setStatus('provisioning');
		$org->setUuid('org-uuid');
		$org->setSlug('acme');

		$admin = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with('alice')
			->willReturn($admin);

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->expects($this->once())->method('addUser')->with($admin);
		$usersGroup = $this->createMock(IGroup::class);
		$usersGroup->expects($this->once())->method('addUser')->with($admin);

		$this->groupManager->method('groupExists')->willReturn(false);
		$this->groupManager->expects($this->exactly(2))->method('createGroup');
		$this->groupManager->method('get')
			->willReturnMap([
				['acme-admin', $adminGroup],
				['acme-users', $usersGroup],
			]);

		$this->organisationMapper->expects($this->once())
			->method('update')
			->willReturnCallback(fn ($entity) => $entity);

		$result = $this->service->provision($org, 'alice');

		$this->assertEquals('active', $result->getStatus());
		$this->assertSame(['acme-admin', 'acme-users'], $result->getGroups());
		$this->assertContains('alice', $result->getUsers());
	}

	public function testDeprovisionSetsStatusAndTimestamp(): void {
		$org = new Organisation();
		$org->setStatus('active');
		$org->setUuid('test-uuid');

		$this->organisationMapper->expects($this->once())
			->method('update')
			->willReturnCallback(function ($entity) {
				return $entity;
			});

		$result = $this->service->deprovision($org);

		$this->assertEquals('deprovisioning', $result->getStatus());
		$this->assertInstanceOf(DateTime::class, $result->getDeprovisionedAt());
	}

	public function testRetainSetsStatusAndRetainedAtButNotDeprovisionedAt(): void {
		$org = new Organisation();
		$org->setStatus('active');
		$org->setUuid('test-uuid');

		$this->organisationMapper->expects($this->once())
			->method('update')
			->willReturnCallback(function ($entity) {
				return $entity;
			});

		$result = $this->service->retain($org);

		$this->assertEquals('retained', $result->getStatus());
		$this->assertInstanceOf(DateTime::class, $result->getRetainedAt());
		// deprovisionedAt is the purge job's clock. Setting it here would start
		// the deletion window on an organisation that is meant to be kept.
		$this->assertNull($result->getDeprovisionedAt());
	}

	public function testRetainFromArchivedThrowsAndWritesNothing(): void {
		$org = new Organisation();
		$org->setStatus('archived');

		$this->organisationMapper->expects($this->never())->method('update');

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);

		$this->service->retain($org);
	}

	public function testReactivatingARetainedOrganisationThrows(): void {
		$org = new Organisation();
		$org->setStatus('retained');

		$this->organisationMapper->expects($this->never())->method('update');

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);

		$this->service->reactivate($org);
	}

	public function testDeprovisioningARetainedOrganisationStartsTheDeletionClock(): void {
		$org = new Organisation();
		$org->setStatus('retained');
		$org->setUuid('test-uuid');
		$org->setRetainedAt(new DateTime('2025-01-01'));

		$this->organisationMapper->expects($this->once())
			->method('update')
			->willReturnCallback(fn ($entity) => $entity);

		$result = $this->service->deprovision($org);

		$this->assertEquals('deprovisioning', $result->getStatus());
		$this->assertInstanceOf(DateTime::class, $result->getDeprovisionedAt());
		$this->assertEquals(new DateTime('2025-01-01'), $result->getRetainedAt(), 'the retention start stays on record');
	}

	public function testOnlyTheArchivedStateIsPurgeable(): void {
		$this->assertSame('archived', TenantLifecycleService::PURGEABLE_STATUS);
		$this->assertNotSame(TenantLifecycleService::STATUS_RETAINED, TenantLifecycleService::PURGEABLE_STATUS);
	}

	public function testSuspendFromArchivedThrowsException(): void {
		$org = new Organisation();
		$org->setStatus('archived');

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);

		$this->service->suspend($org);
	}

	public function testGetValidTransitions(): void {
		$this->assertEquals(['active'], $this->service->getValidTransitions('provisioning'));
		$this->assertEquals(['suspended', 'deprovisioning', 'retained'], $this->service->getValidTransitions('active'));
		$this->assertEquals(['active', 'deprovisioning', 'retained'], $this->service->getValidTransitions('suspended'));
		$this->assertEquals([], $this->service->getValidTransitions('archived'));
		$this->assertEquals(['deprovisioning'], $this->service->getValidTransitions('retained'));
		$this->assertTrue($this->service->isValidStatus('retained'));
	}

	public function testIsValidEnvironment(): void {
		$this->assertTrue($this->service->isValidEnvironment('development'));
		$this->assertTrue($this->service->isValidEnvironment('test'));
		$this->assertTrue($this->service->isValidEnvironment('acceptance'));
		$this->assertTrue($this->service->isValidEnvironment('production'));
		$this->assertFalse($this->service->isValidEnvironment('staging'));
	}

	public function testIsValidPromotionOrder(): void {
		$this->assertTrue($this->service->isValidPromotionOrder('development', 'test'));
		$this->assertTrue($this->service->isValidPromotionOrder('test', 'acceptance'));
		$this->assertTrue($this->service->isValidPromotionOrder('acceptance', 'production'));
		$this->assertTrue($this->service->isValidPromotionOrder('development', 'production'));

		// Invalid: reverse order.
		$this->assertFalse($this->service->isValidPromotionOrder('production', 'development'));
		$this->assertFalse($this->service->isValidPromotionOrder('test', 'development'));

		// Invalid: same environment.
		$this->assertFalse($this->service->isValidPromotionOrder('test', 'test'));
	}
}
