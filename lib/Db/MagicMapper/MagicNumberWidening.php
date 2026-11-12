<?php

/**
 * MagicNumberWidening: the new SQL type of one number column.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * A number column and the wider SQL type it moves to.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicNumberWidening {
	/**
	 * Constructor.
	 *
	 * @param string $column The physical column.
	 * @param string $type   The SQL type.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		public readonly string $column,
		public readonly string $type,
	) {
	}//end __construct()
}//end class
