<?php

/**
 * SearchIndexRebuildJob: the index rebuild, as an observable job.
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

use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Search\SearchIndexMaintenance;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Rebuilds the search index and leaves a run row behind.
 *
 * D-4: rebuilding is a long operation that can fail, and as a button that
 * returns 200 it tells nobody what happened. As a job it lands on the same
 * list, with the same outcome and the same failure, as everything else the
 * instance does in the background.
 *
 * The rebuild refuses itself on a platform without a concurrent reindex, and
 * that refusal is a report, not a throwable. It is re-thrown here so the run
 * row records a failure: a refused rebuild that reported `completed` would be
 * a console saying the index was rebuilt when it was not.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-actions-run-as-observable-jobs-req-aoc-004
 */
class SearchIndexRebuildJob extends RecordedQueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time     Time factory for the parent job class.
	 * @param JobRunRecorder         $recorder The wrapper that writes the run row.
	 * @param SearchIndexMaintenance $index    The rebuild itself.
	 */
	public function __construct(
		ITimeFactory $time,
		JobRunRecorder $recorder,
		private readonly SearchIndexMaintenance $index,
	) {
		parent::__construct(time: $time, recorder: $recorder);

	}//end __construct()

	/**
	 * Rebuild.
	 *
	 * @param mixed $argument The job argument: an optional register, and the actor.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the platform refuses the rebuild.
	 */
	protected function runRecorded(mixed $argument): void {
		$registerId = null;

		if (is_array($argument) === true && isset($argument['registerId']) === true) {
			$registerId = (int)$argument['registerId'];
		}

		$report = $this->index->rebuild(registerId: $registerId, apply: true);

		if (($report['state'] ?? null) === 'refused') {
			throw new \RuntimeException((string)($report['reason'] ?? 'The rebuild was refused.'));
		}

		if ((int)($report['failed'] ?? 0) > 0) {
			throw new \RuntimeException(
				'The rebuild finished with '.(int)$report['failed'].' failed index(es).'
			);
		}

	}//end runRecorded()
}//end class
