<?php

/**
 * A decision on one timer and the reason recorded for it.
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
 * Which timer a lifecycle decision is about, and why it was taken.
 */
final class TimerDecision {

	/**
	 * Constructor.
	 *
	 * @param string      $uuid   The timer uuid.
	 * @param string|null $reason Why, recorded in the ledger.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ?string $reason,
	) {
	}//end __construct()
}//end class
