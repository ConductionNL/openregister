<?php

/**
 * Characterisation tests for OrganisationController::usage().
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
use OCA\OpenRegister\Db\TenantUsage\UsageRange;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationUsageService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\OrganisationControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the usage report: the current-hour counters (APCu is not loaded in the
 * unit run, so they are zero), the quotas, the utilisation for a missing, a zero
 * and a positive quota, the 30-day history window, and the 500 on a failure.
 *
 * The clock is not pinned: the hour bucket is checked for its shape and the
 * history window for its length, both independent of when the test runs.
 */
class OrganisationControllerUsageTest extends TestCase {

	private OrganisationMapper&MockObject $organisationMapper;

	private TenantUsageMapper&MockObject $tenantUsageMapper;

	private OrganisationController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->tenantUsageMapper = $this->createMock(TenantUsageMapper::class);

		$this->controller = OrganisationControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(OrganisationService::class),
			$this->organisationMapper,
			$this->createMock(LoggerInterface::class),
			$this->createMock(TenantLifecycleService::class),
			$this->tenantUsageMapper,
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(OrganisationUsageService::class)
		);
	}

	private function organisation(?int $requests, ?int $bandwidth, ?int $storage): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setRequestQuota($requests);
		$organisation->setBandwidthQuota($bandwidth);
		$organisation->setStorageQuota($storage);

		return $organisation;
	}

	public function testReportsQuotasHistoryAndZeroCountersWithoutApcu(): void {
		$this->assertFalse(function_exists('apcu_enabled') === true && apcu_enabled() === true, 'APCu must be off in the unit run');
		$this->organisationMapper->expects($this->once())->method('findByUuid')->with('uuid-in')
			->willReturn($this->organisation(1000, 0, 50));

		$record = $this->createMock(\JsonSerializable::class);
		$record->method('jsonSerialize')->willReturn(['period' => 'p1']);
		$this->tenantUsageMapper->expects($this->once())->method('findByOrgAndDateRange')
			->willReturnCallback(function (string $orgUuid, UsageRange $range) use ($record): array {
				$this->assertSame('org-1', $orgUuid);
				$days = (int) $range->from->diff($range->to)->format('%a');
				$this->assertSame(30, $days);
				return [$record, $record];
			});

		$response = $this->controller->usage('uuid-in');

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(['current', 'quota', 'utilization', 'history'], array_keys($data));
		$this->assertSame(['requests', 'bandwidth', 'period'], array_keys($data['current']));
		$this->assertSame(0, $data['current']['requests']);
		$this->assertSame(0, $data['current']['bandwidth']);
		$this->assertMatchesRegularExpression('/^\d{10}$/', $data['current']['period']);
		$this->assertSame(['requests' => 1000, 'bandwidth' => 0, 'storage' => 50], $data['quota']);
		// A positive quota gives a rounded percentage; a zero quota gives none.
		$this->assertSame(['requests' => 0.0, 'bandwidth' => null], $data['utilization']);
		$this->assertSame([['period' => 'p1'], ['period' => 'p1']], $data['history']);
	}

	public function testAMissingQuotaHasNoUtilisation(): void {
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation(null, null, null));
		$this->tenantUsageMapper->method('findByOrgAndDateRange')->willReturn([]);

		$data = $this->controller->usage('uuid-in')->getData();

		$this->assertSame(['requests' => null, 'bandwidth' => null, 'storage' => null], $data['quota']);
		$this->assertSame(['requests' => null, 'bandwidth' => null], $data['utilization']);
		$this->assertSame([], $data['history']);
	}

	public function testAPositiveBandwidthQuotaHasAUtilisation(): void {
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation(0, 7, null));
		$this->tenantUsageMapper->method('findByOrgAndDateRange')->willReturn([]);

		$data = $this->controller->usage('uuid-in')->getData();

		$this->assertSame(['requests' => null, 'bandwidth' => 0.0], $data['utilization']);
	}

	public function testAFailureAnswers500WithTheMessage(): void {
		$this->organisationMapper->method('findByUuid')->willThrowException(new \RuntimeException('no such organisation'));
		$this->tenantUsageMapper->expects($this->never())->method('findByOrgAndDateRange');

		$response = $this->controller->usage('uuid-in');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'no such organisation'], $response->getData());
	}

	public function testAHistoryFailureAnswers500(): void {
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation(1, 1, 1));
		$this->tenantUsageMapper->method('findByOrgAndDateRange')->willThrowException(new \Exception('db'));

		$response = $this->controller->usage('uuid-in');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'db'], $response->getData());
	}
}//end class
