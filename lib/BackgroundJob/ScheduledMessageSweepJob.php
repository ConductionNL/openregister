<?php

/**
 * Every five minutes, one bounded pass over the scheduled messages.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Service\Notification\ClaimAttempt;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageSweep;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs ScheduledMessageSweep and logs its counts, a zero included.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */
class ScheduledMessageSweepJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory          $time   The clock.
	 * @param ScheduledMessageSweep $sweep  The sweep.
	 * @param LoggerInterface       $logger Where the counts go.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ScheduledMessageSweep $sweep,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 300);
	}//end __construct()

	/**
	 * One pass, named by a worker id unique to this run.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob's signature.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	protected function run(mixed $argument): void {
		$now   = DateTimeImmutable::createFromInterface($this->time->getDateTime());
		$tally = $this->sweep->run(attempt: new ClaimAttempt(worker: uniqid('sweep-', true), at: $now));

		$this->logger->info(message: '[ScheduledMessageSweepJob] scheduled messages swept', context: $tally);
	}//end run()
}//end class
