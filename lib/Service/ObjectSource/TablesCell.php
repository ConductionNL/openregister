<?php

/**
 * One Tables cell value with the descriptor of its column.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * A raw cell value and the column that says how to coerce it.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
final class TablesCell {
	/**
	 * Constructor.
	 *
	 * @param mixed                $value  The raw cell value, or null for an empty cell.
	 * @param array<string, mixed> $column The column descriptor.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly mixed $value,
		public readonly array $column,
	) {
	}//end __construct()
}//end class
