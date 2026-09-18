<?php

/**
 * ConsistencyCheckJob: the consistency check, as an observable job.
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

use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

/**
 * Runs the read-only consistency check and stores what it found.
 *
 * The check is a read (D-5), so this job writes nothing to the data it
 * inspects. It does store the findings, in app configuration, so the console
 * can show the last result without re-running a full scan on every page load,
 * and so the support bundle can carry a result rather than a spinner.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-actions-run-as-observable-jobs-req-aoc-004
 */
class ConsistencyCheckJob extends RecordedQueuedJob {

	/**
	 * The app the last result is stored under.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * The setting holding the last result.
	 *
	 * @var string
	 */
	public const SETTING_LAST_RESULT = 'operations_consistency_last';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory            $time     Time factory for the parent job class.
	 * @param JobRunRecorder          $recorder The wrapper that writes the run row.
	 * @param ConsistencyCheckService $check    The read-only check.
	 * @param IConfig                 $config   Where the last result is stored.
	 */
	public function __construct(
		ITimeFactory $time,
		JobRunRecorder $recorder,
		private readonly ConsistencyCheckService $check,
		private readonly IConfig $config,
	) {
		parent::__construct(time: $time, recorder: $recorder);

	}//end __construct()

	/**
	 * Check, and remember what was found.
	 *
	 * @param mixed $argument The job argument: the actor, when a person asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
	 */
	protected function runRecorded(mixed $argument): void {
		$findings = $this->check->check();
		$findings['ranAt'] = (new \DateTime())->format(\DateTime::ATOM);

		$encoded = json_encode($findings);
		if ($encoded === false) {
			$encoded = '{}';
		}

		$this->config->setAppValue(
			self::APP_ID,
			self::SETTING_LAST_RESULT,
			$encoded
		);

	}//end runRecorded()
}//end class
