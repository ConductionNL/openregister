<?php

/**
 * A request to extend (verdagen) one timer.
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
 * @spec openspec/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * The timer to extend, by how much, and why.
 */
final class TimerExtensionRequest {

	/**
	 * Constructor.
	 *
	 * @param string $uuid      The timer uuid.
	 * @param int    $amount    The extension amount.
	 * @param string $unit      Its unit; converted into the budget unit when they differ.
	 * @param string $rationale Why (required, non-empty).
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly int $amount,
		public readonly string $unit,
		public readonly string $rationale,
	) {
	}//end __construct()

	/**
	 * The extension this request grants on the given ground.
	 *
	 * @param string $basis The ground recorded in the ledger.
	 *
	 * @return FlowTimerExtension The extension.
	 */
	public function extension(string $basis): FlowTimerExtension {
		return new FlowTimerExtension(amount: $this->amount, unit: $this->unit, rationale: $this->rationale, basis: $basis);
	}//end extension()
}//end class
