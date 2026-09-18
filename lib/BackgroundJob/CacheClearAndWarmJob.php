<?php

/**
 * CacheClearAndWarmJob: clearing and warming the cache, as an observable job.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Clears every cache and warms the name cache again, leaving a run row.
 *
 * Clearing and warming are one act on purpose: a clear on its own leaves the
 * instance slow until something happens to warm it, and the administrator who
 * pressed the button has no way to tell whether that has happened yet. One job
 * with one outcome answers "is the cache back".
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-actions-run-as-observable-jobs-req-aoc-004
 */
class CacheClearAndWarmJob extends RecordedQueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory   $time     Time factory for the parent job class.
	 * @param JobRunRecorder $recorder The wrapper that writes the run row.
	 * @param CacheHandler   $cache    The caches being cleared and warmed.
	 */
	public function __construct(
		ITimeFactory $time,
		JobRunRecorder $recorder,
		private readonly CacheHandler $cache,
	) {
		parent::__construct(time: $time, recorder: $recorder);

	}//end __construct()

	/**
	 * Clear, then warm.
	 *
	 * @param mixed $argument The job argument: the actor, when a person asked.
	 *
	 * @return void
	 */
	protected function runRecorded(mixed $argument): void {
		$this->cache->clearAllCaches();
		$this->cache->warmupNameCache();

	}//end runRecorded()
}//end class
