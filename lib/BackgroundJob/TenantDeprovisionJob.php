<?php

/**
 * Tenant Deprovisioning Background Job
 *
 * Processes organisations in 'deprovisioning' state by soft-deleting their
 * objects and transitioning them to 'archived' state.
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
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-75
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Processes deprovisioning organisations.
 *
 * @package OCA\OpenRegister\BackgroundJob
 */
class TenantDeprovisionJob extends TimedJob
{
    /**
     * Constructor
     *
     * @param ITimeFactory           $time                   Time factory
     * @param OrganisationMapper     $organisationMapper     Organisation mapper
     * @param TenantLifecycleService $tenantLifecycleService Lifecycle service
     * @param LoggerInterface        $logger                 Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly OrganisationMapper $organisationMapper,
        private readonly TenantLifecycleService $tenantLifecycleService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
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
     * @spec openspec/changes/retrofit-2026-04-28-tenant-lifecycle/tasks.md#task-1
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-75
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('[TenantDeprovisionJob] Starting deprovisioning check');

        try {
            $organisations = $this->organisationMapper->findAll(
                filters: ['status' => TenantLifecycleService::STATUS_DEPROVISIONING]
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
            '[TenantDeprovisionJob] Completed, processed '.count($organisations).' organisations'
        );
    }//end run()
}//end class
