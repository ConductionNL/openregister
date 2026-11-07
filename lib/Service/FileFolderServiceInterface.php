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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;

/**
 * Creating, resolving, listing, re-validating and removing the folder behind a register or object.
 */
interface FileFolderServiceInterface {

	/**
	 * Re-validate the access check on an existing object's bound folder.
	 *
	 * Used by `ObjectService::ensureObjectFolder` to close the
	 * defense-in-depth gap flagged on PR #1431: pre-PR cross-tenant
	 * bindings (rows where `_folder` references a node the current
	 * actor cannot read) would otherwise pass through subsequent
	 * saves that don't touch `@self.folder` because `setSelfMetadata`
	 * only fires when `@self.folder` is present in the write payload.
	 *
	 * Re-running the check on every save through `ensureObjectFolder`
	 * makes it apply uniformly. A denial throws
	 * `FolderAccessDeniedException` (HTTP 403) at the controller layer.
	 *
	 * No-op when the object has no folder bound or the bound value
	 * is non-numeric (legacy path-style folder, handled by the
	 * auto-create branch in ensureObjectFolder).
	 *
	 * **Acting user resolution.** `$currentUser` is forwarded verbatim
	 * and follows the documented precedence: when non-null it is used
	 * as-is, otherwise `IUserSession::getUser()` is consulted, otherwise
	 * the app's own principal is resolved when a system-operation scope
	 * is active. Non-HTTP callers SHOULD still pass an explicit
	 * `$currentUser` where they have one, so the check runs against the
	 * identity the work is being done for rather than the app account.
	 *
	 * @param ObjectEntity $object The existing object whose folder must be re-validated.
	 * @param IUser|null $currentUser Explicit acting user; falls back to session resolution.
	 *
	 * @return void
	 *
	 * @throws \OCA\OpenRegister\Exception\FolderAccessDeniedException When the acting user cannot access the bound folder.
	 *
	 * @spec openspec/specs/self-folder-access-control/spec.md
	 */
	public function assertObjectFolderAccessible(ObjectEntity $object, ?IUser $currentUser = null): void;

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
	 * @throws Exception If folder creation fails or entities not found
	 * @throws NotPermittedException If folder creation is not permitted
	 * @throws NotFoundException If parent folders do not exist
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
	 * @throws NotPermittedException When Nextcloud refuses to delete the folder.
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
	 * @throws Exception If the entity folder cannot be accessed
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

	/**
	 * Reset the per-request folder-access revalidation cache.
	 *
	 * `isReadable()` is a snapshot of mount/share/ACL/trash state. Within a
	 * single request that state CAN change — a cascade save may move or trash
	 * the parent folder. Because the cache lives for the FileService instance
	 * (≈ the whole request), a stale "accessible" verdict could otherwise
	 * survive such a mutation. Callers bound the cache to a single save API
	 * call by invoking this at the entry of `saveObject` / `saveObjects`, so
	 * each top-level write re-validates against current state.
	 *
	 * @return void
	 */
	public function resetFolderAccessRevalidationCache(): void;
}//end interface
