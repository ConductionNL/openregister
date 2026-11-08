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
 * transition: a refused arm or cancel is logged. A refused arm of a statutory
 * (`wettelijk`) expiry is also marked ({@see UnarmedTimerMarkers}), because an
 * item that is active past a legal term with nothing to end it is not a log
 * line to move on from: {@see CasePlanTimerRearm} arms it again, or enforces
 * the expiry when the term has passed meanwhile.
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
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Arms, cancels and interprets the timers of plan items.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
class CasePlanItemTimers implements CasePlanItemTimersInterface {

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
	 * What {@see rearmStatutoryExpiry()} did.
	 */
	public const REARM_ARMED = 'armed';

	public const REARM_OVERDUE = 'overdue';

	public const REARM_NONE = 'none';

	public const REARM_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param FlowTimerService $timers The one clock.
	 * @param LoggerInterface $logger Failure reporting.
	 * @param UnarmedTimerMarkers|null $markers Where an item whose statutory expiry could not be armed is remembered.
	 */
	public function __construct(
		private readonly FlowTimerService $timers,
		private readonly LoggerInterface $logger,
		private readonly ?UnarmedTimerMarkers $markers = null,
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
		$deadlines = array_filter([FlowTimer::PURPOSE_DUE => $item->getDueAt(), FlowTimer::PURPOSE_EXPIRY => $item->getExpiresAt()]);
		$armed = 0;
		$act = new FlowTimerAct(actor: $actor, now: $moment);
		foreach ($deadlines as $purpose => $deadline) {
			if ($this->arm(item: $item, purpose: (string)$purpose, deadline: $deadline, act: $act) === true) {
				$armed++;
			}
		}

		return $armed;
	}//end onEntered()

