<?php

/**
 * One Dutch column moving to its English name in one shard table
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair\Support;

/**
 * The shard table, the Dutch source column and the English destination column.
 *
 * ADR-011 Rule 3: the run object RenameDutchColumns' column helpers share. It
 * never leaves that class.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary migration.
 */
final class ColumnMove {
	/**
	 * Constructor.
	 *
	 * @param string $table The shard table.
	 * @param string $old   The Dutch column.
	 * @param string $new   The English column.
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary migration.
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $old,
		public readonly string $new,
	) {
	}//end __construct()
}//end class
