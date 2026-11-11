<?php

/**
 * EscalationNotice: whom a timer fire addresses, how urgently, and with what.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
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

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * The addressees, priority, message identity and announced consequence of one fire.
 *
 * It sends nothing: a subscriber of FlowTimerFiredEvent delivers it.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */
final class EscalationNotice {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{type: string, id: string, role: string}> $recipients  The resolved addressees.
	 * @param string|null                                                $priority    The rung's priority.
	 * @param string|null                                                $message     The message identity, resolved downstream.
	 * @param string|null                                                $consequence What the party is told will happen,
	 *                                                                                for a postBreach rung.
	 */
	public function __construct(
		public readonly array $recipients = [],
		public readonly ?string $priority = null,
		public readonly ?string $message = null,
		public readonly ?string $consequence = null,
	) {
	}//end __construct()
}//end class
