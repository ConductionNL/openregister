<?php

/**
 * Tenant Deprovisioning Background Job
 *
 * Processes organisations in 'deprovisioning' state by soft-deleting their
 * objects and transitioning them to 'archived' state.
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
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;
use OCP\BackgroundJob\TimedJob;
use OCA\OpenRegister\BackgroundJob\Support\TenantRecords;
use OCA\OpenRegister\BackgroundJob\Support\ScheduledJobRuntime;
use Psr\Log\LoggerInterface;

/**
 * Processes deprovisioning organisations.
 *
 * @package OCA\OpenRegister\BackgroundJob
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class TenantDeprovisionJob extends TimedJob {
	/**
	 * Organisation mapper
	 *
	 * @var OrganisationMapperInterface
	 */
	private readonly OrganisationMapperInterface $organisationMapper;

	/**
	 * Lifecycle service
	 *
	 * @var TenantLifecycleServiceInterface
	 */
	private readonly TenantLifecycleServiceInterface $tenantLifecycleService;

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
		$this->tenantLifecycleService = $tenants->lifecycle;
		$this->logger = $runtime->logger;
		// Run every hour.
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Execute the background job.
	 *
	 * @param mixed $argument Job argument (unused)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-deprovisioned-organisations-must-transition-to-archived-with-data-retention
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	protected function run(mixed $argument): void {
		$this->logger->info('[TenantDeprovisionJob] Starting deprovisioning check');

		try {
			// Tenant-scoped read, not findAll: a federated counterparty is an
			// organisation in this table but NOT a tenant of this installation, and
			// selecting on status alone would sweep it up with the tenants.
			$organisations = $this->organisationMapper->findLocalTenants(
				filters: ['status' => TenantLifecycleServiceInterface::STATUS_DEPROVISIONING]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'[TenantDeprovisionJob] Failed to query deprovisioning organisations',
				['error' => $e->getMessage()]
			);
			return;
		}

		foreach ($organisations as $organisation) {
			try {
				// Transition to archived — actual data cleanup is handled by
				// the purge job after the retention period expires.
				$this->tenantLifecycleService->archive($organisation);

				$this->logger->info(
					'[TenantDeprovisionJob] Organisation archived',
					['uuid' => $organisation->getUuid()]
				);
			} catch (\Exception $e) {
				$this->logger->error(
					'[TenantDeprovisionJob] Failed to archive organisation',
					['uuid' => $organisation->getUuid(), 'error' => $e->getMessage()]
				);
			}
		}

		$this->logger->info(
			'[TenantDeprovisionJob] Completed, processed ' . count($organisations) . ' organisations'
		);
	}//end run()
}//end class
