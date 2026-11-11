<?php

/**
 * The value one hardening control has in force, against its declared floor.
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
 * The value in force, the declared floor, which way is stronger, and what the value counts.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
final class HardeningReading {

	/**
	 * Constructor.
	 *
	 * @param int|null $value      The value in force, or null when it cannot be read.
	 * @param int      $floor      The declared floor.
	 * @param string   $comparator `atLeast` or `atMost`, naming which way is stronger.
	 * @param string   $unit       What the value counts: `seconds`, `attempts`, `bytes`, `entries` or `switch`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?int $value,
		public readonly int $floor,
		public readonly string $comparator,
		public readonly string $unit,
	) {

	}//end __construct()
}//end class
