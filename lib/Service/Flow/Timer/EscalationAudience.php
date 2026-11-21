<?php

/**
 * Who an escalation rung can reach: the subject task's performer and the ladder's role bindings.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\Task;

/**
 * The subject task, when the subject is one, and the role bindings a ladder declares.
 *
 * Passed to {@see EscalationLadderService::resolveRecipients()} by the timer service.
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */
final class EscalationAudience {
	/**
	 * Constructor.
	 *
	 * @param Task|null             $subject      The subject task, when the subject is a task.
	 * @param array<string, string> $roleBindings Role → `type:id` bindings from the ladder.
	 */
	public function __construct(
		public readonly ?Task $subject,
		public readonly array $roleBindings,
	) {
	}//end __construct()
}//end class
