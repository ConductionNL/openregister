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
use OCA\OpenRegister\Kernel\Contracts\TimerChange;
use OCA\OpenRegister\Kernel\Contracts\TimerSubjectRef;
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
	 * @param CaseItem     $item The item.
	 * @param FlowTimerAct $act  The identity that moved it and the clock.
	 *
	 * @return int How many timers were armed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function onEntered(CaseItem $item, FlowTimerAct $act): int {
		return $this->armDeadlines(item: $item, act: $act);
	}//end onEntered()

	/**
	 * Arm a timer for each deadline the item carries; a passed one arms none.
	 *
	 * @param CaseItem     $item The item that became active.
	 * @param FlowTimerAct $act  The arming identity and the moment of entry.
	 *
	 * @return integer How many timers were armed (0..2).
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function armDeadlines(CaseItem $item, FlowTimerAct $act): int {
		$deadlines = array_filter([FlowTimer::PURPOSE_DUE => $item->getDueAt(), FlowTimer::PURPOSE_EXPIRY => $item->getExpiresAt()]);
		$armed = 0;
		foreach ($deadlines as $purpose => $deadline) {
			if ($this->arm(due: new CaseItemDeadline(item: $item, purpose: (string)$purpose, deadline: $deadline), act: $act) === true) {
				$armed++;
			}
		}

		return $armed;
	}//end armDeadlines()

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
	 * @param CaseItem     $item The item.
	 * @param FlowTimerAct $act  The arming identity and the moment of the pass.
	 *
	 * @return string One of the REARM_* constants.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function rearmStatutoryExpiry(CaseItem $item, FlowTimerAct $act): string {
		if ($this->hasStatutoryExpiry(item: $item) === false) {
			return self::REARM_NONE;
		}

		$expires = $item->getExpiresAt();
		if ($expires->getTimestamp() <= $act->now->getTimestamp()) {
			return self::REARM_OVERDUE;
		}

		if ($this->arm(due: new CaseItemDeadline(item: $item, purpose: FlowTimer::PURPOSE_EXPIRY, deadline: $expires), act: $act) === true) {
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

			return $this->timers->cancelForSubject(
				subject: new TimerSubjectRef(type: self::SUBJECT_TYPE, uuid: $uuid),
				change: new TimerChange(reason: $reason, actor: self::ACTOR)
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
	 * @param CaseItemDeadline $due The item, `due` or `expiry`, and the moment.
	 * @param FlowTimerAct     $act The arming identity and the clock.
	 *
	 * @return boolean True when armed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function arm(CaseItemDeadline $due, FlowTimerAct $act): bool {
		$seconds = ($due->deadline->getTimestamp() - $act->now->getTimestamp());
		if ($seconds <= 0) {
			$this->passedBeforeEntry(item: $due->item, purpose: $due->purpose);

			return false;
		}

		$config = $this->configFor(due: $due, seconds: $seconds);

		return $this->submit(run: new CaseTimerArmRun(item: $due->item, config: $config, actor: $act->actor));
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
	 * @param CaseItemDeadline $due     The item, `due` or `expiry`, and the moment.
	 * @param int              $seconds How far ahead it is.
	 *
	 * @return array<string, mixed> The configuration the clock arms.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function configFor(CaseItemDeadline $due, int $seconds): array {
		$config = [
			'subjectType' => self::SUBJECT_TYPE,
			'subjectUuid' => (string)$due->item->getUuid(),
			'purpose' => $due->purpose,
			'legalEffect' => $this->legalEffect(item: $due->item, purpose: $due->purpose),
		];
		$config += self::timing(deadline: $due->deadline, seconds: $seconds);
		$config += self::labels(item: $due->item);
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
	 * @param CaseTimerArmRun $run The item, the configuration and the arming identity.
	 *
	 * @return bool True when armed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function submit(CaseTimerArmRun $run): bool {
		try {
			$this->timers->arm(config: $run->config, actor: $run->actor);

			return true;
		} catch (Throwable $failure) {
			$this->reportRefused(run: $run, failure: $failure);

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
	 * @param CaseTimerArmRun $run     The item and the refused configuration.
	 * @param Throwable       $failure Why.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function reportRefused(CaseTimerArmRun $run, Throwable $failure): void {
		if ($run->config['legalEffect'] === FlowTimer::LEGAL_WETTELIJK) {
			$this->mark(item: $run->item);
			$this->logger->error(self::unarmedMessage(run: $run, failure: $failure), self::unarmedContext(run: $run, failure: $failure));

			return;
		}

		$this->logger->warning(
			'[CasePlanItemTimers] A plan-item timer was refused; the transition stands: ' . $failure->getMessage(),
			['item' => $run->item->getUuid(), 'purpose' => $run->purpose(), 'exception' => $failure]
		);
	}//end reportRefused()

	/**
	 * The loud message of an item left active without its statutory timer.
	 *
	 * @param CaseTimerArmRun $run     The item and the refused `due` or `expiry` configuration.
	 * @param Throwable       $failure Why the arm was refused.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private static function unarmedMessage(CaseTimerArmRun $run, Throwable $failure): string {
		$item = $run->item;
		return sprintf(
			'[CasePlanItemTimers] Plan item %s of case object %s is active WITHOUT its statutory (%s) %s timer: '
			. 'the term will not run until the re-arm pass arms it. %s',
			(string)$item->getItemKey(),
			(string)$item->getObjectUuid(),
			FlowTimer::LEGAL_WETTELIJK,
			$run->purpose(),
			$failure->getMessage()
		);
	}//end unarmedMessage()

	/**
	 * The log context of an item left active without its statutory timer.
	 *
	 * @param CaseTimerArmRun $run     The item and the refused `due` or `expiry` configuration.
	 * @param Throwable       $failure Why the arm was refused.
	 *
	 * @return array<string, mixed> The context.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private static function unarmedContext(CaseTimerArmRun $run, Throwable $failure): array {
		return [
			'item' => $run->item->getUuid(),
			'case' => $run->item->getObjectUuid(),
			'legalEffect' => FlowTimer::LEGAL_WETTELIJK,
			'purpose' => $run->purpose(),
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
