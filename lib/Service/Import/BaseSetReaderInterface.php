<?php

/**
 * BaseSetReaderInterface: read a base export of one product into a neutral set.
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

use InvalidArgumentException;

/**
 * Reads one product's base export into a BaseSet.
 *
 * A source adapter that reads a running installation produces the same
 * BaseSet, so both land on the same proposal and import path.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */
interface BaseSetReaderInterface {

	/**
	 * The product this reader reads (e.g. baserow), the format name callers pass.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function format(): string;

	/**
	 * Read an export file into a set of tables.
	 *
	 * @param string $filePath Path to the export (archive or JSON) on disk.
	 *
	 * @return BaseSet The base.
	 *
	 * @throws InvalidArgumentException When the file is not a readable export of this product.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function read(string $filePath): BaseSet;
}//end interface
