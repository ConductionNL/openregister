<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\TenantDeprovisionJob;
use OCA\OpenRegister\BackgroundJob\TenantPurgeJob;
use OCA\OpenRegister\BackgroundJob\TenantUsageSyncJob;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * What the three tenant background jobs consider a tenant.
 *
 * PINNING TESTS, written before the behaviour changes. They exist because a
 * scoping mistake here does not throw: `TenantPurgeJob` PERMANENTLY DELETES the
 * organisation entity, and it selects rows by `status` alone. Nothing in
 * `Organisation` distinguishes a tenant of THIS installation from a
 * counterparty that is a tenant of another one — so once ketenpartners live in
 * the same table, an archived partner is indistinguishable from an archived
 * tenant and gets deleted with it.
 *
 * They were written against the OLD behaviour first, and two of them FAILED
 * when the distinction landed — which is the point of writing them first. They
 * now assert the guarantee instead: the jobs read through
 * `findLocalTenants()`, whose name is the contract, and a counterparty is never
 * among the rows they act on.
 */
class TenantJobsScopeTest extends TestCase {

	/**
	 * Build an organisation.
	 *
	 * @param string      $uuid            Its uuid.
	 * @param string      $status          Its lifecycle status.
	 * @param string|null $deprovisionedAt When it was deprovisioned.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(string $uuid, string $status, ?string $deprovisionedAt = null): Organisation {
		$org = new Organisation();
		$org->setUuid($uuid);
		$org->setStatus($status);
		$org->setName('Org ' . $uuid);
		if ($deprovisionedAt !== null) {
			$org->setDeprovisionedAt(new DateTime($deprovisionedAt));
		}

		return $org;

	}//end organisation()

	/**
	 * Invoke a job's protected run().
	 *
	 * @param object $job The job.
	 *
	 * @return void
	 */
	private function runJob(object $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

	}//end runJob()

	/**
	 * The purge job reads through the tenant-scoped path.
	 *
	 * It still filters by status — that part is unchanged — but it can no longer
	 * see a counterparty at all, because `findLocalTenants()` excludes one
	 * before the status filter is applied. The mapper is mocked here, so what
	 * this pins is that the job ASKS the right question; that the query answers
	 * it correctly is pinned by OrganisationMapperTenantScopeTest.
	 *
	 * @return void
	 */
	public function testPurgeReadsThroughTheTenantScopedPath(): void {
		$seenFilters = [];
		$deleted = [];

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findLocalTenants')->willReturnCallback(
			function (int $limit = 50, int $offset = 0, ?array $filters = []) use (&$seenFilters): array {
				$seenFilters = ($filters ?? []);

				return [$this->organisation('partner-1', 'archived', '2020-01-01')];
			}
		);
		$mapper->method('delete')->willReturnCallback(
			static function (Organisation $org) use (&$deleted): Organisation {
				$deleted[] = $org->getUuid();

				return $org;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('30');

		$job = new TenantPurgeJob(
			$this->createMock(ITimeFactory::class),
			$mapper,
			$this->createMock(TenantUsageMapper::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);

		$this->runJob($job);

		$this->assertSame(['status' => TenantLifecycleService::STATUS_ARCHIVED], $seenFilters);
		$this->assertSame(['partner-1'], $deleted, 'a LOCAL TENANT that is archived is still purged');

	}//end testPurgeReadsThroughTheTenantScopedPath()

	/**
	 * The purge job spares a row that has not been deprovisioned.
	 *
	 * @return void
	 */
	public function testPurgeSparesARowWithNoDeprovisionedAt(): void {
		$deleted = [];
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findLocalTenants')->willReturn([$this->organisation('org-1', 'archived')]);
		$mapper->method('delete')->willReturnCallback(
			static function (Organisation $org) use (&$deleted): Organisation {
				$deleted[] = $org->getUuid();

				return $org;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('30');

		$this->runJob(
			new TenantPurgeJob(
				$this->createMock(ITimeFactory::class),
				$mapper,
				$this->createMock(TenantUsageMapper::class),
				$appConfig,
				$this->createMock(LoggerInterface::class),
			)
		);

		$this->assertSame([], $deleted);

	}//end testPurgeSparesARowWithNoDeprovisionedAt()

	/**
	 * The purge job spares a row still inside the retention window.
	 *
	 * @return void
	 */
	public function testPurgeSparesARowInsideRetention(): void {
		$deleted = [];
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findLocalTenants')->willReturn(
			[$this->organisation('org-1', 'archived', (new DateTime())->format('c'))]
		);
		$mapper->method('delete')->willReturnCallback(
			static function (Organisation $org) use (&$deleted): Organisation {
				$deleted[] = $org->getUuid();

				return $org;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('30');

		$this->runJob(
			new TenantPurgeJob(
				$this->createMock(ITimeFactory::class),
				$mapper,
				$this->createMock(TenantUsageMapper::class),
				$appConfig,
				$this->createMock(LoggerInterface::class),
			)
		);

		$this->assertSame([], $deleted);

	}//end testPurgeSparesARowInsideRetention()

	/**
	 * The deprovision job reads through the tenant-scoped path too.
	 *
	 * @return void
	 */
	public function testDeprovisionReadsThroughTheTenantScopedPath(): void {
		$seenFilters = [];
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findLocalTenants')->willReturnCallback(
			function (int $limit = 50, int $offset = 0, ?array $filters = []) use (&$seenFilters): array {
				$seenFilters = ($filters ?? []);

				return [];
			}
		);

		$this->runJob(
			new TenantDeprovisionJob(
				$this->createMock(ITimeFactory::class),
				$mapper,
				$this->createMock(TenantLifecycleService::class),
				$this->createMock(LoggerInterface::class),
			)
		);

		$this->assertSame(['status' => TenantLifecycleService::STATUS_DEPROVISIONING], $seenFilters);

	}//end testDeprovisionReadsThroughTheTenantScopedPath()

	/**
	 * The usage-sync job does nothing at all without APCu.
	 *
	 * Pinned as the honest current behaviour rather than asserting a filter the
	 * test environment cannot reach: the job returns before it queries anything
	 * when `apcu_enabled()` is false, which it is here. Asserting the filter
	 * would have been a test that passes because the code never ran.
	 *
	 * @return void
	 */
	public function testUsageSyncDoesNothingWithoutApcu(): void {
		if (function_exists('apcu_enabled') === true && apcu_enabled() === true) {
			$this->markTestSkipped('APCu is enabled here, so the early return under test cannot be observed.');
		}

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->expects($this->never())->method('findLocalTenants');

		$this->runJob(
			new TenantUsageSyncJob(
				$this->createMock(ITimeFactory::class),
				$mapper,
				$this->createMock(TenantUsageMapper::class),
				$this->createMock(LoggerInterface::class),
			)
		);

		$this->addToAssertionCount(1);

	}//end testUsageSyncDoesNothingWithoutApcu()

}//end class
