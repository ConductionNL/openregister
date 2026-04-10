<?php

/**
 * Tenant Purge Background Job
 *
 * Permanently deletes archived organisations and their data after the
 * configured retention period (default: 90 days).
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use DateInterval;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Purges archived organisations after retention period.
 *
 * @package OCA\OpenRegister\BackgroundJob
 */
class TenantPurgeJob extends TimedJob
{
    /**
     * Default retention period in days.
     */
    private const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Constructor
     *
     * @param ITimeFactory       $time               Time factory
     * @param OrganisationMapper $organisationMapper Organisation mapper
     * @param TenantUsageMapper  $tenantUsageMapper  Usage mapper
     * @param IAppConfig         $appConfig          App config
     * @param LoggerInterface    $logger             Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly OrganisationMapper $organisationMapper,
        private readonly TenantUsageMapper $tenantUsageMapper,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
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
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('[TenantPurgeJob] Starting purge check');

        $retentionDays = (int) $this->appConfig->getValueString(
            'openregister',
            'tenantRetentionDays',
            (string) self::DEFAULT_RETENTION_DAYS
        );

        $cutoffDate = new DateTime();
        $cutoffDate->sub(new DateInterval("P{$retentionDays}D"));

        try {
            $organisations = $this->organisationMapper->findAll(
                filters: ['status' => TenantLifecycleService::STATUS_ARCHIVED]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[TenantPurgeJob] Failed to query archived organisations',
                ['error' => $e->getMessage()]
            );
            return;
        }

        $purgedCount = 0;
        foreach ($organisations as $organisation) {
            $deprovisionedAt = $organisation->getDeprovisionedAt();
            if ($deprovisionedAt === null) {
                continue;
            }

            if ($deprovisionedAt > $cutoffDate) {
                continue;
            }

            try {
                $orgUuid = $organisation->getUuid();

                // Delete usage records for this organisation.
                $this->tenantUsageMapper->deleteOlderThan(new DateTime('2099-12-31'));

                // Delete the organisation entity.
                $this->organisationMapper->delete($organisation);

                $this->logger->info(
                    '[TenantPurgeJob] Permanently deleted archived organisation',
                    ['uuid' => $orgUuid, 'deprovisionedAt' => $deprovisionedAt->format('c')]
                );

                $purgedCount++;
            } catch (\Exception $e) {
                $this->logger->error(
                    '[TenantPurgeJob] Failed to purge organisation',
                    ['uuid' => $organisation->getUuid(), 'error' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        $this->logger->info(
            '[TenantPurgeJob] Completed, purged '.$purgedCount.' organisations'
        );
    }//end run()
}//end class
