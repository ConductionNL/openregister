<?php

/**
 * TimerSubjectRef: the subject a flow timer is bound to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The subject a timer is bound to: its type (object, task, case plan item) and uuid.
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
 */
final class TimerSubjectRef {

	/**
	 * Constructor
	 *
	 * @param string $type The subject type.
	 * @param string $uuid The subject uuid.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
