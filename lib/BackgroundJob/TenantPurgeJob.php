<?php

/**
 * Tenant Purge Background Job
 *
 * Permanently deletes archived organisations and their data after the
 * configured retention period (default: 90 days).
 *
 * Only an organisation in TenantLifecycleServiceInterface::PURGEABLE_STATUS is ever
 * deleted. A `retained` organisation, whose access ended but whose data must
 * be kept, is outside this job's reach by construction.
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

use DateInterval;
use DateTime;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\TenantUsageMapperInterface;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;
use OCP\BackgroundJob\TimedJob;
use OCA\OpenRegister\BackgroundJob\Support\TenantRecords;
use OCA\OpenRegister\BackgroundJob\Support\ScheduledJobRuntime;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Purges archived organisations after retention period.
 *
 * @package OCA\OpenRegister\BackgroundJob
 */
class TenantPurgeJob extends TimedJob {
	/**
	 * Default retention period in days.
	 */
	private const DEFAULT_RETENTION_DAYS = 90;

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
	 * App config
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

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
		$this->appConfig = $runtime->config;
		$this->logger = $runtime->logger;
		// Run daily.
		$this->setInterval(seconds: 86400);
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
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-purge-must-touch-only-the-organisation-it-purges
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-terminated-organisation-must-be-able-to-keep-its-data-in-the-retained-state
	 */
	protected function run(mixed $argument): void {
		$this->logger->info('[TenantPurgeJob] Starting purge check');

		$retentionDays = (int)$this->appConfig->getValueString(
			'openregister',
			'tenantRetentionDays',
			(string)self::DEFAULT_RETENTION_DAYS
		);

		$cutoffDate = new DateTime();
		$cutoffDate->sub(new DateInterval("P{$retentionDays}D"));

		$organisations = $this->archivedTenants();
		if ($organisations === null) {
			return;
		}

		$purgedCount = 0;
		foreach ($organisations as $organisation) {
			if ($this->purgeIfDue(organisation: $organisation, cutoffDate: $cutoffDate) === true) {
				$purgedCount++;
			}
		}

		$this->logger->info(
			'[TenantPurgeJob] Completed, purged ' . $purgedCount . ' organisations'
		);
	}//end run()

	/**
	 * The archived organisations of this installation, or null when the read failed.
	 *
	 * @return array<int, Organisation>|null The candidates.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-purge-must-touch-only-the-organisation-it-purges
	 */
	private function archivedTenants(): ?array {
		try {
			// Tenant-scoped read, not findAll: a federated counterparty is an
			// organisation in this table but NOT a tenant of this installation, and
			// selecting on status alone would sweep it up with the tenants.
			return $this->organisationMapper->findLocalTenants(
				filters: ['status' => TenantLifecycleServiceInterface::PURGEABLE_STATUS]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'[TenantPurgeJob] Failed to query archived organisations',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end archivedTenants()

	/**
	 * Purge one organisation when it is archived, past retention and has a uuid.
	 *
	 * @param Organisation $organisation The candidate.
	 * @param DateTime     $cutoffDate   Deprovisioned at or before this is past retention.
	 *
	 * @return bool True when it was purged.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-purge-must-touch-only-the-organisation-it-purges
	 */
	private function purgeIfDue(Organisation $organisation, DateTime $cutoffDate): bool {
		$deprovisionedAt = $this->dueSince(organisation: $organisation, cutoffDate: $cutoffDate);
		if ($deprovisionedAt === null) {
			return false;
		}

		// Every delete below is scoped by this uuid. Without one there is
		// nothing to scope by, so the organisation is left alone.
		$orgUuid = $organisation->getUuid();
		if ($orgUuid === null || $orgUuid === '') {
			$this->logger->error('[TenantPurgeJob] Skipped an archived organisation without a uuid');
			return false;
		}

		return $this->purge(organisation: $organisation, deprovisionedAt: $deprovisionedAt);
	}//end purgeIfDue()

	/**
	 * When the organisation was deprovisioned, if it is archived and past retention.
	 *
	 * @param Organisation $organisation The candidate.
	 * @param DateTime     $cutoffDate   Deprovisioned at or before this is past retention.
	 *
	 * @return DateTime|null The deprovisioning moment, or null when the row must be spared.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-deprovisioned-organisations-must-transition-to-archived-with-data-retention
	 */
	private function dueSince(Organisation $organisation, DateTime $cutoffDate): ?DateTime {
		// The query above selects on the status, and this re-checks it on the
		// row itself. The query is the only thing between a retained
		// organisation and a permanent delete, so a filter that was dropped
		// or ignored must not be enough to delete one.
		if ($organisation->getStatus() !== TenantLifecycleServiceInterface::PURGEABLE_STATUS) {
			$this->logger->warning(
				'[TenantPurgeJob] Skipped an organisation that is not in the purgeable state',
				['uuid' => $organisation->getUuid(), 'status' => $organisation->getStatus()]
			);
			return null;
		}

		$deprovisionedAt = $organisation->getDeprovisionedAt();
		if ($deprovisionedAt === null || $deprovisionedAt > $cutoffDate) {
			return null;
		}

		return $deprovisionedAt;
	}//end dueSince()

	/**
	 * Delete the organisation's usage records and the organisation itself.
	 *
	 * @param Organisation $organisation    The organisation to purge; its non-empty uuid scopes every delete.
	 * @param DateTime     $deprovisionedAt When it was deprovisioned, for the log.
	 *
	 * @return bool True when both deletes succeeded.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-purge-must-touch-only-the-organisation-it-purges
	 */
	private function purge(Organisation $organisation, DateTime $deprovisionedAt): bool {
		$orgUuid = (string)$organisation->getUuid();
		try {
			// Delete this organisation's usage records, and only this one's.
			$this->tenantUsageMapper->deleteByOrganisation(organisationUuid: $orgUuid);

			// Delete the organisation entity.
			$this->organisationMapper->delete($organisation);

			$this->logger->info(
				'[TenantPurgeJob] Permanently deleted archived organisation',
				['uuid' => $orgUuid, 'deprovisionedAt' => $deprovisionedAt->format('c')]
			);

			return true;
		} catch (\Exception $e) {
			$this->logger->error(
				'[TenantPurgeJob] Failed to purge organisation',
				['uuid' => $organisation->getUuid(), 'error' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end purge()
}//end class
