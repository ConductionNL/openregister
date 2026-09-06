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
	 * Constructor.
	 *
	 * @param FlowTimer $timer The timer that fired.
	 * @param string $kind `rung` or `expiry`.
	 * @param string $transition The named transition raised.
	 * @param string|null $rungKey The rung's stable key, for a rung fire.
	 * @param array<int, array{type: string, id: string, role: string}> $recipients The resolved addressees.
	 * @param string|null $priority The rung's priority.
	 * @param string|null $message The message identity, resolved downstream.
	 */
	public function __construct(
		private readonly FlowTimer $timer,
		private readonly string $kind,
		private readonly string $transition,
		private readonly ?string $rungKey,
		private readonly array $recipients,
		private readonly ?string $priority,
		private readonly ?string $message,
	) {
		parent::__construct();

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
		return $this->kind;
	}//end getKind()

	/**
	 * The named transition raised.
	 *
	 * @return string The transition.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getTransition(): string {
		return $this->transition;
	}//end getTransition()

	/**
	 * The rung key, for a rung fire.
	 *
	 * @return string|null The key.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getRungKey(): ?string {
		return $this->rungKey;
	}//end getRungKey()

	/**
	 * The resolved addressees.
	 *
	 * @return array<int, array{type: string, id: string, role: string}> The recipients.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getRecipients(): array {
		return $this->recipients;
	}//end getRecipients()

	/**
	 * The rung's priority.
	 *
	 * @return string|null The priority.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getPriority(): ?string {
		return $this->priority;
	}//end getPriority()

	/**
	 * The message identity.
	 *
	 * @return string|null The message identity.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()
}//end class
