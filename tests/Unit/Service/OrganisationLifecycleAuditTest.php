<?php

/**
 * An organisation's lifecycle on the audit trail.
 *
 * Suspending, reactivating, ending, retaining or archiving an organisation, and
 * editing its name, description or slug, each write one sealed entry keyed by
 * the organisation's uuid, so its history can be read back from the trail.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\Organisation\OrganisationLifecycleAuditor;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationUsageService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
class OrganisationLifecycleAuditTest extends TestCase {

	private AuditTrailMapper&MockObject $trail;

	private OrganisationMapper&MockObject $organisations;

	/** @var array<int, AuditTrail> */
	private array $written = [];

	private OrganisationLifecycleAuditor $auditor;

	protected function setUp(): void {
		parent::setUp();

		$this->trail = $this->createMock(AuditTrailMapper::class);
		$this->trail->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				$this->written = array_merge($this->written, $entries);
				return $entries;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('getDisplayName')->willReturn('Marieke Visser');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->auditor = new OrganisationLifecycleAuditor($this->trail, $session, new NullLogger());

		$this->organisations = $this->createMock(OrganisationMapper::class);
		$this->organisations->method('update')->willReturnArgument(0);
		$this->organisations->method('save')->willReturnArgument(0);
	}//end setUp()

	/**
	 * An organisation in a given status.
	 *
	 * @param string $status The status.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(string $status): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setName('Gemeente Waterland');
		$organisation->setDescription('Gemeente');
		$organisation->setSlug('waterland');
		$organisation->setStatus($status);
		return $organisation;
	}//end organisation()

	/**
	 * @return TenantLifecycleService The service with the auditor.
	 */
	private function lifecycle(): TenantLifecycleService {
		return new TenantLifecycleService(
			$this->organisations,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IEventDispatcher::class),
			new NullLogger(),
			$this->auditor
		);
	}//end lifecycle()

	/**
	 * @dataProvider transitions
	 *
	 * @param string $method The lifecycle method.
	 * @param string $from   The status before.
	 * @param string $to     The status after.
	 */
	public function testEachTransitionWritesOneEntry(string $method, string $from, string $to): void {
		$this->lifecycle()->{$method}($this->organisation($from));

		$this->assertCount(1, $this->written);
		$entry = $this->written[0];
		$this->assertSame('org-1', $entry->getObjectUuid());
		$this->assertSame(OrganisationLifecycleAuditor::ACTION_STATUS, $entry->getAction());
		$this->assertSame(['status' => ['old' => $from, 'new' => $to]], $entry->getChanged());
		$this->assertSame('admin', $entry->getUser());
		$this->assertSame('Marieke Visser', $entry->getUserName());
	}//end testEachTransitionWritesOneEntry()

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function transitions(): array {
		return [
			'suspend'     => ['suspend', 'active', 'suspended'],
			'reactivate'  => ['reactivate', 'suspended', 'active'],
			'deprovision' => ['deprovision', 'active', 'deprovisioning'],
			'retain'      => ['retain', 'active', 'retained'],
			'archive'     => ['archive', 'deprovisioning', 'archived'],
		];
	}//end transitions()

	public function testARefusedTransitionWritesNothing(): void {
		try {
			$this->lifecycle()->reactivate($this->organisation('archived'));
		} catch (\Exception $e) {
			// The transition is refused; only the trail matters here.
		}

		$this->assertSame([], $this->written);
	}//end testARefusedTransitionWritesNothing()

	public function testAnEditWritesTheFieldsThatChanged(): void {
		$organisation = $this->organisation('active');
		$service = $this->createMock(OrganisationService::class);
		$service->method('hasAccessToOrganisation')->willReturn(true);
		$service->method('isOrganisationAdmin')->willReturn(true);
		$this->organisations->method('findByUuid')->willReturn($organisation);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['name' => 'Gemeente Waterland-Noord', 'description' => 'Gemeente']);

		$controller = new OrganisationController(
			'openregister',
			$request,
			$service,
			$this->organisations,
			new NullLogger(),
			$this->createMock(TenantLifecycleService::class),
			$this->createMock(TenantUsageMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(OrganisationUsageService::class),
			null,
			$this->auditor
		);

		$response = $controller->update('org-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $this->written);
		$this->assertSame(OrganisationLifecycleAuditor::ACTION_UPDATED, $this->written[0]->getAction());
		$this->assertSame(
			[
				'name' => ['old' => 'Gemeente Waterland', 'new' => 'Gemeente Waterland-Noord'],
				'slug' => ['old' => 'waterland', 'new' => 'gemeente-waterland-noord'],
			],
			$this->written[0]->getChanged()
		);
	}//end testAnEditWritesTheFieldsThatChanged()

	public function testAFailingTrailDoesNotFailTheChange(): void {
		$trail = $this->createMock(AuditTrailMapper::class);
		$trail->method('insertAuditTrails')->willThrowException(new RuntimeException('down'));
		$auditor = new OrganisationLifecycleAuditor($trail, $this->createMock(IUserSession::class), new NullLogger());

		$auditor->recordStatus($this->organisation('suspended'), 'active');

		$this->addToAssertionCount(1);
	}//end testAFailingTrailDoesNotFailTheChange()
}//end class
