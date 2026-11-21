<?php

/**
 * FileReferenceSearch
 *
 * One search of a magic table for rows that reference a file id: the table,
 * the file id and the data columns it compares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/files-sidebar-tabs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * The table, file id and searchable columns of one file-reference search.
 *
 * @spec openspec/specs/files-sidebar-tabs/spec.md
 */
final class FileReferenceSearch {

	/**
	 * The table's data columns, once listed.
	 *
	 * @var list<string>
	 */
	public array $columns = [];

	/**
	 * Constructor.
	 *
	 * @param string $tableName The magic table name (without prefix).
	 * @param int    $fileId    The file ID to search for.
	 *
	 * @spec openspec/specs/files-sidebar-tabs/spec.md
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly int $fileId,
	) {
	}//end __construct()
}//end class
