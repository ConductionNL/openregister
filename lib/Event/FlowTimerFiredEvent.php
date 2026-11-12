<?php

/**
 * A business timer raised a NAMED transition: an escalation rung or an expiry.
 *
 * This is the seam between deciding WHEN (this change) and telling WHO (the
 * messaging nodes and the inbox projections). The event carries the rung's
 * transition name, the recipient descriptors the ladder resolved from the
 * subject's performer (`user:<uid>`, `group:<gid>`, or an unresolved
 * `role:<name>`), the priority and the message identity. It sends nothing
 * and knows no channel: a subscriber that delivers is downstream of it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Service\Flow\Timer\EscalationNotice;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use OCP\EventDispatcher\Event;

/**
 * Carries the timer, the transition raised and its addressees.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */
class FlowTimerFiredEvent extends Event {

	/**
	 * Kinds of fire.
	 */
	public const KIND_RUNG = 'rung';

	public const KIND_EXPIRY = 'expiry';

	/**
	 * The fire's addressees and message; empty when none was given.
	 *
	 * @var EscalationNotice
	 */
	private readonly EscalationNotice $notice;

	/**
	 * Constructor.
	 *
	 * @param FlowTimer             $timer  The timer that fired.
	 * @param TimerFiring           $firing The kind, the named transition raised and the rung key.
	 * @param EscalationNotice|null $notice Recipients, priority, message and consequence; null for none.
	 */
	public function __construct(
		private readonly FlowTimer $timer,
		private readonly TimerFiring $firing,
		?EscalationNotice $notice = null,
	) {
		parent::__construct();
		$this->notice = $notice ?? new EscalationNotice();
	}//end __construct()

	/**
	 * The timer that fired.
	 *
	 * @return FlowTimer The timer.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getTimer(): FlowTimer {
		return $this->timer;
	}//end getTimer()

	/**
	 * Whether this was a rung or the expiry.
	 *
	 * @return string `rung` or `expiry`.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getKind(): string {
		return $this->firing->kind;
	}//end getKind()

	/**
	 * The named transition raised.
	 *
	 * @return string The transition.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getTransition(): string {
		return $this->firing->transition;
	}//end getTransition()

	/**
	 * The rung key, for a rung fire.
	 *
	 * @return string|null The key.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getRungKey(): ?string {
		return $this->firing->rungKey;
	}//end getRungKey()

	/**
	 * The resolved addressees.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The recipients.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getRecipients(): array {
		return $this->notice->recipients;
	}//end getRecipients()

	/**
	 * The rung's priority.
	 *
	 * @return string|null The priority.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getPriority(): ?string {
		return $this->notice->priority;
	}//end getPriority()

	/**
	 * The message identity.
	 *
	 * @return string|null The message identity.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getMessage(): ?string {
		return $this->notice->message;
	}//end getMessage()

	/**
	 * The consequence a postBreach rung tells the party, as the case type words it.
	 *
	 * @return string|null The consequence line, or null when the rung carries none.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-overdue-path-is-consumed-from-flow-business-timers-never-rebuilt
	 */
	public function getConsequence(): ?string {
		return $this->notice->consequence;
	}//end getConsequence()
}//end class
