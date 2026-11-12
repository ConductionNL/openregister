<?php

/**
 * An extension granted on a timer, with who granted it and when.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * The extension and the act that grants it.
 */
final class TimerExtensionGrant {

	/**
	 * Constructor.
	 *
	 * @param FlowTimerExtension $extension The amount, unit, rationale and basis.
	 * @param FlowTimerAct       $act       The acting identity and the clock.
	 */
	public function __construct(
		public readonly FlowTimerExtension $extension,
		public readonly FlowTimerAct $act,
	) {
	}//end __construct()
}//end class
