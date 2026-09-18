<?php

/**
 * Counts the views whose alert is due, and says when one crosses.
 *
 * 🔴 IT COUNTS AS THE VIEW'S OWNER (ADR-099, design D-2). The sweep has no
 * session, and a count is a disclosure: a shared view alerts on what its OWNER
 * may see, never on rows the owner could not read. Counting as the system would
 * make a threshold on a view somebody shared into a way to learn how many
 * records exist behind a filter they are not entitled to.
 *
 * 🔴 IT FIRES ON THE CROSSING, NOT THE STATE. That decision lives in
 * {@see ViewAlert::decide()} so it is one rule with one test, and so this job
 * cannot quietly grow a second version of it.
 *
 * 🔑 BOUNDED AND FAIR. One pass takes at most BATCH views, oldest evaluation
 * first, so a thousand due views take five passes rather than one long one, and
 * none of them starves behind a busier neighbour.
 *
 * 🔑 IT NEVER THROWS. A background job that raises is one Nextcloud retries and
 * eventually disables; an alert that is one pass late is a smaller problem than
 * an alert that never runs again.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Event\ViewAlertCrossedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evaluates due view alerts, a bounded batch at a time.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
 */
class ViewAlertSweepJob extends TimedJob {

	/**
	 * Views evaluated per pass.
	 *
	 * A count is a query. Two hundred of them in one cron tick is a pass that
	 * finishes; a thousand is a tick that does not, and the views at the end of
	 * the list are the ones that never get evaluated.
	 *
	 * @var int
	 */
	public const BATCH = 200;

	/**
	 * How often a pass may run, in seconds.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time       Time factory for TimedJob.
	 * @param ViewMapper        $views      The saved views.
	 * @param ObjectService     $objects    Counts a view's query.
	 * @param IUserManager      $users      Resolves the owner to count as.
	 * @param IEventDispatcher  $dispatcher Announces a crossing.
	 * @param LoggerInterface   $logger     Diagnostics.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ViewMapper $views,
		private readonly ObjectService $objects,
		private readonly IUserManager $users,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Evaluate the next batch of due alerts.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the
	 * QueuedJob/TimedJob contract; this job sweeps on a clock and takes no
	 * argument.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
	 */
	protected function run($argument): void {
		try {
			$now = new DateTime();
			$crossed = 0;
			foreach ($this->views->findWithAlerts(limit: self::BATCH) as $view) {
				if ($this->evaluate(view: $view, now: $now) === true) {
					$crossed++;
				}
			}

			if ($crossed > 0) {
				$this->logger->info('[ViewAlertSweepJob] {count} view alerts crossed', ['count' => $crossed]);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ViewAlertSweepJob] The pass failed; the watermark stands: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);
		}//end try
	}//end run()

	/**
	 * Evaluate one view.
	 *
	 * @param View     $view The view.
	 * @param DateTime $now  The pass instant.
	 *
	 * @return bool True when this evaluation crossed the threshold.
	 */
	private function evaluate(View $view, DateTime $now): bool {
		try {
			$alert = ViewAlert::parse(raw: $view->getAlert());
		} catch (Throwable $e) {
			// A declaration that no longer reads is not a reason to stop the
			// pass, and not a reason to guess at what it meant.
			$this->logger->warning(
				'[ViewAlertSweepJob] View {view} has an unreadable alert and is skipped: {error}',
				['view' => (string)$view->getUuid(), 'error' => $e->getMessage()]
			);
			return false;
		}

		if ($alert === null) {
			return false;
		}

		$lastEvaluated = $view->getAlertEvaluatedAt()?->getTimestamp();
		if ($alert->isDue(lastEvaluated: $lastEvaluated, now: $now->getTimestamp()) === false) {
			return false;
		}

		$count = $this->countAsOwner(view: $view);
		if ($count === null) {
			return false;
		}

		$state = (string)(($view->getAlertState() ?? [])['state'] ?? ViewAlert::ARMED);
		$decision = $alert->decide(state: $state, count: $count);

		$view->setAlertState(
			[
				'state' => $decision['state'],
				'lastCount' => $count,
				'lastEvaluated' => $now->format('c'),
			]
		);
		$view->setAlertEvaluatedAt($now);
		$this->views->update($view);

		if ($decision['fires'] === false) {
			return false;
		}

		$this->dispatcher->dispatchTyped(new ViewAlertCrossedEvent(view: $view, alert: $alert, count: $count));

		return true;
	}//end evaluate()

	/**
	 * Count the view's query with the owner's own rights.
	 *
	 * A view whose owner no longer exists is skipped, not counted as the
	 * system: the alert belongs to a person, and with nobody to hold it there
	 * is nobody whose entitlement the count could be measured against.
	 *
	 * @param View $view The view.
	 *
	 * @return int|null The count, or null when it cannot be taken.
	 */
	private function countAsOwner(View $view): ?int {
		$owner = $this->users->get((string)$view->getOwner());
		if ($owner === null) {
			$this->logger->warning(
				'[ViewAlertSweepJob] View {view} has no resolvable owner, so its count has no entitlement to be measured against',
				['view' => (string)$view->getUuid()]
			);
			return null;
		}

		try {
			return (int)$this->objects->runAs(
				$owner,
				fn (): int => $this->objects->count(config: (array)($view->getQuery() ?? []))
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ViewAlertSweepJob] Could not count view {view}: {error}',
				['view' => (string)$view->getUuid(), 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end countAsOwner()
}//end class
