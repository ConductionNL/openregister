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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.UndefinedVariable)
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-usage-counters-must-be-persisted-via-background-job
	 * @spec openspec/specs/tenant-quotas/spec.md
	 */
	protected function run(mixed $argument): void {
		if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
			return;
		}

		$this->logger->debug('[TenantUsageSyncJob] Starting usage sync');

		try {
			// Tenant-scoped read, not findAll: a federated counterparty is an
			// organisation in this table but NOT a tenant of this installation, and
			// selecting on status alone would sweep it up with the tenants.
			$organisations = $this->organisationMapper->findLocalTenants(
				filters: ['status' => TenantLifecycleServiceInterface::STATUS_ACTIVE]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'[TenantUsageSyncJob] Failed to query active organisations',
				['error' => $e->getMessage()]
			);
			return;
		}

		$hourBucket = (new DateTime())->format('YmdH');
		$period = DateTime::createFromFormat('YmdH', $hourBucket);
		if ($period === false) {
			return;
		}

		$period->setTime((int)$period->format('H'), 0, 0);
		$syncedCount = 0;

		foreach ($organisations as $organisation) {
			$orgUuid = $organisation->getUuid();
			if ($orgUuid === null) {
				continue;
			}

			$requestKey = "or_quota_{$orgUuid}_{$hourBucket}";
			$bandwidthKey = "or_bw_{$orgUuid}_{$hourBucket}";

			$rawRequest = apcu_fetch($requestKey, $reqSuccess);
			$requestCount = 0;
			if ($reqSuccess === true) {
				$requestCount = (int)$rawRequest;
			}

			$rawBandwidth = apcu_fetch($bandwidthKey, $bwSuccess);
			$bandwidthBytes = 0;
			if ($bwSuccess === true) {
				$bandwidthBytes = (int)$rawBandwidth;
			}

			if ($requestCount === 0 && $bandwidthBytes === 0) {
				continue;
			}

			try {
				$this->tenantUsageMapper->upsertUsage(
					bucket: new UsageBucket(organisationUuid: $orgUuid, period: $period),
					measurement: new UsageMeasurement(requestCount: $requestCount, bandwidthBytes: $bandwidthBytes, storageBytes: 0)
				);
				$syncedCount++;
			} catch (\Exception $e) {
				$this->logger->error(
					'[TenantUsageSyncJob] Failed to sync usage for organisation',
					['uuid' => $orgUuid, 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		$this->logger->debug(
			'[TenantUsageSyncJob] Completed, synced ' . $syncedCount . ' organisations'
		);
	}//end run()
}//end class
