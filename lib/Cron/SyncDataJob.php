<?php

/**
 * OpenRegister Data Sync Job
 *
 * Hourly TimedJob that drives the harvest pipeline for every Source with
 * scheduled sync enabled. For each due source it runs the gather -> fetch ->
 * import pipeline and records the outcome on the Source (lastSyncDate,
 * lastSyncStatus). Mirrors SyncConfigurationsJob's interval-check structure
 * but delegates "is this source due?" to the testable SyncScheduleService
 * and the actual work to HarvestPipelineService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Cron;

use DateTime;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCA\OpenRegister\Service\Sync\SyncScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Scheduled harvest driver.
 *
 * @package OCA\OpenRegister\Cron
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
class SyncDataJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory           $time            Time factory for scheduling
     * @param SourceMapper           $sourceMapper    Source persistence
     * @param SyncScheduleService    $scheduleService Due-source selection
     * @param SourceFetcherRegistry  $fetcherRegistry Resolves transport per source type
     * @param HarvestPipelineService $pipeline        Pipeline orchestrator
     * @param LoggerInterface        $logger          Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly SourceMapper $sourceMapper,
        private readonly SyncScheduleService $scheduleService,
        private readonly SourceFetcherRegistry $fetcherRegistry,
        private readonly HarvestPipelineService $pipeline,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        // Run every hour; each source is gated by its own interval.
        $this->setInterval(seconds: 3600);
    }//end __construct()

    /**
     * Run the job: sync all due sources.
     *
     * @param mixed $argument Job arguments (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    protected function run($argument): void
    {
        $this->logger->info(message: '[SyncDataJob] Starting data sync job');

        try {
            $sources = $this->sourceMapper->findBySyncEnabled();
            $now     = new DateTime();
            $due     = $this->scheduleService->selectDueSources(sources: $sources, now: $now);

            $this->logger->info(
                message: sprintf('[SyncDataJob] %d source(s) enabled, %d due', count($sources), count($due))
            );

            foreach ($due as $source) {
                $this->syncSource(source: $source);
            }
        } catch (Throwable $e) {
            $this->logger->error(message: '[SyncDataJob] Data sync job failed: '.$e->getMessage());
        }
    }//end run()

    /**
     * Sync a single source through the harvest pipeline.
     *
     * @param Source $source The source to sync
     *
     * @return void
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    private function syncSource(Source $source): void
    {
        $type    = (string) $source->getType();
        $fetcher = $this->fetcherRegistry->get($type);
        if ($fetcher === null) {
            $this->logger->warning(
                message: sprintf('[SyncDataJob] No fetcher for source type "%s" (source %s)', $type, $source->getUuid())
            );
            return;
        }

        $executionId = (string) Uuid::v4();

        // Overlap protection marker.
        $source->setLastSyncStatus('running');
        $this->sourceMapper->update($source);

        try {
            $since = null;
            if ($source->getLastSyncToken() !== null) {
                $since = $source->getLastSyncToken();
            } else if ($source->getLastSyncDate() !== null) {
                $since = $source->getLastSyncDate()->format('c');
            }

            $summary = $this->pipeline->run(
                source: $source,
                fetcher: $fetcher,
                executionId: $executionId,
                since: $since
            );

            $source->setLastSyncStatus((string) ($summary['status'] ?? 'success'));
            $source->setLastSyncDate(new DateTime());
            $this->sourceMapper->update($source);

            $this->logger->info(
                message: sprintf('[SyncDataJob] Synced source %s: %s', $source->getUuid(), (string) ($summary['status'] ?? 'success'))
            );
        } catch (Throwable $e) {
            $source->setLastSyncStatus('failed');
            $source->setLastSyncDate(new DateTime());
            $this->sourceMapper->update($source);
            $this->logger->error(
                message: sprintf('[SyncDataJob] Source %s sync failed: %s', $source->getUuid(), $e->getMessage())
            );
        }//end try
    }//end syncSource()
}//end class
