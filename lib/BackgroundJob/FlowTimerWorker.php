<?php

/**
 * Sweeps the business-timer store: fires due expiries and due escalation
 * rungs, once per interval, from persisted state alone.
 *
 * A business timer is decided by rows, never by a scheduled callback, a
 * sleep or an in-memory handle: this job wakes on the existing 300s cadence
 * ({@see FlowScheduleWorker} runs the same interval) and asks
 * {@see FlowTimerSweep} to process what the two index range scans return.
 * An instance down for a week fires what it missed, once, on the first pass
 * after it comes back. The job holds no state of its own.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one bounded sweep pass per tick.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface
 * is PHP's own conversion of the job clock.
 */
class FlowTimerWorker extends TimedJob {

	/**
	 * The sweep interval, matching FlowScheduleWorker. A business timer's
	 * resolution is days; 300s is already finer than anything it measures.
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 300;

	/**
	 * App-config key for the per-pass batch limit, and its default.
	 */
	public const CONFIG_BATCH = 'flow_timer_batch';

	public const DEFAULT_BATCH = 200;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Job scheduling clock (kept by the base Job as $this->time).
	 * @param FlowTimerSweep $sweep The two range scans and what they fire.
	 * @param IAppConfig $appConfig Holds the batch limit override.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FlowTimerSweep $sweep,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);

	}//end __construct()

	/**
	 * Run one sweep pass.
	 *
	 * Counts logged are work PERFORMED (timers fired, rungs raised), not rows
	 * examined, and a pass that hit the batch limit says `truncated: true` so
	 * a backlog is visible instead of looking like a clean sweep.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is TimedJob's signature.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	protected function run($argument): void {
		try {
			$now = DateTimeImmutable::createFromInterface($this->time->getDateTime());
			$result = $this->sweep->run(now: $now, batch: $this->batchLimit());

			if ($result['expiriesFired'] > 0 || $result['rungsFired'] > 0 || $result['truncated'] === true || $result['errors'] > 0) {
				$this->logger->info(
					message: sprintf(
						'[FlowTimerWorker] Fired %d expiry timer(s) and %d escalation rung(s); truncated: %s; errors: %d',
						$result['expiriesFired'],
						$result['rungsFired'],
						var_export($result['truncated'], true),
						$result['errors']
					),
					context: ['file' => __FILE__, 'line' => __LINE__] + $result
				);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowTimerWorker] Sweep pass failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);
		}//end try

	}//end run()

	/**
	 * The batch limit: configured, else the default; never below one.
	 *
	 * @return int The limit.
	 */
	private function batchLimit(): int {
		$configured = (int)$this->appConfig->getValueString(
			app: 'openregister',
			key: self::CONFIG_BATCH,
			default: (string)self::DEFAULT_BATCH
		);

		return max(1, $configured);
	}//end batchLimit()
}//end class
