<?php
/**
 * ObjectFolderAccessInterface: the access check on an object's bound folder, as the object save asks it.
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
use OCP\IUser;

/**
 * The access check on an object's bound folder, as the object save (Core) asks it.
 *
 * The object save re-validates the bound folder and resets the per-request
 * verdicts at its entry; file storage answers, through
 * FileFolderServiceInterface, which extends this.
 *
 * @spec openspec/specs/self-folder-access-control/spec.md
 */
interface ObjectFolderAccessInterface {

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
	 *
	 * @spec openspec/specs/self-folder-access-control/spec.md
	 */
	public function resetFolderAccessRevalidationCache(): void;
}//end interface
