<?php

/**
 * One table copy: the rows of a source table moved into a target table, column by column.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SharedSchema
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

namespace OCA\OpenRegister\Service\SharedSchema;

/**
 * One INSERT-SELECT copy of a shared schema split: the source table, the
 * target table and the columns that move, in order.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final readonly class TableCopy {

	/**
	 * Bundle the copy.
	 *
	 * @param string   $sourceTable The fully qualified source table.
	 * @param string   $targetTable The fully qualified target table.
	 * @param string[] $columns     The columns to copy, in order.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function __construct(
		public string $sourceTable,
		public string $targetTable,
		public array $columns,
	) {
	}//end __construct()
}//end class
