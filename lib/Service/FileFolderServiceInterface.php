<?php

/**
 * FileFolderServiceInterface: the register and object folders FileService provisions.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFolderAccessInterface;
use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * Creating, resolving, listing, re-validating and removing the folder behind a register or object.
 */
interface FileFolderServiceInterface extends ObjectFolderAccessInterface {

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

	/**
	 * Get files for either a Register or ObjectEntity.
	 *
	 * This unified method handles file retrieval for both entity types,
	 * using the stored folder IDs for stable access.
	 *
	 * @param Register|ObjectEntity $entity The entity to get files for
	 * @param bool|null $sharedFilesOnly Whether to return only shared files
	 *
	 * @return Node[]
	 *
	 * @throws \Exception If the entity folder cannot be accessed
	 *
	 * @psalm-return   list<\OCP\Files\Node>
	 * @phpstan-return array<int, Node>
	 *
	 * @spec openspec/specs/file-actions/spec.md#file-retrieval-resolves-by-id-or-name-and-projects-nodes-to-metadata
	 *   (unified file listing for a Register or ObjectEntity via the stored folder, with optional shared-only filter)
	 */
	public function getFilesForEntity(Register|ObjectEntity $entity, ?bool $sharedFilesOnly = false): array;

	/**
	 * Get an object folder by its stored ID.
	 *
	 * @param ObjectEntity|string $objectEntity The object entity to get the folder for
	 * @param int|string|null $registerId The register of the object to add the file to
	 *
	 * @return Folder|null The folder Node or null if not found
	 *
	 * @psalm-return   Folder|null
	 * @phpstan-return Folder|null
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getObjectFolder(ObjectEntity|string $objectEntity, int|string|null $registerId = null): ?Folder;

}//end interface
