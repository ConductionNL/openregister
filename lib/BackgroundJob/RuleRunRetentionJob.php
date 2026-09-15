<?php

/**
 * OpenRegister RuleRunRetentionJob
 *
 * Prunes the rule run log daily, under its own configured period, and leaves
 * every summary row where it is.
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
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The daily prune of the rule run log.
 *
 * D-3. A rule that fires on every object save writes a row per save, so the
 * detail log is a first-class retention subject with its own period. The
 * summary is NOT pruned here and that is the whole point: after this job has
 * deleted a rule's last thousand rows, the inventory still reports when that
 * rule last ran and what it last errored with.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class RuleRunRetentionJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 * @param RuleRunMapper $runs Deletes expired detail rows.
	 * @param RuleRunRecorder $recorder Reads the configured retention period.
	 * @param LoggerInterface $logger Records what was swept.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly RuleRunMapper $runs,
		private readonly RuleRunRecorder $recorder,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: 86400);

	}//end __construct()

	/**
	 * Delete the detail rows past their period.
	 *
	 * @param mixed $argument The job argument, unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	protected function run($argument): void {
		$days = $this->recorder->retentionDays();

		try {
			$deleted = $this->runs->pruneBefore(before: new DateTime('-' . $days . ' days'));
		} catch (Throwable $e) {
			$this->logger->warning(
				'[RuleRunRetention] Sweep failed: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		if ($deleted === 0) {
			return;
		}

		$this->logger->info(
			'[RuleRunRetention] Removed ' . $deleted . ' rule run rows older than ' . $days . ' days.',
			['file' => __FILE__, 'line' => __LINE__]
		);
	}//end run()
}//end class
