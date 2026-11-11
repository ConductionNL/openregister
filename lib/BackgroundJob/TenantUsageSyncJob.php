<?php

/**
 * Tenant Usage Sync Background Job
 *
 * Flushes APCu-based quota counters to the openregister_tenant_usage database
 * table for persistence, dashboard display, and historical tracking.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/tenant-quotas/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\TenantUsage\UsageBucket;
use OCA\OpenRegister\Db\TenantUsageMapperInterface;
use OCA\OpenRegister\Db\UsageMeasurement;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;
use OCP\BackgroundJob\TimedJob;
use OCA\OpenRegister\BackgroundJob\Support\TenantRecords;
use OCA\OpenRegister\BackgroundJob\Support\ScheduledJobRuntime;
use Psr\Log\LoggerInterface;

/**
 * Syncs APCu counters to database for usage tracking.
 *
 * @package OCA\OpenRegister\BackgroundJob
 */
class TenantUsageSyncJob extends TimedJob {
	/**
	 * Organisation mapper
	 *
	 * @var OrganisationMapperInterface
	 */
	private readonly OrganisationMapperInterface $organisationMapper;

	/**
	 * Usage mapper
	 *
	 * @var TenantUsageMapperInterface
	 */
	private readonly TenantUsageMapperInterface $tenantUsageMapper;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param ScheduledJobRuntime $runtime The clock, app config and log the job runs on.
	 * @param TenantRecords       $tenants The organisations, their usage rows and lifecycle.
	 */
	public function __construct(ScheduledJobRuntime $runtime, TenantRecords $tenants) {
		parent::__construct(time: $runtime->time);
		$this->organisationMapper = $tenants->organisations;
		$this->tenantUsageMapper = $tenants->usage;
		$this->logger = $runtime->logger;
		// Run every 5 minutes.
		$this->setInterval(seconds: 300);
	}//end __construct()

	/**
	 * Execute the background job: flush APCu counters to database.
	 *
	 * @param mixed $argument Job argument (unused)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-usage-counters-must-be-persisted-via-background-job
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	protected function run(mixed $argument): void {
		if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
			return;
		}

		$this->logger->debug('[TenantUsageSyncJob] Starting usage sync');

		$organisations = $this->activeTenants();
		if ($organisations === null) {
			return;
		}

		$this->syncOrganisations(organisations: $organisations);
	}//end run()

	/**
	 * The active tenants of this installation, or null when the query failed.
	 *
	 * @return array<int, Organisation>|null
	 */
	private function activeTenants(): ?array {
		try {
			// Tenant-scoped read, not findAll: a federated counterparty is an
			// organisation in this table but NOT a tenant of this installation, and
			// selecting on status alone would sweep it up with the tenants.
			return $this->organisationMapper->findLocalTenants(
				filters: ['status' => TenantLifecycleServiceInterface::STATUS_ACTIVE]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'[TenantUsageSyncJob] Failed to query active organisations',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end activeTenants()

	/**
	 * Flush the current hour's counters of every organisation and log the total.
	 *
	 * @param array<int, Organisation> $organisations The active tenants
	 *
	 * @return void
	 */
	private function syncOrganisations(array $organisations): void {
		$period = $this->currentHourPeriod();
		if ($period === null) {
			return;
		}

		$syncedCount = 0;
		foreach ($organisations as $organisation) {
			$syncedCount += $this->syncOrganisation(organisation: $organisation, period: $period);
		}

		$this->logger->debug(
			'[TenantUsageSyncJob] Completed, synced ' . $syncedCount . ' organisations'
		);
	}//end syncOrganisations()

	/**
	 * The start of the current hour, or null when it cannot be parsed back.
	 *
	 * @return DateTime|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function currentHourPeriod(): ?DateTime {
		$hourBucket = (new DateTime())->format('YmdH');
		$period = DateTime::createFromFormat('YmdH', $hourBucket);
		if ($period === false) {
			return null;
		}

		$period->setTime((int)$period->format('H'), 0, 0);
		return $period;
	}//end currentHourPeriod()

	/**
	 * Flush one organisation's counters for the period.
	 *
	 * @param Organisation $organisation The tenant
	 * @param DateTime     $period       The start of the hour being flushed
	 *
	 * @return int 1 when a usage row was written, 0 otherwise
	 */
	private function syncOrganisation(Organisation $organisation, DateTime $period): int {
		$orgUuid = $organisation->getUuid();
		if ($orgUuid === null) {
			return 0;
		}

		$measurement = $this->readCounters(orgUuid: $orgUuid, hourBucket: $period->format('YmdH'));
		if ($measurement->requestCount === 0 && $measurement->bandwidthBytes === 0) {
			return 0;
		}

		return $this->writeUsage(
			bucket: new UsageBucket(organisationUuid: $orgUuid, period: $period),
			measurement: $measurement
		);
	}//end syncOrganisation()

	/**
	 * Read an organisation's request and bandwidth counters of one hour.
	 *
	 * @param string $orgUuid    The organisation uuid
	 * @param string $hourBucket The hour as YmdH
	 *
	 * @return UsageMeasurement
	 */
	private function readCounters(string $orgUuid, string $hourBucket): UsageMeasurement {
		$requestCount = $this->fetchCounter(key: "or_quota_{$orgUuid}_{$hourBucket}");
		$bandwidthBytes = $this->fetchCounter(key: "or_bw_{$orgUuid}_{$hourBucket}");

		return new UsageMeasurement(requestCount: $requestCount, bandwidthBytes: $bandwidthBytes, storageBytes: 0);
	}//end readCounters()

	/**
	 * Read one APCu counter, 0 when it is not set.
	 *
	 * @param string $key The APCu key
	 *
	 * @return int
	 *
	 * @SuppressWarnings(PHPMD.UndefinedVariable)
	 */
	private function fetchCounter(string $key): int {
		$raw = apcu_fetch($key, $success);
		if ($success === true) {
			return (int)$raw;
		}

		return 0;
	}//end fetchCounter()

	/**
	 * Write a usage row, logging (never throwing) a failure.
	 *
	 * @param UsageBucket      $bucket      The organisation and hour
	 * @param UsageMeasurement $measurement The counters read
	 *
	 * @return int 1 when the row was written, 0 when the write failed
	 */
	private function writeUsage(UsageBucket $bucket, UsageMeasurement $measurement): int {
		try {
			$this->tenantUsageMapper->upsertUsage(bucket: $bucket, measurement: $measurement);
			return 1;
		} catch (\Exception $e) {
			$this->logger->error(
				'[TenantUsageSyncJob] Failed to sync usage for organisation',
				['uuid' => $bucket->organisationUuid, 'error' => $e->getMessage()]
			);
			return 0;
		}
	}//end writeUsage()
}//end class
