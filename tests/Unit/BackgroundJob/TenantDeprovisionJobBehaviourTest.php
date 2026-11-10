<?php

/**
 * TenantDeprovisionJob archives every deprovisioning tenant and contains each failure.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\TenantDeprovisionJob;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of the hourly deprovisioning run.
 */
class TenantDeprovisionJobBehaviourTest extends TestCase {

	/**
	 * @var OrganisationMapperInterface&MockObject
	 */
	private OrganisationMapperInterface $mapper;

	/**
	 * @var TenantLifecycleServiceInterface&MockObject
	 */
	private TenantLifecycleServiceInterface $lifecycle;

	/**
	 * Every log call: [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $log = [];

	private TenantDeprovisionJob $job;

	protected function setUp(): void {
		$this->mapper    = $this->createMock(OrganisationMapperInterface::class);
		$this->lifecycle = $this->createMock(TenantLifecycleServiceInterface::class);
		$logger          = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		$this->job = new TenantDeprovisionJob(new \OCA\OpenRegister\BackgroundJob\Support\ScheduledJobRuntime($this->createMock(ITimeFactory::class), $this->createMock(\OCP\IAppConfig::class), $logger), new \OCA\OpenRegister\BackgroundJob\Support\TenantRecords($this->mapper, $this->createMock(\OCA\OpenRegister\Db\TenantUsageMapperInterface::class), $this->lifecycle));
	}

	private function runJob(): void {
		(new ReflectionMethod($this->job, 'run'))->invoke($this->job, null);
	}

	private function organisation(string $uuid): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		return $organisation;
	}

	public function testRunsHourly(): void {
		$this->assertSame(3600, $this->job->getInterval());
	}

	public function testOnlyLocalTenantsInDeprovisioningAreArchived(): void {
		$first  = $this->organisation('t1');
		$second = $this->organisation('t2');
		$this->mapper->expects($this->once())->method('findLocalTenants')
			->with(50, 0, ['status' => TenantLifecycleServiceInterface::STATUS_DEPROVISIONING])
			->willReturn([$first, $second]);
		$archived = [];
		$this->lifecycle->method('archive')->willReturnCallback(
			function (Organisation $organisation) use (&$archived): Organisation {
				$archived[] = $organisation->getUuid();
				return $organisation;
			}
		);

		$this->runJob();

		$this->assertSame(['t1', 't2'], $archived);
		$this->assertSame(
			[
				['info', '[TenantDeprovisionJob] Starting deprovisioning check', []],
				['info', '[TenantDeprovisionJob] Organisation archived', ['uuid' => 't1']],
				['info', '[TenantDeprovisionJob] Organisation archived', ['uuid' => 't2']],
				['info', '[TenantDeprovisionJob] Completed, processed 2 organisations', []],
			],
			$this->log
		);
	}

	public function testQueryFailureIsLoggedAndNothingIsArchived(): void {
		$this->mapper->method('findLocalTenants')->willThrowException(new RuntimeException('db down'));
		$this->lifecycle->expects($this->never())->method('archive');

		$this->runJob();

		$this->assertSame(
			[
				['info', '[TenantDeprovisionJob] Starting deprovisioning check', []],
				['error', '[TenantDeprovisionJob] Failed to query deprovisioning organisations', ['error' => 'db down']],
			],
			$this->log
		);
	}

	public function testAnArchiveFailureIsLoggedAndTheNextTenantIsStillArchived(): void {
		$this->mapper->method('findLocalTenants')->willReturn([$this->organisation('bad'), $this->organisation('good')]);
		$archived = [];
		$this->lifecycle->method('archive')->willReturnCallback(
			function (Organisation $organisation) use (&$archived): Organisation {
				if ($organisation->getUuid() === 'bad') {
					throw new RuntimeException('cannot transition');
				}

				$archived[] = $organisation->getUuid();
				return $organisation;
			}
		);

		$this->runJob();

		$this->assertSame(['good'], $archived);
		$this->assertContains(
			['error', '[TenantDeprovisionJob] Failed to archive organisation', ['uuid' => 'bad', 'error' => 'cannot transition']],
			$this->log
		);
		$this->assertSame(['info', '[TenantDeprovisionJob] Completed, processed 2 organisations', []], end($this->log));
	}

	public function testNoDeprovisioningTenantsCompletesWithZero(): void {
		$this->mapper->method('findLocalTenants')->willReturn([]);
		$this->lifecycle->expects($this->never())->method('archive');

		$this->runJob();

		$this->assertSame(['info', '[TenantDeprovisionJob] Completed, processed 0 organisations', []], end($this->log));
	}
}
