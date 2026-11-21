<?php

/**
 * OpenRegister BandPosition
 *
 * Where one band sits in a rate schedule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * The position of a band in its schedule: its number for the refusal message,
 * and whether it is the last band, the only one that may leave out upTo.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class BandPosition {

	/**
	 * Constructor.
	 *
	 * @param int  $number The band's position, counted from 1.
	 * @param bool $isLast Whether this is the last band of the schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly int $number,
		public readonly bool $isLast,
	) {
	}//end __construct()
}//end class
