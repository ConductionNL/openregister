<?php

/**
 * TimerAnchorMove: a timer whose anchoring event moved, with the new anchor.
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

use DateTimeInterface;

/**
 * The timer whose anchoring event moved, and the new instant of that event.
 *
 * @spec openspec/specs/flow-business-timers/spec.md
 */
final class TimerAnchorMove {

	/**
	 * Constructor
	 *
	 * @param string            $uuid          The timer to supersede.
	 * @param DateTimeInterface $anchorEventAt The new instant of the anchoring event.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly DateTimeInterface $anchorEventAt,
	) {
	}//end __construct()
}//end class
