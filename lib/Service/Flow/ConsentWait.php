<?php

/**
 * How long a parked run may wait for consent, judged at one moment.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;

/**
 * The consent wait of one sweep: the moment it judges at and the hours a
 * parked run may wait before it is failed.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
final class ConsentWait {
	/**
	 * Constructor.
	 *
	 * @param DateTime $now   The moment the sweep judges at.
	 * @param integer  $hours How long a parked run may wait.
	 */
	public function __construct(
		public readonly DateTime $now,
		public readonly int $hours,
	) {
	}//end __construct()
}//end class