	/**
	 * Mark an active item for the re-arm pass when it carries a statutory expiry.
	 *
	 * For an item that already existed when its plan was ensured again: its first arm may have failed, and
	 * nothing else would look at it. The pass drops the marker at once for an item whose timer is running.
	 *
	 * @param CaseItem $item The active item.
	 *
	 * @return bool True when the item was marked.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function markIfStatutory(CaseItem $item): bool {
		if ($this->hasStatutoryExpiry(item: $item) === false) {
			return false;
		}

		$this->mark(item: $item);

		return true;
	}//end markIfStatutory()

	/**
	 * Arm the statutory expiry of an item that is active without one, again.
	 *
	 * Called by {@see CasePlanTimerRearm} for an item the first arm failed
	 * for. Nothing is armed for an item that has no statutory expiry, and
	 * nothing for one whose term has already passed: that one is reported as
	 * overdue for the caller to enforce.
	 *
	 * @param CaseItem $item The item.
	 * @param DateTimeInterface $now The clock.
	 * @param string|null $actor The arming identity.
	 *
	 * @return string One of the REARM_* constants.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function rearmStatutoryExpiry(CaseItem $item, DateTimeInterface $now, ?string $actor = null): string {
		if ($this->hasStatutoryExpiry(item: $item) === false) {
			return self::REARM_NONE;
		}

		$expires = $item->getExpiresAt();
		$moment = DateTimeImmutable::createFromInterface($now);
		if ($expires->getTimestamp() <= $moment->getTimestamp()) {
			return self::REARM_OVERDUE;
		}

		if ($this->arm(item: $item, purpose: FlowTimer::PURPOSE_EXPIRY, deadline: $expires, act: new FlowTimerAct(actor: $actor, now: $moment)) === true) {
			return self::REARM_ARMED;
		}

		return self::REARM_FAILED;
	}//end rearmStatutoryExpiry()

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
		$reason = sprintf("Plan item '%s' is %s.", (string)$item->getItemKey(), (string)$item->getState());
		try {
			$uuid = (string)$item->getUuid();

			return $this->timers->cancelForSubject(subjectType: self::SUBJECT_TYPE, subjectUuid: $uuid, reason: $reason, actor: self::ACTOR);
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
		if (self::isEnforcingItemExpiry(event: $event) === false) {
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
	 * @param FlowTimerAct $act The arming identity and the clock.
	 *
	 * @return boolean True when armed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function arm(CaseItem $item, string $purpose, DateTimeInterface $deadline, FlowTimerAct $act): bool {
		$seconds = ($deadline->getTimestamp() - $act->now->getTimestamp());
		if ($seconds <= 0) {
			$this->passedBeforeEntry(item: $item, purpose: $purpose);

			return false;
		}

		$config = $this->configFor(item: $item, purpose: $purpose, deadline: $deadline, seconds: $seconds);

		return $this->submit(item: $item, config: $config, actor: $act->actor);
	}//end arm()

	/**
	 * Whether an expiry fired on a plan item and enforces.
	 *
	 * @param FlowTimerFiredEvent $event The fired timer.
	 *
	 * @return bool True for an enforcing expiry whose subject is a plan item.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private static function isEnforcingItemExpiry(FlowTimerFiredEvent $event): bool {
		$timer = $event->getTimer();

		return $event->getKind() === FlowTimerFiredEvent::KIND_EXPIRY
			&& $timer->getSubjectType() === self::SUBJECT_TYPE
			&& $timer->isEnforcing() === true;
	}//end isEnforcingItemExpiry()

	/**
	 * Whether an item carries an expiry that is statutory.
	 *
	 * @param CaseItem $item The item.
	 *
	 * @return bool True when it has an expiry and its key is in the plan's statutory list.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function hasStatutoryExpiry(CaseItem $item): bool {
		return $item->getExpiresAt() !== null && $this->legalEffect(item: $item, purpose: FlowTimer::PURPOSE_EXPIRY) === FlowTimer::LEGAL_WETTELIJK;
	}//end hasStatutoryExpiry()

	/**
	 * A deadline that passed before the item became active arms nothing; a
	 * statutory term that ran out meanwhile still has to be enforced, so it is
	 * marked, and the re-arm pass (which enforces an overdue term) ends the
	 * item as a fired timer would have.
	 *
	 * @param CaseItem $item The item.
	 * @param string $purpose `due` or `expiry`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function passedBeforeEntry(CaseItem $item, string $purpose): void {
		$this->logger->info(
			'[CasePlanItemTimers] A plan item became active after its deadline; no timer is armed.',
			['item' => $item->getUuid(), 'purpose' => $purpose]
		);

		if ($purpose === FlowTimer::PURPOSE_EXPIRY && $this->legalEffect(item: $item, purpose: $purpose) === FlowTimer::LEGAL_WETTELIJK) {
			$this->mark(item: $item);
		}
	}//end passedBeforeEntry()

	/**
	 * The timer configuration whose deadline lands exactly on `$deadline`.
	 *
	 * @param CaseItem $item The item.
	 * @param string $purpose `due` or `expiry`.
	 * @param DateTimeInterface $deadline The moment.
	 * @param int $seconds How far ahead it is.
	 *
	 * @return array<string, mixed> The configuration the clock arms.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function configFor(CaseItem $item, string $purpose, DateTimeInterface $deadline, int $seconds): array {
		$config = [
			'subjectType' => self::SUBJECT_TYPE,
			'subjectUuid' => (string)$item->getUuid(),
			'purpose' => $purpose,
			'legalEffect' => $this->legalEffect(item: $item, purpose: $purpose),
		];
		$config += self::timing(deadline: $deadline, seconds: $seconds);
		$config += self::labels(item: $item);
		if ($config['legalEffect'] === FlowTimer::LEGAL_WETTELIJK) {
			$config['onExpiry'] = self::ON_EXPIRY;
		}

		return $config;
	}//end configFor()

	/**
	 * The budget and anchor of a timer: a whole number of hours (calendar
	 * days past the hour bound), the anchor set back so anchor + budget is
	 * the deadline.
	 *
	 * @param DateTimeInterface $deadline The moment.
	 * @param int $seconds How far ahead it is.
	 *
	 * @return array{sla: array{value: int, unit: string}, anchorEvent: string, anchorEventAt: string} The timing keys.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private static function timing(DateTimeInterface $deadline, int $seconds): array {
		$unit = SlaCalculatorInterface::UNIT_HOURS;
		$step = 3600;
		if ((int)ceil($seconds / 3600) > SlaCalculatorInterface::MAX_VALUE) {
			$unit = SlaCalculatorInterface::UNIT_CALENDAR_DAYS;
			$step = 86400;
		}

		$value = max(SlaCalculatorInterface::MIN_VALUE, (int)ceil($seconds / $step));
		$anchor = DateTimeImmutable::createFromInterface($deadline)->modify(sprintf('-%d seconds', $value * $step));

		return [
			'sla' => ['value' => $value, 'unit' => $unit],
			'anchorEvent' => 'case.item.active',
			'anchorEventAt' => $anchor->format(DATE_ATOM),
		];
	}//end timing()

	/**
	 * What a timer says about its item: the app, a title and where it belongs.
	 *
	 * @param CaseItem $item The item.
	 *
	 * @return array{appId: string, title: string, metadata: array<string, mixed>} The label keys.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private static function labels(CaseItem $item): array {
		return [
			'appId' => 'openregister',
			'title' => (string)($item->getName() ?? $item->getItemKey()),
			'metadata' => ['objectUuid' => $item->getObjectUuid(), 'itemKey' => $item->getItemKey()],
		];
	}//end labels()

	/**
	 * Hand a configuration to the clock; a refusal is reported, never thrown.
	 *
	 * @param CaseItem $item The item.
	 * @param array<string, mixed> $config The configuration.
	 * @param string|null $actor The arming identity.
	 *
	 * @return bool True when armed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function submit(CaseItem $item, array $config, ?string $actor): bool {
		try {
			$this->timers->arm(config: $config, actor: $actor);

			return true;
		} catch (Throwable $failure) {
			$this->reportRefused(item: $item, config: $config, failure: $failure);

			return false;
		}
	}//end submit()

	/**
	 * Report a refused arm. The transition is already committed and the
	 * caller swallows an exception raised from here
	 * (CasePlanStateMachine::flushEvents()), so this cannot fail it. What it
	 * can do for a statutory timer is say loudly that the item is active
	 * without the timer that enforces its expiry, and leave a durable marker
	 * the re-arm job acts on.
	 *
	 * @param CaseItem $item The item.
	 * @param array<string, mixed> $config The refused configuration.
	 * @param Throwable $failure Why.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function reportRefused(CaseItem $item, array $config, Throwable $failure): void {
		$purpose = (string)$config['purpose'];
		if ($config['legalEffect'] === FlowTimer::LEGAL_WETTELIJK) {
			$this->mark(item: $item);
			$this->logger->error(
				self::unarmedMessage(item: $item, purpose: $purpose, failure: $failure),
				self::unarmedContext(item: $item, purpose: $purpose, failure: $failure)
			);

			return;
		}

		$this->logger->warning(
			'[CasePlanItemTimers] A plan-item timer was refused; the transition stands: ' . $failure->getMessage(),
			['item' => $item->getUuid(), 'purpose' => $purpose, 'exception' => $failure]
		);
	}//end reportRefused()

	/**
	 * The loud message of an item left active without its statutory timer.
	 *
	 * @param CaseItem $item The item.
	 * @param string $purpose `due` or `expiry`.
	 * @param Throwable $failure Why the arm was refused.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private static function unarmedMessage(CaseItem $item, string $purpose, Throwable $failure): string {
		return sprintf(
			'[CasePlanItemTimers] Plan item %s of case object %s is active WITHOUT its statutory (%s) %s timer: '
			. 'the term will not run until the re-arm pass arms it. %s',
			(string)$item->getItemKey(),
			(string)$item->getObjectUuid(),
			FlowTimer::LEGAL_WETTELIJK,
			$purpose,
			$failure->getMessage()
		);
	}//end unarmedMessage()

	/**
	 * The log context of an item left active without its statutory timer.
	 *
	 * @param CaseItem $item The item.
	 * @param string $purpose `due` or `expiry`.
	 * @param Throwable $failure Why the arm was refused.
	 *
	 * @return array<string, mixed> The context.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private static function unarmedContext(CaseItem $item, string $purpose, Throwable $failure): array {
		return [
			'item' => $item->getUuid(),
			'case' => $item->getObjectUuid(),
			'legalEffect' => FlowTimer::LEGAL_WETTELIJK,
			'purpose' => $purpose,
			'exception' => $failure,
		];
	}//end unarmedContext()

	/**
	 * Remember that an item lacks its statutory expiry timer.
	 *
	 * @param CaseItem $item The item.
	 *
	 * @return void
	 */
	private function mark(CaseItem $item): void {
		if ($this->markers === null || $this->markers->add(itemUuid: (string)$item->getUuid()) === true) {
			return;
		}

		$this->logger->error(
			'[CasePlanItemTimers] The marker of an item without its statutory timer could not be stored; nothing will arm it again.',
			['item' => $item->getUuid(), 'case' => $item->getObjectUuid()]
		);
	}//end mark()

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
