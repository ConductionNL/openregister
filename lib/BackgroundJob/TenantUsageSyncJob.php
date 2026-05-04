<?php

/**
 * Tenant Usage Sync Background Job
 *
 * Flushes APCu-based quota counters to the openregister_tenant_usage database
 * table for persistence, dashboard display, and historical tracking.
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
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-80
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Syncs APCu counters to database for usage tracking.
 *
 * @package OCA\OpenRegister\BackgroundJob
 */
class TenantUsageSyncJob extends TimedJob
{
    /**
     * Constructor
     *
     * @param ITimeFactory       $time               Time factory
     * @param OrganisationMapper $organisationMapper Organisation mapper
     * @param TenantUsageMapper  $tenantUsageMapper  Usage mapper
     * @param LoggerInterface    $logger             Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly OrganisationMapper $organisationMapper,
        private readonly TenantUsageMapper $tenantUsageMapper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
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
     * @spec openspec/changes/retrofit-2026-04-28-tenant-isolation-audit/tasks.md#task-2
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-80
     */
    protected function run(mixed $argument): void
    {
        if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
            return;
        }

        $this->logger->debug('[TenantUsageSyncJob] Starting usage sync');

        try {
            $organisations = $this->organisationMapper->findAll(
                filters: ['status' => TenantLifecycleService::STATUS_ACTIVE]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[TenantUsageSyncJob] Failed to query active organisations',
                ['error' => $e->getMessage()]
            );
            return;
        }

        $hourBucket = (new DateTime())->format('YmdH');
        $period     = DateTime::createFromFormat('YmdH', $hourBucket);
        if ($period === false) {
            return;
        }

        $period->setTime((int) $period->format('H'), 0, 0);
        $syncedCount = 0;

        foreach ($organisations as $organisation) {
            $orgUuid = $organisation->getUuid();
            if ($orgUuid === null) {
                continue;
            }

            $requestKey   = "or_quota_{$orgUuid}_{$hourBucket}";
            $bandwidthKey = "or_bw_{$orgUuid}_{$hourBucket}";

            $requestCount   = apcu_fetch($requestKey, $reqSuccess);
            $requestCount   = ($reqSuccess === true) ? (int) $requestCount : 0;
            $bandwidthBytes = apcu_fetch($bandwidthKey, $bwSuccess);
            $bandwidthBytes = ($bwSuccess === true) ? (int) $bandwidthBytes : 0;

            if ($requestCount === 0 && $bandwidthBytes === 0) {
                continue;
            }

            try {
                $this->tenantUsageMapper->upsertUsage(
                    $orgUuid,
                    $period,
                    (int) $requestCount,
                    (int) $bandwidthBytes,
                    0
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
            '[TenantUsageSyncJob] Completed, synced '.$syncedCount.' organisations'
        );
    }//end run()
}//end class
