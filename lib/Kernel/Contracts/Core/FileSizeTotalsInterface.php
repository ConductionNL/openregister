<?php

/**
 * FileSizeTotalsInterface: the total stored size of a set of files.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Sums the stored size of files by their file cache ids.
 *
 * Core asks (an organisation's storage usage is the size of its files);
 * file storage answers. FileMapperInterface extends it, so FileMapper and every
 * caller of FileMapperInterface keep working.
 */
interface FileSizeTotalsInterface {

	/**
	 * The summed size of the given files or folders.
	 *
	 * A folder's size in the file cache is the size of everything under it. A
	 * negative size means the entry was never scanned and is left out.
	 *
	 * @param list<int> $fileIds The file cache ids.
	 *
	 * @return int The total size in bytes, 0 for no ids.
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	public function sumSizesByFileIds(array $fileIds): int;
}//end interface
