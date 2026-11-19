<?php

/**
 * EntityFolderInterface: creating and removing the folder behind a register or object.
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
use OCA\OpenRegister\Db\Register;
use OCP\Files\Node;

/**
 * Creates the folder behind a register or object, and removes a deleted register's folder.
 *
 * Core asks (the register service provisions and removes register folders);
 * file storage answers. FileFolderServiceInterface extends it, so FileService
 * and every caller of FileFolderServiceInterface keep working.
 */
interface EntityFolderInterface {

	/**
	 * Creates a folder for either a Register or ObjectEntity and stores the folder ID.
	 *
	 * This unified method creates folders and stores the folder ID as an integer
	 * in the entity's folder property instead of using unstable path mapping.
	 * For ObjectEntity, it ensures the folder is nested under the register folder.
	 *
	 * @param Register|ObjectEntity $entity The entity to create a folder for
	 *
	 * @return Node|null The created folder Node or null if creation fails
	 *
	 * @throws \Exception If folder creation fails or entities not found
	 * @throws \OCP\Files\NotPermittedException If folder creation is not permitted
	 * @throws \OCP\Files\NotFoundException If parent folders do not exist
	 *
	 * @phpstan-return Node|null
	 *
	 * @spec openspec/specs/file-actions/spec.md#object-and-register-folder-provisioning
	 *   (unified entry: provisions the backing folder for a Register or ObjectEntity, degrading to null on failure)
	 */
	public function createEntityFolder(Register|ObjectEntity $entity): ?Node;

	/**
	 * Remove the folder of a register whose row was just deleted.
	 *
	 * @param Register $register The deleted register.
	 *
	 * @return bool True when the folder was removed.
	 *
	 * @throws \OCP\Files\NotPermittedException When Nextcloud refuses to delete the folder.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function deleteRegisterFolder(Register $register): bool;
}//end interface
