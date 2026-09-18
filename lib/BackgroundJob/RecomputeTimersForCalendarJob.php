<?php

/**
 * Re-project the timers a changed calendar governs — off the write (ADR-078).
 *
 * 🔑 THE LISTENER ONLY QUEUES (D-2). Recomputing can touch thousands of timers,
 * and doing it inline would make an administrator's Save on a calendar page
 * wait for every deadline in the instance. This job does the work, in batches,
 * with the pair (slug, object version) as its idempotency key.
 *
 * 🔴 IT IS A QUEUED JOB, NOT A TIMED ONE. A timed sweep would have to ask "has
 * any calendar changed since last time", which is the question nothing could
 * answer — that is the row's own finding, "there is no single place a calendar
 * change could be observed". The event IS the observation; the job is what it
 * queues.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecompute;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes every open timer measured against one changed calendar.
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */
class RecomputeTimersForCalendarJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time      The clock.
	 * @param FlowTimerMapper   $timers    Where the timers are.
	 * @param FlowTimerService  $service   The supersession path, reused whole.
	 * @param CalendarRecompute $recompute The rule.
	 * @param LoggerInterface   $logger    The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FlowTimerMapper $timers,
		private readonly FlowTimerService $service,
		private readonly CalendarRecompute $recompute,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}//end __construct()

	/**
	 * Run the recompute for one calendar version.
	 *
	 * @param mixed $argument `['slug' => string, 'version' => string]`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	protected function run($argument): void {
		$slug = trim((string)(is_array($argument) === true ? ($argument['slug'] ?? '') : ''));
		$version = trim((string)(is_array($argument) === true ? ($argument['version'] ?? '') : ''));

		if ($slug === '' || $version === '') {
			$this->logger->warning('[RecomputeTimersForCalendarJob] queued without a slug and a version; nothing to do');
			return;
		}

		try {
			$counts = $this->recompute->recomputeBatch(
				slug: $slug,
				version: $version,
				timers: $this->candidates(slug: $slug),
				supersede: function (FlowTimer $timer): void {
					// The EXISTING supersession path, reused whole: it writes
					// the history and re-inherits the rungs that have already
					// fired, so a calendar change lands in the ledger looking
					// like an anchor move with a different reason. The anchor
					// itself has NOT moved, so it is handed back unchanged —
					// what moved is the calendar under it.
					$this->service->supersede(
						uuid: (string)$timer->getUuid(),
						anchorEventAt: ($timer->getAnchorAt() ?? $timer->getRunningSince()),
						reason: CalendarRecompute::REASON,
						actor: CalendarRecompute::ACTOR
					);
				}
			);

			if ($counts['skipped'] === false) {
				$this->recompute->markRan(slug: $slug, version: $version);
			}
		} catch (Throwable $e) {
			// 🔴 The mark is NOT written on a failure, deliberately. A pass that
			// died halfway must be allowed to run again; marking it done would
			// leave the timers it never reached on a stale deadline, with the
			// log claiming the calendar was handled.
			$this->logger->error(
				sprintf('[RecomputeTimersForCalendarJob] %s version %s failed: %s', $slug, $version, $e->getMessage())
			);
		}//end try
	}//end run()

	/**
	 * The open timers, in bounded pages ordered by id (D-2).
	 *
	 * A GENERATOR, not an array: the point of batching is that a hundred
	 * thousand timers never exist in memory at once, and returning an array
	 * would make the page size decorative.
	 *
	 * It walks `armed` and `suspended` separately because that is the pager the
	 * engine already has, and it is ordered by `id` — an index read with a
	 * cursor, so a pass killed halfway resumes from where it stopped rather
	 * than re-examining from the start.
	 *
	 * 🔑 IT DOES NOT NARROW BY CALENDAR IN SQL, and that is a measured choice
	 * rather than an oversight. A timer naming ANOTHER calendar cannot resolve
	 * to the changed one, so narrowing would be sound — but the index task 1.1
	 * names has not landed, and an unindexed `calendar_slug IS NULL OR
	 * calendar_slug = ?` over the whole table is slower than paging the open
	 * timers, which are the small set. When the index exists this becomes the
	 * two reads D-3 describes; the RULE does not change, because the rule is
	 * `CalendarDependency` either way.
	 *
	 * @param string $slug The changed calendar, for the log.
	 *
	 * @return \Generator<FlowTimer> The candidates.
	 */
	private function candidates(string $slug): \Generator {
		foreach ([FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED] as $state) {
			$afterId = 0;
			while (true) {
				try {
					$page = $this->timers->findByStatePaged(
						state: $state,
						afterId: $afterId,
						limit: CalendarRecompute::BATCH
					);
				} catch (Throwable $e) {
					$this->logger->error(
						sprintf('[RecomputeTimersForCalendarJob] could not page %s timers for %s: %s', $state, $slug, $e->getMessage())
					);
					return;
				}

				if ($page === []) {
					break;
				}

				foreach ($page as $timer) {
					$afterId = max($afterId, (int)$timer->getId());
					yield $timer;
				}

				if (count($page) < CalendarRecompute::BATCH) {
					break;
				}
			}//end while
		}//end foreach
	}//end candidates()
}//end class
