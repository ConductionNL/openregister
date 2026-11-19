<?php
/**
 * FileNodeLookupInterface: a stored file by its id.
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

use OCP\Files\File;

/**
 * The file node behind a file id, as Core reads it to render an object's file properties.
 *
 * Core asks; file storage answers through the service bound to this contract.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface FileNodeLookupInterface {

	/**
	 * The file node with a Nextcloud file id.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return File|null The file node, or null when there is none.
	 *
	 * @throws \Exception When the file cannot be accessed.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getFileById(int $fileId): ?File;
}//end interface
