<?php

/**
 * Arms again the statutory expiry timers that could not be armed when their item became active.
 *
 * Runs {@see CasePlanTimerRearm} once per interval, from the markers alone;
 * the job holds no state of its own. Its interval is the timer sweep's.
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Case\CasePlanTimerRearm;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one re-arm pass per tick.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface
 * is PHP's own conversion of the job clock.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
class CasePlanTimerRearmJob extends TimedJob {

	/**
	 * The interval, the same as the timer sweep it backs up.
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Job scheduling clock.
	 * @param CasePlanTimerRearm $rearm The pass.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CasePlanTimerRearm $rearm,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		// Two overlapping passes would enforce one expired term twice and race on the same markers.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run one pass; a failing pass is logged, never thrown.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is TimedJob's signature.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	protected function run($argument): void {
		try {
			$result = $this->rearm->run(now: DateTimeImmutable::createFromInterface($this->time->getDateTime()));
			if ($result !== ['armed' => 0, 'enforced' => 0, 'dropped' => 0, 'failed' => 0]) {
				$this->logger->info(
					message: sprintf(
						'[CasePlanTimerRearmJob] Armed %d statutory timer(s), enforced %d expired term(s), dropped %d marker(s); %d still failing',
						$result['armed'],
						$result['enforced'],
						$result['dropped'],
						$result['failed']
					),
					context: ['file' => __FILE__, 'line' => __LINE__] + $result
				);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[CasePlanTimerRearmJob] Re-arm pass failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);
		}//end try

	}//end run()
}//end class
