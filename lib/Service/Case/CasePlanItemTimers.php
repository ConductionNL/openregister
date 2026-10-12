<?php

/**
 * Plan-item deadlines on the shared clock.
 *
 * The case layer keeps no clock of its own. An item that becomes active
 * with a `dueAt` arms an advisory `due` timer on {@see FlowTimerService};
 * one with an `expiresAt` arms an `expiry` timer, enforcing (terminate the
 * item) only when the plan settings list the item under `statutory`, the
 * core's own rule for an enforcing outcome. Reaching a terminal state
 * cancels the item's open timers. The core decides WHEN; this class only
 * decides what a deadline means for an item. Nothing here ever blocks a
 * transition: a refused arm or cancel is logged.
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
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Arms, cancels and interprets the timers of plan items.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
class CasePlanItemTimers {

	/**
	 * The timer subject type of a plan item.
	 */
	public const SUBJECT_TYPE = 'case-item';

	/**
	 * The enforcing outcome of a statutory item's expiry.
	 */
	public const ON_EXPIRY = 'transition:terminate';

	/**
	 * The actor recorded on cancellations.
	 */
	private const ACTOR = 'case-plan';

	/**
	 * Constructor.
	 *
	 * @param FlowTimerService $timers The one clock.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly FlowTimerService $timers,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Arm the timers of an item that just became active.
	 *
	 * @param CaseItem $item The item.
	 * @param string|null $actor The identity that moved it.
	 * @param DateTimeInterface|null $now The clock; null is the real clock.
	 *
	 * @return int How many timers were armed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function onEntered(CaseItem $item, ?string $actor, ?DateTimeInterface $now = null): int {
		$moment = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable());
		$armed = 0;

		$due = $item->getDueAt();
		if ($due !== null && $this->arm(item: $item, purpose: FlowTimer::PURPOSE_DUE, deadline: $due, actor: $actor, now: $moment) === true) {
			$armed++;
		}

		$expires = $item->getExpiresAt();
		if ($expires !== null && $this->arm(item: $item, purpose: FlowTimer::PURPOSE_EXPIRY, deadline: $expires, actor: $actor, now: $moment) === true) {
			$armed++;
		}

		return $armed;
	}//end onEntered()

	/**
	 * Cancel the open timers of an item that reached a terminal state.
	 *
	 * @param CaseItem $item The item.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function onTerminal(CaseItem $item): int {
		try {
			return $this->timers->cancelForSubject(
				subjectType: self::SUBJECT_TYPE,
				subjectUuid: (string)$item->getUuid(),
				reason: sprintf("Plan item '%s' is %s.", (string)$item->getItemKey(), (string)$item->getState()),
				actor: self::ACTOR
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[CasePlanItemTimers] Could not cancel a plan item\'s timers; the sweep will find them moot: ' . $failure->getMessage(),
				['item' => $item->getUuid(), 'exception' => $failure]
			);

			return 0;
		}
	}//end onTerminal()

	/**
	 * The item an expiry should terminate, if any: only an enforcing expiry
	 * whose subject is a plan item.
	 *
	 * @param FlowTimerFiredEvent $event The fired timer.
	 *
	 * @return string|null The item uuid, or null.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function itemToTerminate(FlowTimerFiredEvent $event): ?string {
		$timer = $event->getTimer();
		if ($event->getKind() !== FlowTimerFiredEvent::KIND_EXPIRY
			|| $timer->getSubjectType() !== self::SUBJECT_TYPE
			|| $timer->isEnforcing() === false
		) {
			return null;
		}

		$uuid = trim((string)$timer->getSubjectUuid());
		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end itemToTerminate()

	/**
	 * Arm one timer whose deadline lands exactly on `$deadline`: the budget
	 * is a whole number of hours (calendar days past the hour bound), and the
	 * anchor is set back so anchor + budget is the deadline.
	 *
	 * @param CaseItem $item The item.
	 * @param string $purpose `due` or `expiry`.
	 * @param DateTimeInterface $deadline The moment.
	 * @param string|null $actor The arming identity.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return boolean True when armed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function arm(CaseItem $item, string $purpose, DateTimeInterface $deadline, ?string $actor, DateTimeImmutable $now): bool {
		$seconds = ($deadline->getTimestamp() - $now->getTimestamp());
		if ($seconds <= 0) {
			$this->logger->info(
				'[CasePlanItemTimers] A plan item became active after its deadline; no timer is armed.',
				['item' => $item->getUuid(), 'purpose' => $purpose]
			);

			return false;
		}

		$unit = SlaCalculator::UNIT_HOURS;
		$step = 3600;
		if ((int)ceil($seconds / 3600) > SlaCalculator::MAX_VALUE) {
			$unit = SlaCalculator::UNIT_CALENDAR_DAYS;
			$step = 86400;
		}

		$value = max(SlaCalculator::MIN_VALUE, (int)ceil($seconds / $step));
		$anchor = DateTimeImmutable::createFromInterface($deadline)->modify(sprintf('-%d seconds', $value * $step));

		$config = [
			'subjectType' => self::SUBJECT_TYPE,
			'subjectUuid' => (string)$item->getUuid(),
			'purpose' => $purpose,
			'legalEffect' => $this->legalEffect(item: $item, purpose: $purpose),
			'sla' => ['value' => $value, 'unit' => $unit],
			'anchorEvent' => 'case.item.active',
			'anchorEventAt' => $anchor->format(DATE_ATOM),
			'appId' => 'openregister',
			'title' => (string)($item->getName() ?? $item->getItemKey()),
			'metadata' => ['objectUuid' => $item->getObjectUuid(), 'itemKey' => $item->getItemKey()],
		];
		if ($config['legalEffect'] === FlowTimer::LEGAL_WETTELIJK) {
			$config['onExpiry'] = self::ON_EXPIRY;
		}

		try {
			$this->timers->arm(config: $config, actor: $actor);

			return true;
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[CasePlanItemTimers] A plan-item timer was refused; the transition stands: ' . $failure->getMessage(),
				['item' => $item->getUuid(), 'purpose' => $purpose, 'exception' => $failure]
			);

			return false;
		}
	}//end arm()

	/**
	 * A due date advises; an expiry enforces only on a statutory item and
	 * otherwise counts as a service norm.
	 *
	 * @param CaseItem $item The item.
	 * @param string $purpose `due` or `expiry`.
	 *
	 * @return string The legal effect.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function legalEffect(CaseItem $item, string $purpose): string {
		if ($purpose === FlowTimer::PURPOSE_DUE) {
			return FlowTimer::LEGAL_NONE;
		}

		$statutory = ($item->getPlanSettings()['statutory'] ?? []);
		if (is_array($statutory) === true && in_array($item->getItemKey(), $statutory, true) === true) {
			return FlowTimer::LEGAL_WETTELIJK;
		}

		return FlowTimer::LEGAL_SERVICENORM;
	}//end legalEffect()
}//end class
