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
use OCA\OpenRegister\Db\FlowTimer;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The re-arm pass over the marked plan items.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
class CasePlanTimerRearm implements CasePlanTimerRearmInterface {

	/**
	 * The cause reference an enforced expiry records instead of a timer uuid.
	 */
	public const CAUSE_REF = 'rearm-pass';

	/**
	 * Constructor.
	 *
	 * @param CaseRearmSources $sources The markers, the plan-item table and the timers an item already has.
	 * @param CasePlanItemTimers $timers Arms the timer.
	 * @param CasePlanService $plans Enforces an expiry that passed meanwhile.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly CaseRearmSources $sources,
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
		foreach (array_keys($this->sources->markers->all()) as $uuid) {
			$result[$this->settle(itemUuid: (string)$uuid, now: $moment)]++;
		}

		return $result;
	}//end run()

	/**
	 * Settle one marked item; a failure is logged and counted, never rethrown into the pass.
	 *
	 * @param string $itemUuid The item.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return string `armed`, `enforced`, `dropped` or `failed`.
	 */
	private function settle(string $itemUuid, DateTimeImmutable $now): string {
		try {
			return $this->rearm(itemUuid: $itemUuid, now: $now);
		} catch (Throwable $failure) {
			$this->logger->error(
				'[CasePlanTimerRearm] Plan item ' . $itemUuid . ' still lacks its statutory expiry timer: ' . $failure->getMessage(),
				['item' => $itemUuid, 'exception' => $failure]
			);

			return 'failed';
		}
	}//end settle()

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
			$item = $this->sources->items->findByUuid(uuid: $itemUuid);
		} catch (DoesNotExistException) {
			return $this->drop(itemUuid: $itemUuid);
		}

		if ($item->getState() !== CaseItem::STATE_ACTIVE || $this->hasOpenExpiry(item: $item) === true) {
			return $this->drop(itemUuid: $itemUuid);
		}

		return $this->conclude(itemUuid: $itemUuid, outcome: $this->timers->rearmStatutoryExpiry(item: $item, now: $now));
	}//end rearm()

	/**
	 * What the arm attempt means for the marker: a failed arm keeps it, an
	 * overdue term is enforced, anything else settles the item.
	 *
	 * @param string $itemUuid The item.
	 * @param string $outcome One of the CasePlanItemTimers::REARM_* constants.
	 *
	 * @return string `armed`, `enforced`, `dropped` or `failed`.
	 */
	private function conclude(string $itemUuid, string $outcome): string {
		if ($outcome === CasePlanItemTimers::REARM_FAILED) {
			return 'failed';
		}

		if ($outcome === CasePlanItemTimers::REARM_OVERDUE) {
			return $this->enforce(itemUuid: $itemUuid);
		}

		$this->sources->markers->remove(itemUuid: $itemUuid);
		if ($outcome === CasePlanItemTimers::REARM_ARMED) {
			return 'armed';
		}

		return 'dropped';
	}//end conclude()

	/**
	 * The term ran out while nothing was watching it: end the item as the fired timer would have.
	 *
	 * @param string $itemUuid The item.
	 *
	 * @return string `enforced`, `dropped` or `failed`.
	 */
	private function enforce(string $itemUuid): string {
		if ($this->plans->onTimerExpired(itemUuid: $itemUuid, timerUuid: self::CAUSE_REF) === true) {
			$this->sources->markers->remove(itemUuid: $itemUuid);

			return 'enforced';
		}

		// False covers three cases: gone and already terminal need nothing, a transition that threw does.
		if ($this->needsNothingMore(itemUuid: $itemUuid) === true) {
			return $this->drop(itemUuid: $itemUuid);
		}

		return 'failed';
	}//end enforce()

	/**
	 * Forget the marker of an item that needs nothing more.
	 *
	 * @param string $itemUuid The item.
	 *
	 * @return string `dropped`.
	 */
	private function drop(string $itemUuid): string {
		$this->sources->markers->remove(itemUuid: $itemUuid);

		return 'dropped';
	}//end drop()

	/**
	 * Whether the item is gone or terminal, so a refused enforcement left nothing to retry.
	 *
	 * @param string $itemUuid The item.
	 *
	 * @return bool
	 */
	private function needsNothingMore(string $itemUuid): bool {
		try {
			return $this->sources->items->findByUuid(uuid: $itemUuid)->isInTerminalState() === true;
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
		$open = $this->sources->timerRows->findBySubject(
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
