<?php

/**
 * ReindexSelectionJob: the recorded run of an administrator's reindex.
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
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Operations\JobRunProgressInterface;
use OCA\OpenRegister\Service\Operations\ReindexSelectionServiceInterface;

/**
 * Reindexes the selection an administrator posted, writing its progress onto
 * its own run row.
 *
 * The run row is what `GET /api/operations/runs` lists, so its details carry
 * `total`, `processed`, `failed` and the failed ids with their reasons,
 * written at the start, after every 100 objects and at the end. A run whose
 * objects partly failed still completes: the failures are named in the row,
 * and only a run that could not start at all is a failed run.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
 */
class ReindexSelectionJob extends RecordedQueuedJob {

	/**
	 * Constructor.
	 *
	 * @param RecordedJobRuntime               $runtime The scheduler clock and the recorder that writes the run row.
	 * @param ReindexSelectionServiceInterface $reindex The reindex.
	 */
	public function __construct(
		RecordedJobRuntime $runtime,
		private readonly ReindexSelectionServiceInterface $reindex,
	) {
		parent::__construct(time: $runtime->time, recorder: $runtime->recorder);

	}//end __construct()

	/**
	 * Run the stored selection and report its progress on the run row.
	 *
	 * @param mixed $argument `{"run": "<key>", "actor": "<uid>"}`, as the service queued it.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the argument does not name the run and its administrator.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
	 */
	protected function runRecorded(mixed $argument): void {
		$runKey = is_array($argument) === true ? ($argument['run'] ?? null) : null;
		$actor = is_array($argument) === true ? ($argument['actor'] ?? null) : null;

		if (is_string($runKey) === false || $runKey === '' || is_string($actor) === false || $actor === '') {
			throw new InvalidArgumentException('A reindex run names its run key and the administrator who started it.');
		}

		$this->reindex->run(
			runKey: $runKey,
			actor: $actor,
			onProgress: function (array $details): void {
				$this->reportProgress(details: $details);
			}
		);

	}//end runRecorded()

	/**
	 * Write the progress onto this run's row, when the recorder can.
	 *
	 * @param array<string, mixed> $details The progress.
	 *
	 * @return void
	 */
	private function reportProgress(array $details): void {
		if ($this->recorder instanceof JobRunProgressInterface) {
			$this->recorder->progress(jobClass: static::class, details: $details);
		}

	}//end reportProgress()
}//end class
