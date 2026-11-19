<?php
/**
 * ObjectFolderLookupInterface: an object's Nextcloud folder.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\Folder;

/**
 * The folder an object's files live in, as Core reads it to remove it with a permanently deleted object.
 *
 * Core asks; file storage answers through the service bound to this contract.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface ObjectFolderLookupInterface {

	/**
	 * An object's folder by its stored id.
	 *
	 * @param ObjectEntity|string $objectEntity The object, or its uuid.
	 * @param int|string|null     $registerId   The object's register.
	 *
	 * @return Folder|null The folder, or null when it is not found.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getObjectFolder(ObjectEntity|string $objectEntity, int|string|null $registerId = null): ?Folder;
}//end interface
