<?php

/**
 * Ticks the calendar flow triggers: queues a run for every moment now due.
 *
 * A calendar trigger has no event at the moment it fires, only a date on a
 * record, so this job wakes every five minutes and asks
 * {@see FlowCalendarService} to queue the runs of the moments that have come
 * due. The runs then go through {@see FlowRunWorker} like any other.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Cron
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Flow\FlowCalendarService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fires the calendar moments that are due, once per interval.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowCalendarWorker extends TimedJob {

	/**
	 * Constructor.
	 *
	 * Takes the clock and logger directly rather than the Core JobRuntime bundle,
	 * so this Processes job does not depend on the Core component.
	 *
	 * @param ITimeFactory        $time     Scheduler clock.
	 * @param LoggerInterface     $logger   Logger.
	 * @param FlowCalendarService $calendar Fires the due moments.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly FlowCalendarService $calendar,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 300);
	}//end __construct()

	/**
	 * Run one tick.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
	 */
	protected function run($argument): void {
		try {
			$queued = $this->calendar->fireDue(now: $this->time->getDateTime());
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowCalendarWorker] Calendar tick failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		if ($queued !== []) {
			$this->logger->info(
				message: '[FlowCalendarWorker] Queued ' . count($queued) . ' calendar flow run(s)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'runs' => $queued]
			);
		}
	}//end run()
}//end class
