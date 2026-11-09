<?php

/**
 * A control value that would cross a floor: which control, which floor, what
 * was asked for, and in which direction the floor holds.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * A floor that a proposed value would cross.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
final class FloorBreach {
	/**
	 * Constructor.
	 *
	 * @param string $control    The control that would have been weakened.
	 * @param int    $floor      The floor in force.
	 * @param int    $proposed   The value that was asked for.
	 * @param string $comparator `atLeast` or `atMost`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $control,
		public readonly int $floor,
		public readonly int $proposed,
		public readonly string $comparator,
	) {
	}//end __construct()
}//end class
