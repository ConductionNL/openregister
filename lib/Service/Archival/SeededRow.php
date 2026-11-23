<?php

/**
 * One selectielijst row and what seeding it did.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The row as written, and the outcome: created, updated, unchanged, kept or failed.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class SeededRow {
	/**
	 * Hold the values.
	 *
	 * @param array  $row     The row.
	 * @param string $outcome created, updated, unchanged, kept or failed.
	 */
	public function __construct(
		public readonly array $row,
		public readonly string $outcome,
	) {
	}//end __construct()
}//end class
