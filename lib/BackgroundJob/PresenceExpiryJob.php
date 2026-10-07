<?php

/**
 * Drop the readers whose beats stopped.
 *
 * 🔴 THE EXPIRY IS A SWEEP AND NOT A READ-TIME FILTER ALONE, AND BOTH EXIST ON
 * PURPOSE. `PresenceService::present()` already excludes anything outside the
 * window, so the LIST is correct without this job: nobody ever sees a reader
 * who left. What the list cannot do is push. A tab that was closed rather than
 * departed cleanly leaves a row that nothing will ever touch again, and the
 * other readers on that page are holding a list with a ghost on it until
 * something else happens to the object. This job is what turns that into a
 * departure they are told about.
 *
 * It also stops the table growing without bound, which the read-time filter
 * would never do on its own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\PresenceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Expire stale presence rows on a tick.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
 */
class PresenceExpiryJob extends TimedJob {

	/**
	 * How often the sweep runs, in seconds.
	 *
	 * 🔑 SHORTER THAN THE WINDOW IT ENFORCES. At 60 seconds against a 90-second
	 * window, a closed tab is gone from everybody's list within two and a half
	 * minutes at worst. A sweep at the window's own length would make the worst
	 * case three minutes and, worse, would tempt a reader into thinking the two
	 * numbers are the same thing.
	 *
	 * @var integer
	 */
	private const INTERVAL_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time     Time factory for TimedJob.
	 * @param PresenceService $presence The presence rows.
	 * @param LoggerInterface $logger   The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PresenceService $presence,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Expire what has gone quiet.
	 *
	 * 🔑 IT NEVER THROWS. A background job that raises is a job Nextcloud
	 * retries and eventually disables, and presence going stale is not worth
	 * losing the job over: the read-time filter still hides the ghosts.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the
	 * QueuedJob/TimedJob contract; this job sweeps on a clock and takes no
	 * argument.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
	 */
	protected function run($argument): void {
		try {
			$gone = $this->presence->expire();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PresenceExpiryJob] the presence sweep failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return;
		}

		if ($gone === []) {
			return;
		}

		$this->logger->debug(
			message: '[PresenceExpiryJob] expired ' . count($gone) . ' presence rows',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end run()
}//end class
