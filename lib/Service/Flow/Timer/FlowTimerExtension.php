<?php

/**
 * One extension of a timer's budget: how much, in which unit, why, and on what ground.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * An extension as extend() and extendWithOverride() grant it.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
 */
final class FlowTimerExtension {

	/**
	 * Constructor.
	 *
	 * @param int    $amount    The extension amount.
	 * @param string $unit      Its unit.
	 * @param string $rationale Why.
	 * @param string $basis     The recorded basis (`Awb 4:14` or `override`).
	 */
	public function __construct(
		public readonly int $amount,
		public readonly string $unit,
		public readonly string $rationale,
		public readonly string $basis,
	) {
	}//end __construct()

	/**
	 * Refuse an empty rationale and a non-positive amount.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException When the rationale is empty or the amount is not positive.
	 */
	public function assertValid(): void {
		if (trim($this->rationale) === '') {
			throw new FlowTimerValidationException(message: 'An extension requires a non-empty rationale.');
		}

		if ($this->amount <= 0) {
			throw new FlowTimerValidationException(message: sprintf('An extension amount must be positive; got %d.', $this->amount));
		}
	}//end assertValid()

	/**
	 * The reason the ledger records: the rationale and what was added.
	 *
	 * @return string The reason.
	 */
	public function reason(): string {
		return sprintf('%s (+%d %s)', $this->rationale, $this->amount, $this->unit);
	}//end reason()
}//end class
