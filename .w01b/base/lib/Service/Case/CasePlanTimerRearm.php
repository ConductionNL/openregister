<?php

/**
 * Arms again the statutory expiry of plan items whose first arm was refused.
 *
 * One pass reads the markers {@see UnarmedTimerMarkers} holds and, for each
 * marked item that is still active, either finds its timer armed after all
 * (and drops the marker), arms it, or, when the term has passed meanwhile,
 * enforces the expiry the timer would have: the item is terminated, as a
 * fired statutory timer terminates it. An item that is gone, or no longer
 * active, needs nothing and loses its marker. A pass that cannot arm an item, or whose
 * enforcement threw, leaves the marker for the next one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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

namespace OCA\OpenRegister\Service\Case;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The re-arm pass over the marked plan items.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
class CasePlanTimerRearm {

	/**
	 * The cause reference an enforced expiry records instead of a timer uuid.
	 */
	public const CAUSE_REF = 'rearm-pass';

	/**
	 * Constructor.
	 *
	 * @param UnarmedTimerMarkers $markers The items that lack their statutory timer.
	 * @param CaseItemMapper $items The plan-item table.
	 * @param FlowTimerMapper $timerRows Reads the timers an item already has.
	 * @param CasePlanItemTimers $timers Arms the timer.
	 * @param CasePlanService $plans Enforces an expiry that passed meanwhile.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly UnarmedTimerMarkers $markers,
		private readonly CaseItemMapper $items,
		private readonly FlowTimerMapper $timerRows,
		private readonly CasePlanItemTimers $timers,
		private readonly CasePlanService $plans,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run one pass.
	 *
	 * @param DateTimeInterface|null $now The clock; null is the real clock.
	 *
	 * @return array{armed: int, enforced: int, dropped: int, failed: int} Work performed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function run(?DateTimeInterface $now = null): array {
		$moment = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable());
		$result = ['armed' => 0, 'enforced' => 0, 'dropped' => 0, 'failed' => 0];
		foreach (array_keys($this->markers->all()) as $uuid) {
			try {
				$outcome = $this->rearm(itemUuid: (string)$uuid, now: $moment);
			} catch (Throwable $failure) {
				$outcome = 'failed';
				$this->logger->error(
					'[CasePlanTimerRearm] Plan item ' . $uuid . ' still lacks its statutory expiry timer: ' . $failure->getMessage(),
					['item' => $uuid, 'exception' => $failure]
				);
			}

			$result[$outcome]++;
		}

		return $result;
	}//end run()

	/**
	 * Settle one marked item.
	 *
	 * @param string $itemUuid The item.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return string `armed`, `enforced`, `dropped` or `failed`.
	 */
	private function rearm(string $itemUuid, DateTimeImmutable $now): string {
		try {
			$item = $this->items->findByUuid(uuid: $itemUuid);
		} catch (DoesNotExistException) {
			$this->markers->remove(itemUuid: $itemUuid);

			return 'dropped';
		}

		if ($item->getState() !== CaseItem::STATE_ACTIVE || $this->hasOpenExpiry(item: $item) === true) {
			$this->markers->remove(itemUuid: $itemUuid);

			return 'dropped';
		}

		$outcome = $this->timers->rearmStatutoryExpiry(item: $item, now: $now);
		if ($outcome === CasePlanItemTimers::REARM_FAILED) {
			return 'failed';
		}

		if ($outcome === CasePlanItemTimers::REARM_OVERDUE) {
			// The term ran out while nothing was watching it: end the item as the fired timer would have.
			if ($this->plans->onTimerExpired(itemUuid: $itemUuid, timerUuid: self::CAUSE_REF) === true) {
				$this->markers->remove(itemUuid: $itemUuid);

				return 'enforced';
			}

			// False covers three cases: gone and already terminal need nothing, a transition that threw does.
			if ($this->needsNothingMore(itemUuid: $itemUuid) === true) {
				$this->markers->remove(itemUuid: $itemUuid);

				return 'dropped';
			}

			return 'failed';
		}

		$this->markers->remove(itemUuid: $itemUuid);
		if ($outcome === CasePlanItemTimers::REARM_ARMED) {
			return 'armed';
		}

		return 'dropped';
	}//end rearm()

	/**
	 * Whether the item is gone or terminal, so a refused enforcement left nothing to retry.
	 *
	 * @param string $itemUuid The item.
	 *
	 * @return bool
	 */
	private function needsNothingMore(string $itemUuid): bool {
		try {
			return $this->items->findByUuid(uuid: $itemUuid)->isInTerminalState() === true;
		} catch (DoesNotExistException) {
			return true;
		}
	}//end needsNothingMore()

	/**
	 * Whether the item already has an expiry timer that is still running or suspended.
	 *
	 * @param CaseItem $item The item.
	 *
	 * @return bool
	 */
	private function hasOpenExpiry(CaseItem $item): bool {
		$open = $this->timerRows->findBySubject(
			subjectType: CasePlanItemTimers::SUBJECT_TYPE,
			subjectUuid: (string)$item->getUuid(),
			states: [FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED]
		);
		foreach ($open as $timer) {
			if ($timer->getPurpose() === FlowTimer::PURPOSE_EXPIRY) {
				return true;
			}
		}

		return false;
	}//end hasOpenExpiry()
}//end class
