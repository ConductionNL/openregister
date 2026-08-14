<?php

/**
 * Ticks the flow schedule — fires flows whose cron is due.
 *
 * The event triggers need no worker: an object write, a file upload or a tag
 * dispatches an event and the listener fires the flow inline. A schedule has no
 * event, only a time, so it needs something to notice that time has come. This
 * job is that something: it wakes periodically and asks {@see FlowScheduleService}
 * to queue a run for every scheduled flow now due. The queued runs are then
 * executed by {@see FlowRunWorker} like any other, so a scheduled flow shares the
 * whole run lifecycle (trace, retry, retention) with a triggered one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Cron;

use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fires the scheduled flows that are due, once per interval.
 */
class FlowScheduleWorker extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Job scheduling clock (kept by the base Job as $this->time).
	 * @param FlowScheduleService $schedule Decides which flows are due and fires them.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FlowScheduleService $schedule,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Every five minutes: the finest schedule this supports is "every
		// minute", and a five-minute tick keeps a per-minute cron within a few
		// minutes of its time without waking the instance every minute.
		$this->setInterval(seconds: 300);

	}//end __construct()

	/**
	 * Fire the scheduled flows that are due at this tick.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
	 */
	protected function run($argument): void {
		try {
			$fired = $this->schedule->fireDueFlows(now: $this->time->getDateTime());
			if ($fired !== []) {
				$this->logger->info(
					message: '[FlowScheduleWorker] Queued ' . count($fired) . ' scheduled flow run(s)',
					context: ['file' => __FILE__, 'line' => __LINE__, 'flows' => $fired]
				);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowScheduleWorker] Schedule tick failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}//end try

	}//end run()
}//end class
