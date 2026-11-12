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
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Event\ViewAlertCrossedEvent;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use Throwable;
use OCA\OpenRegister\BackgroundJob\Support\ViewAlertCheck;
use OCA\OpenRegister\BackgroundJob\Support\ViewAlertSweepSources;
use OCA\OpenRegister\BackgroundJob\Support\ViewAlertSweepPlatform;

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
	 * @param ITimeFactory              $time       Time factory for TimedJob.
	 * @param ViewAlertSweepSources $sources Saved views, object counting and the view query.
	 * @param ViewAlertSweepPlatform $platform Users, event dispatcher and logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ViewAlertSweepSources $sources,
		private readonly ViewAlertSweepPlatform $platform,
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
			foreach ($this->sources->views->findWithAlerts(limit: self::BATCH) as $view) {
				if ($this->evaluate(view: $view, now: $now) === true) {
					$crossed++;
				}
			}

			if ($crossed > 0) {
				$this->platform->logger->info('[ViewAlertSweepJob] {count} view alerts crossed', ['count' => $crossed]);
			}
		} catch (Throwable $e) {
			$this->platform->logger->warning(
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
		$check        = new ViewAlertCheck(view: $view, now: $now);
		$check->alert = $this->dueAlert(check: $check);
		if ($check->alert === null || $this->takeCount(check: $check) === false) {
			return false;
		}

		if ($this->storeDecision(check: $check) === false) {
			return false;
		}

		$this->platform->dispatcher->dispatchTyped(new ViewAlertCrossedEvent(view: $view, alert: $check->alert, count: $check->count));

		return true;
	}//end evaluate()

	/**
	 * Take the view's count as its owner onto the check.
	 *
	 * @param ViewAlertCheck $check The view being checked.
	 *
	 * @return bool Whether a count could be taken.
	 */
	private function takeCount(ViewAlertCheck $check): bool {
		$count = $this->countAsOwner(check: $check);
		if ($count === null) {
			return false;
		}

		$check->count = $count;
		return true;
	}//end takeCount()

	/**
	 * The view's alert when it is declared, readable and due at the pass clock.
	 *
	 * @param ViewAlertCheck $check The view and the pass clock.
	 *
	 * @return ViewAlert|null
	 */
	private function dueAlert(ViewAlertCheck $check): ?ViewAlert {
		$alert = $this->parsedAlert(view: $check->view);
		if ($alert === null) {
			return null;
		}

		$lastEvaluated = $check->view->getAlertEvaluatedAt()?->getTimestamp();
		if ($alert->isDue(lastEvaluated: $lastEvaluated, now: $check->now->getTimestamp()) === false) {
			return null;
		}

		return $alert;
	}//end dueAlert()

	/**
	 * The view's alert declaration; null when it has none or it no longer reads.
	 *
	 * @param View $view The view.
	 *
	 * @return ViewAlert|null
	 */
	private function parsedAlert(View $view): ?ViewAlert {
		try {
			return ViewAlert::parse(raw: $view->getAlert());
		} catch (Throwable $e) {
			// A declaration that no longer reads is not a reason to stop the
			// pass, and not a reason to guess at what it meant.
			$this->platform->logger->warning(
				'[ViewAlertSweepJob] View {view} has an unreadable alert and is skipped: {error}',
				['view' => (string)$view->getUuid(), 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end parsedAlert()

	/**
	 * Decide the alert's new state from the count, and store it on the view.
	 *
	 * @param ViewAlertCheck $check The view, pass clock, due alert and count.
	 *
	 * @return bool Whether the alert fires.
	 */
	private function storeDecision(ViewAlertCheck $check): bool {
		$view     = $check->view;
		$state    = (string)(($view->getAlertState() ?? [])['state'] ?? ViewAlert::ARMED);
		$decision = $check->alert->decide(state: $state, count: $check->count);

		$view->setAlertState(
			[
				'state' => $decision['state'],
				'lastCount' => $check->count,
				'lastEvaluated' => $check->now->format('c'),
			]
		);
		$view->setAlertEvaluatedAt($check->now);
		$this->sources->views->update($view);

		return $decision['fires'] !== false;
	}//end storeDecision()

	/**
	 * Count the view's query with the owner's own rights.
	 *
	 * A view whose owner no longer exists is skipped, not counted as the
	 * system: the alert belongs to a person, and with nobody to hold it there
	 * is nobody whose entitlement the count could be measured against.
	 *
	 * @param ViewAlertCheck $check The view being checked.
	 *
	 * @return int|null The count, or null when it cannot be taken.
	 */
	private function countAsOwner(ViewAlertCheck $check): ?int {
		$check->owner = $this->ownerOf(view: $check->view);
		if ($check->owner === null) {
			return null;
		}

		// The VIEW's rows: its registers, schemas, filters and search terms.
		// ObjectService::count() reads none of those, only the service's ambient
		// register/schema context, which this sweep never sets (O5, 5 Oct).
		$query = $this->sources->viewQuery->forView(viewQuery: (array)($check->view->getQuery() ?? []));
		if ($query === null) {
			return 0;
		}

		if ($this->boundsNothing(view: $check->view, query: $query) === true) {
			return null;
		}

		return $this->countQuery(check: $check, query: $query);
	}//end countAsOwner()

	/**
	 * The view's owner; null, logged, when the owner no longer resolves.
	 *
	 * @param View $view The view.
	 *
	 * @return IUser|null
	 */
	private function ownerOf(View $view): ?IUser {
		$owner = $this->platform->users->get((string)$view->getOwner());
		if ($owner === null) {
			$this->platform->logger->warning(
				'[ViewAlertSweepJob] View {view} has no resolvable owner, so its count has no entitlement to be measured against',
				['view' => (string)$view->getUuid()]
			);
		}

		return $owner;
	}//end ownerOf()

	/**
	 * Whether a view's query names no register and no schema, logged when it does not.
	 *
	 * A view that names no register and no schema bounds nothing: the search
	 * answers 0 for it, and a silent 0 would re-arm a fired alert.
	 *
	 * @param View                 $view  The view.
	 * @param array<string, mixed> $query The view's search query.
	 *
	 * @return bool
	 */
	private function boundsNothing(View $view, array $query): bool {
		if (isset($query['@self']['register']) === false && isset($query['@self']['schema']) === false) {
			$this->platform->logger->warning(
				'[ViewAlertSweepJob] View {view} names no register or schema, so its alert has nothing to count',
				['view' => (string)$view->getUuid()]
			);
			return true;
		}

		return false;
	}//end boundsNothing()

	/**
	 * Count the query as the view's owner; null, logged, when the count fails.
	 *
	 * @param ViewAlertCheck       $check The view and its resolved owner.
	 * @param array<string, mixed> $query The view's search query.
	 *
	 * @return int|null
	 */
	private function countQuery(ViewAlertCheck $check, array $query): ?int {
		try {
			return (int)$this->sources->objects->runAs(
				$check->owner,
				fn (): int => $this->sources->objects->countSearchObjects(query: $query)
			);
		} catch (Throwable $e) {
			$this->platform->logger->warning(
				'[ViewAlertSweepJob] Could not count view {view}: {error}',
				['view' => (string)$check->view->getUuid(), 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end countQuery()
}//end class
