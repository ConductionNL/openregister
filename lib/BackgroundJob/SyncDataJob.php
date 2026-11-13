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
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Sync\HarvestRun;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCA\OpenRegister\Service\Sync\SyncScheduleInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use OCA\OpenRegister\BackgroundJob\Support\SyncDataServices;

/**
 * Scheduled harvest driver.
 *
 * @package OCA\OpenRegister\BackgroundJob
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
class SyncDataJob extends TimedJob {
	/**
	 * Source persistence
	 *
	 * @var SourceMapperInterface
	 */
	private readonly SourceMapperInterface $sourceMapper;

	/**
	 * Due-source selection
	 *
	 * @var SyncScheduleInterface
	 */
	private readonly SyncScheduleInterface $scheduleService;

	/**
	 * Resolves transport per source type
	 *
	 * @var SourceFetcherLookupInterface
	 */
	private readonly SourceFetcherLookupInterface $fetcherRegistry;

	/**
	 * Pipeline orchestrator
	 *
	 * @var HarvestPipelineServiceInterface
	 */
	private readonly HarvestPipelineServiceInterface $pipeline;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                    $time            Time factory for scheduling
	 * @param SyncDataServices $services Sources, schedule, fetchers and harvest pipeline.
	 * @param LoggerInterface                 $logger          Logger
	 */
	public function __construct(
		ITimeFactory $time,
		SyncDataServices $services,
		private readonly LoggerInterface $logger,
	) {
		$this->sourceMapper = $services->sourceMapper;
		$this->scheduleService = $services->scheduleService;
		$this->fetcherRegistry = $services->fetcherRegistry;
		$this->pipeline = $services->pipeline;
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
	protected function run($argument): void {
		$this->logger->info(message: '[SyncDataJob] Starting data sync job');

		try {
			$sources = $this->jobSources(sources: $this->sourceMapper->findBySyncEnabled());
			$now = new DateTime();
			$due = $this->scheduleService->selectDueSources(sources: $sources, now: $now);

			$this->logger->info(
				message: sprintf('[SyncDataJob] %d source(s) enabled, %d due', count($sources), count($due))
			);

			foreach ($due as $source) {
				$this->syncSource(source: $source);
			}
		} catch (Throwable $e) {
			$this->logger->error(message: '[SyncDataJob] Data sync job failed: ' . $e->getMessage());
		}
	}//end run()

	/**
	 * The sources this job still schedules: those without a harvest flow.
	 *
	 * A source with a flowId is scheduled only by its flow's schedule trigger.
	 * The rest are named in one warning per run, so the list of sources still
	 * on this job (and so running with no acting identity) stays visible until
	 * each is given a schedule and runAs.
	 *
	 * @param Source[] $sources The sync-enabled sources.
	 *
	 * @return Source[] Those without a flow.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	private function jobSources(array $sources): array {
		$onJob = array_values(
			array_filter(
				$sources,
				static fn (Source $source): bool => ($source->getFlowId() === null || $source->getFlowId() === '')
			)
		);

		if ($onJob !== []) {
			$this->logger->warning(
				message: sprintf(
					'[SyncDataJob] %d source(s) are still scheduled by this job rather than a harvest flow: %s',
					count($onJob),
					implode(', ', array_map(static fn (Source $source): string => (string)$source, $onJob))
				)
			);
		}

		return $onJob;
	}//end jobSources()

	/**
	 * Sync a single source through the harvest pipeline.
	 *
	 * @param Source $source The source to sync
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	private function syncSource(Source $source): void {
		$fetcher = $this->fetcherFor(source: $source);
		if ($fetcher === null) {
			return;
		}

		// Overlap protection marker.
		$source->setLastSyncStatus('running');
		$this->sourceMapper->update($source);

		try {
			$summary = $this->harvest(source: $source, fetcher: $fetcher);
			$this->recordSynced(source: $source, status: (string)($summary['status'] ?? 'success'));
		} catch (Throwable $e) {
			$this->recordFailed(source: $source, error: $e);
		}
	}//end syncSource()

	/**
	 * The fetcher for a source's type, logging when there is none.
	 *
	 * @param Source $source The source to sync.
	 *
	 * @return SourceFetcherInterface|null
	 */
	private function fetcherFor(Source $source): ?SourceFetcherInterface {
		$type    = (string)$source->getType();
		$fetcher = $this->fetcherRegistry->get($type);
		if ($fetcher === null) {
			$this->logger->warning(
				message: sprintf('[SyncDataJob] No fetcher for source type "%s" (source %s)', $type, $source->getUuid())
			);
		}

		return $fetcher;
	}//end fetcherFor()

	/**
	 * Run one harvest of a source through the pipeline.
	 *
	 * @param Source                 $source  The source to sync.
	 * @param SourceFetcherInterface $fetcher The fetcher for its type.
	 *
	 * @return array The pipeline's summary.
	 */
	private function harvest(Source $source, SourceFetcherInterface $fetcher): array {
		$executionId = (string)Uuid::v4();

		return $this->pipeline->run(
			new HarvestRun(source: $source, fetcher: $fetcher, executionId: $executionId, since: self::syncSince(source: $source))
		);
	}//end harvest()

	/**
	 * Where a harvest resumes: the source's sync token, else its last sync date.
	 *
	 * @param Source $source The source to sync.
	 *
	 * @return string|null Null for a source never synced.
	 */
	private static function syncSince(Source $source): ?string {
		$since = null;
		if ($source->getLastSyncToken() !== null) {
			$since = $source->getLastSyncToken();
		} elseif ($source->getLastSyncDate() !== null) {
			$since = $source->getLastSyncDate()->format('c');
		}

		return $since;
	}//end syncSince()

	/**
	 * Record a finished harvest on the source.
	 *
	 * @param Source $source The synced source.
	 * @param string $status The pipeline's status.
	 *
	 * @return void
	 */
	private function recordSynced(Source $source, string $status): void {
		$source->setLastSyncStatus($status);
		$source->setLastSyncDate(new DateTime());
		$this->sourceMapper->update($source);

		$this->logger->info(
			message: sprintf('[SyncDataJob] Synced source %s: %s', $source->getUuid(), $status)
		);
	}//end recordSynced()

	/**
	 * Record a failed harvest on the source.
	 *
	 * @param Source    $source The source.
	 * @param Throwable $error  Why the harvest failed.
	 *
	 * @return void
	 */
	private function recordFailed(Source $source, Throwable $error): void {
		$source->setLastSyncStatus('failed');
		$source->setLastSyncDate(new DateTime());
		$this->sourceMapper->update($source);
		$this->logger->error(
			message: sprintf('[SyncDataJob] Source %s sync failed: %s', $source->getUuid(), $error->getMessage())
		);
	}//end recordFailed()
}//end class
