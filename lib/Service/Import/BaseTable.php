<?php

/**
 * BaseTable: one table of a base read from another product.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

/**
 * A table of a base: its columns and its rows.
 *
 * Each row is keyed by the source product's row id (as a string) and holds
 * its values by column name. A link cell holds the list of linked row ids.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */
final class BaseTable {

	/**
	 * Constructor.
	 *
	 * @param string                              $name    The table's name in the source product.
	 * @param array<int, BaseColumn>              $columns The columns, in source order.
	 * @param array<string, array<string, mixed>> $rows    The rows by source row id, values by column name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $columns,
		public readonly array $rows,
	) {
	}//end __construct()

	/**
	 * Whether the table holds a row with this source id.
	 *
	 * @param string $rowId The source row id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function hasRow(string $rowId): bool {
		return array_key_exists($rowId, $this->rows);
	}//end hasRow()
}//end class
