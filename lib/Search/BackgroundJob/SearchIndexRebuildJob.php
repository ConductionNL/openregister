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
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\RecordedJobRuntime;
use OCA\OpenRegister\BackgroundJob\RecordedQueuedJob;
use OCA\OpenRegister\Search\Service\Search\SearchIndexPass;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use RuntimeException;

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
 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-actions-run-as-observable-jobs-req-aoc-004
 */
class SearchIndexRebuildJob extends RecordedQueuedJob {

	/**
	 * Constructor.
	 *
	 * @param RecordedJobRuntime     $runtime The scheduler clock and the recorder that writes the run row.
	 * @param SearchIndexMaintenance $index   The rebuild itself.
	 */
	public function __construct(
		RecordedJobRuntime $runtime,
		private readonly SearchIndexMaintenance $index,
	) {
		parent::__construct(time: $runtime->time, recorder: $runtime->recorder);

	}//end __construct()

	/**
	 * Rebuild.
	 *
	 * @param mixed $argument The job argument: an optional register, and the actor.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the platform refuses the rebuild.
	 *
	 * @spec openspec/specs/operations-console/spec.md
	 */
	protected function runRecorded(mixed $argument): void {
		$pass   = new SearchIndexPass(apply: true, progress: null);
		$report = $this->index->rebuild(registerId: self::registerIdOf(argument: $argument), pass: $pass);

		self::assertRebuilt(report: $report);
	}//end runRecorded()

	/**
	 * The register a rebuild is limited to; null for every register.
	 *
	 * @param mixed $argument The job arguments.
	 *
	 * @return int|null
	 */
	private static function registerIdOf(mixed $argument): ?int {
		$registerId = null;

		if (is_array($argument) === true && isset($argument['registerId']) === true) {
			$registerId = (int)$argument['registerId'];
		}

		return $registerId;
	}//end registerIdOf()

	/**
	 * Fail the run when the rebuild was refused or left failed indexes.
	 *
	 * @param array<string, mixed> $report The rebuild report.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the rebuild did not succeed.
	 */
	private static function assertRebuilt(array $report): void {
		if (($report['state'] ?? null) === 'refused') {
			throw new RuntimeException((string)($report['reason'] ?? 'The rebuild was refused.'));
		}

		if ((int)($report['failed'] ?? 0) > 0) {
			throw new RuntimeException(
				'The rebuild finished with '.(int)$report['failed'].' failed index(es).'
			);
		}
	}//end assertRebuilt()
}//end class
