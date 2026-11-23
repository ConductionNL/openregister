<?php

/**
 * OpenRegister SourceHarvester
 *
 * Runs one source's harvest pipeline under the source's lock, for the harvest flow step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use DateTime;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\SourceHarvesterInterface;
use OCA\OpenRegister\Kernel\Contracts\SourceHarvestRefused;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Looks the source up, checks it can run, holds its lock, runs the pipeline and records the
 * last sync status and date on the source.
 */
class SourceHarvester implements SourceHarvesterInterface {

	/**
	 * Constructor.
	 *
	 * @param SourceMapperInterface<Source>   $sources  The sources.
	 * @param SourceFetcherLookupInterface    $fetchers The fetcher per source type.
	 * @param HarvestPipelineServiceInterface $pipeline Runs the harvest.
	 * @param ILockingProvider                $locks    One harvest per source at a time.
	 * @param LoggerInterface                 $logger   Logs a last-sync write that failed.
	 */
	public function __construct(
		private readonly SourceMapperInterface $sources,
		private readonly SourceFetcherLookupInterface $fetchers,
		private readonly HarvestPipelineServiceInterface $pipeline,
		private readonly ILockingProvider $locks,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The lock a source's harvest holds.
	 *
	 * @param string $sourceUuid The source uuid.
	 *
	 * @return string
	 */
	public static function lockKey(string $sourceUuid): string {
		return 'openregister/harvest/'.$sourceUuid;
	}//end lockKey()

	/**
	 * Harvest one source.
	 *
	 * @param string $sourceId    The uuid or id of the source.
	 * @param string $executionId The run the harvest belongs to.
	 *
	 * @return array<string, mixed> The pipeline's summary.
	 *
	 * @throws SourceHarvestRefused When the source cannot run now.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function harvest(string $sourceId, string $executionId): array {
		$source  = $this->sources->findForSystem(sourceId: $sourceId);
		$fetcher = $this->runnable(source: $source);

		$key = self::lockKey(sourceUuid: (string)$source->getUuid());
		try {
			$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new SourceHarvestRefused(reason: SourceHarvestRefused::ALREADY_RUNNING, isError: false);
		}

		try {
			return $this->run(source: $source, fetcher: $fetcher, executionId: $executionId);
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}//end harvest()

	/**
	 * The fetcher of a source that can run, or the refusal.
	 *
	 * @param Source|null $source The source.
	 *
	 * @return SourceFetcherInterface
	 *
	 * @throws SourceHarvestRefused When the source is missing or disabled, or has no fetcher or mapping.
	 *
	 * @psalm-assert Source $source
	 */
	private function runnable(?Source $source): SourceFetcherInterface {
		if ($source === null || $source->getSyncEnabled() === false) {
			throw new SourceHarvestRefused(reason: SourceHarvestRefused::DISABLED, isError: true);
		}

		$fetcher = $this->fetchers->get((string)$source->getType());
		if ($fetcher === null) {
			throw new SourceHarvestRefused(reason: SourceHarvestRefused::FETCHER_MISSING, isError: true);
		}

		if ($source->getMappingId() === null) {
			throw new SourceHarvestRefused(reason: SourceHarvestRefused::MAPPING_MISSING, isError: true);
		}

		return $fetcher;
	}//end runnable()

	/**
	 * Run the pipeline and record the outcome on the source.
	 *
	 * @param Source                 $source      The source.
	 * @param SourceFetcherInterface $fetcher     Its fetcher.
	 * @param string                 $executionId The run.
	 *
	 * @return array<string, mixed>
	 */
	private function run(Source $source, SourceFetcherInterface $fetcher, string $executionId): array {
		try {
			$summary = $this->pipeline->run(
				new HarvestRun(source: $source, fetcher: $fetcher, executionId: $executionId, since: $source->getLastSyncToken())
			);
		} catch (Throwable $e) {
			$this->record(source: $source, status: 'failed');
			throw $e;
		}

		$this->record(source: $source, status: (string)($summary['status'] ?? 'success'));

		return $summary;
	}//end run()

	/**
	 * Record the last sync status and date; a failed write is logged, never thrown.
	 *
	 * @param Source $source The source.
	 * @param string $status The status.
	 *
	 * @return void
	 */
	private function record(Source $source, string $status): void {
		$source->setLastSyncStatus($status);
		$source->setLastSyncDate(new DateTime());
		try {
			$this->sources->update($source);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[HarvestSourceNode] Could not record the last sync on source '.$source->getUuid().': '.$e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end record()
}//end class
