<?php

/**
 * OpenRegister Review Reminder Background Job
 *
 * Reminds each reviewer of the destruction list entries still waiting on them.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The reminder pass over every destruction list still under review.
 *
 * The review only happens if somebody is asked. A reminder every day is
 * ignored and a reminder once is missed, so the frequency is declared
 * configuration (`reviewReminderFrequency`, an ISO 8601 duration) and it is
 * both this job's interval and the age an entry must reach before it counts.
 *
 * ONE NOTIFICATION PER REVIEWER, NAMING A COUNT. A notification per entry on a
 * list of two hundred is a reminder nobody reads.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ReviewReminderJob extends TimedJob {

	/**
	 * How long an unanswered entry waits before its reviewer is reminded again.
	 */
	private const DEFAULT_FREQUENCY = 'P7D';

	/**
	 * The fallback interval, one week, when the declared frequency cannot be read.
	 */
	private const DEFAULT_INTERVAL = 604800;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time      Time factory for the parent class.
	 * @param ContainerInterface $container App container the job resolves its collaborators from.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: $this->intervalSeconds());
	}//end __construct()

	/**
	 * The declared reminder frequency, in seconds.
	 *
	 * @return int The interval.
	 */
	private function intervalSeconds(): int {
		try {
			$handler = $this->container->get(ObjectRetentionHandler::class);
			$settings = $handler->getArchivalSettingsOnly();
			$frequency = (string)($settings['reviewReminderFrequency'] ?? self::DEFAULT_FREQUENCY);

			$now = new DateTimeImmutable('@0');
			$then = $now->add(new DateInterval($frequency));
			$seconds = ($then->getTimestamp() - $now->getTimestamp());

			if ($seconds <= 0) {
				return self::DEFAULT_INTERVAL;
			}

			return $seconds;
		} catch (Exception $e) {
			return self::DEFAULT_INTERVAL;
		}//end try
	}//end intervalSeconds()

	/**
	 * Remind every reviewer holding work that has waited long enough.
	 *
	 * @param mixed $argument Job arguments, unused for timed jobs.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	protected function run($argument): void {
		$logger = $this->container->get(LoggerInterface::class);

		try {
			$lists = $this->container->get(DestructionListRepository::class);
			$reviews = $this->container->get(DestructionReviewService::class);

			if ($lists->isConfigured() === false) {
				$logger->debug('[ReviewReminderJob] No destruction list register configured, skipping');
				return;
			}

			$cutoff = (new DateTimeImmutable())->sub(new DateInterval($this->frequency()));

			$pending = [];
			foreach ($lists->findLists(statuses: DestructionListRepository::OPEN_STATUSES) as $list) {
				$pending = array_merge(
					$pending,
					$reviews->pendingEntries(
						listData: ($list->getObject() ?? []),
						listUuid: (string)$list->getUuid(),
						reviewer: null,
						waitingSince: $cutoff
					)
				);
			}

			$counts = $reviews->countByReviewer(entries: $pending);
			if ($counts === []) {
				$logger->debug('[ReviewReminderJob] No review entries are waiting');
				return;
			}

			foreach ($counts as $reviewer => $count) {
				$this->remind(reviewer: $reviewer, count: $count, logger: $logger);
			}

			$logger->info('[ReviewReminderJob] Reminded ' . count($counts) . ' reviewers');
		} catch (Exception $e) {
			$logger->error('[ReviewReminderJob] Error: ' . $e->getMessage(), ['exception' => $e]);
		}//end try
	}//end run()

	/**
	 * The declared frequency, as an ISO 8601 duration.
	 *
	 * @return string The duration.
	 */
	private function frequency(): string {
		try {
			$handler = $this->container->get(ObjectRetentionHandler::class);
			$settings = $handler->getArchivalSettingsOnly();
			$frequency = (string)($settings['reviewReminderFrequency'] ?? self::DEFAULT_FREQUENCY);

			// Refuse a duration DateInterval cannot read rather than letting it
			// throw out of the run and silence every reminder on the instance.
			new DateInterval($frequency);

			return $frequency;
		} catch (Exception $e) {
			return self::DEFAULT_FREQUENCY;
		}
	}//end frequency()

	/**
	 * Tell one reviewer how much is waiting on them.
	 *
	 * @param string          $reviewer The reviewer's user id.
	 * @param int             $count    How many entries are waiting.
	 * @param LoggerInterface $logger   Logger.
	 *
	 * @return void
	 */
	private function remind(string $reviewer, int $count, LoggerInterface $logger): void {
		try {
			$notificationManager = $this->container->get(INotificationManager::class);

			$notification = $notificationManager->createNotification();
			$notification->setApp('openregister')
				->setUser($reviewer)
				->setDateTime(new DateTime())
				->setObject('destruction_review', $reviewer)
				->setSubject(
					'destruction_review_pending',
					['pendingCount' => $count]
				);

			$notificationManager->notify($notification);
		} catch (Exception $e) {
			$logger->warning('[ReviewReminderJob] Could not remind ' . $reviewer . ': ' . $e->getMessage());
		}
	}//end remind()
}//end class
