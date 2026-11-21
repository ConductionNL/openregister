<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\Support\ScheduledJobRuntime;
use OCA\OpenRegister\BackgroundJob\Support\TenantRecords;
use OCA\OpenRegister\BackgroundJob\TenantUsageSyncJob;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsage;
use OCA\OpenRegister\Db\TenantUsage\UsageBucket;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Db\UsageMeasurement;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Characterisation test of TenantUsageSyncJob::run with APCu available.
 *
 * Needs the APCu extension with apc.enable_cli=1; skipped otherwise (the
 * no-APCu path is pinned in TenantJobsScopeTest).
 */
class TenantUsageSyncJobCharacterisationTest extends TestCase {

	protected function setUp(): void {
		if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
			$this->markTestSkipped('APCu with apc.enable_cli=1 is required');
		}

		apcu_clear_cache();
	}

	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/** @var array<int, array{0: string, 1: string, 2: array}> */
			public array $trail = [];

			public function log($level, $message, array $context = []): void {
				$this->trail[] = [$level, (string) $message, $context];
			}
		};
	}

	private function organisation(?string $uuid): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		return $organisation;
	}

	private function runJob(OrganisationMapper $organisations, TenantUsageMapper $usage, AbstractLogger $logger): void {
		$job = new TenantUsageSyncJob(
			new ScheduledJobRuntime($this->createMock(ITimeFactory::class), $this->createMock(IAppConfig::class), $logger),
			new TenantRecords($organisations, $usage, $this->createMock(TenantLifecycleServiceInterface::class))
		);
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	public function testQueryFailureIsLoggedAndStops(): void {
		$logger = $this->recordingLogger();
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->expects($this->once())->method('findLocalTenants')
			->with(50, 0, ['status' => TenantLifecycleServiceInterface::STATUS_ACTIVE])
			->willThrowException(new \RuntimeException('db down'));
		$usage = $this->createMock(TenantUsageMapper::class);
		$usage->expects($this->never())->method('upsertUsage');

		$this->runJob($organisations, $usage, $logger);

		$this->assertSame(
			[
				['debug', '[TenantUsageSyncJob] Starting usage sync', []],
				['error', '[TenantUsageSyncJob] Failed to query active organisations', ['error' => 'db down']],
			],
			$logger->trail
		);
	}

	public function testCountersAreFlushedPerOrganisation(): void {
		$hour = (new DateTime())->format('YmdH');
		apcu_store("or_quota_a_{$hour}", 5);
		apcu_store("or_bw_a_{$hour}", '700');
		apcu_store("or_bw_b_{$hour}", 9);
		apcu_store("or_quota_c_{$hour}", 3);
		apcu_store("or_quota_d_{$hour}", 0);

		$logger = $this->recordingLogger();
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('findLocalTenants')->willReturn(
			[
				$this->organisation('a'),
				$this->organisation(null),
				$this->organisation('b'),
				$this->organisation('c'),
				$this->organisation('d'),
				$this->organisation('e'),
			]
		);
		$written = [];
		$usage = $this->createMock(TenantUsageMapper::class);
		$usage->method('upsertUsage')->willReturnCallback(
			function (UsageBucket $bucket, UsageMeasurement $measurement) use (&$written) {
				$written[] = [
					$bucket->organisationUuid,
					$bucket->period->format('Y-m-d H:i:s'),
					$measurement->requestCount,
					$measurement->bandwidthBytes,
					$measurement->storageBytes,
				];
				if ($bucket->organisationUuid === 'c') {
					throw new \RuntimeException('write failed');
				}
				return new TenantUsage();
			}
		);

		$this->runJob($organisations, $usage, $logger);

		if ((new DateTime())->format('YmdH') !== $hour) {
			$this->markTestSkipped('The hour rolled over during the run');
		}

		$period = DateTime::createFromFormat('YmdH', $hour)->format('Y-m-d H') . ':00:00';
		$this->assertSame(
			[
				['a', $period, 5, 700, 0],
				['b', $period, 0, 9, 0],
				['c', $period, 3, 0, 0],
			],
			$written
		);
		$this->assertSame(
			[
				['debug', '[TenantUsageSyncJob] Starting usage sync', []],
				['error', '[TenantUsageSyncJob] Failed to sync usage for organisation', ['uuid' => 'c', 'error' => 'write failed']],
				['debug', '[TenantUsageSyncJob] Completed, synced 2 organisations', []],
			],
			$logger->trail
		);
	}
}
