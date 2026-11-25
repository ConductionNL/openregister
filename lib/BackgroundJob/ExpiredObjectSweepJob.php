<?php

/**
 * ExpiredObjectSweepJob: the daily expired-object sweep.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Retention\ExpiredObjectSweeper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Runs the expired-object sweep once a day, unless `expiry_sweep_enabled` is off.
 */
class ExpiredObjectSweepJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time    The clock.
	 * @param ExpiredObjectSweeper $sweeper The sweep.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ExpiredObjectSweeper $sweeper,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Run the sweep when it is switched on.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	protected function run($argument): void {
		if ($this->sweeper->isEnabled() === false) {
			return;
		}

		$this->sweeper->sweep(now: new DateTimeImmutable('@'.$this->time->getTime()));
	}//end run()
}//end class
