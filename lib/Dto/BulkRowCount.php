<?php

/**
 * BulkRowCount: how many rows a bulk save was handed and how many its result accounts for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

/**
 * The submitted and the accounted (saved + updated + unchanged) row counts of one bulk save.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkRowCount {

	/**
	 * Constructor.
	 *
	 * @param int $requested Rows the caller submitted.
	 * @param int $accounted Rows the result accounts for (saved + updated + unchanged).
	 */
	public function __construct(
		public readonly int $requested,
		public readonly int $accounted,
	) {
	}//end __construct()

	/**
	 * Rows neither accounted for nor rejected; never negative.
	 *
	 * @param int $rejected Rows the save rejected with a reason.
	 *
	 * @return int The rows that vanished without a reason.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function unaccounted(int $rejected): int {
		return max(0, ($this->requested - $this->accounted - $rejected));
	}//end unaccounted()
}//end class
