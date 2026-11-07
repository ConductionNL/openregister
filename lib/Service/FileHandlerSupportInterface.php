<?php

/**
 * FileHandlerSupportInterface: what the file handlers in lib/Service/File call back on FileService.
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
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\Share\IShare;

/**
 * Ownership, path, tag and share helpers the file handlers reach through FileService, on top of
 * the object-file and folder operations.
 */
interface FileHandlerSupportInterface extends ObjectFilesInterface, FileFolderServiceInterface {

	/**
	 * Attach tags to a file.
	 *
	 * Delegates to TaggingHandler for single-responsibility tag attachment.
	 *
	 * @param string $fileId The file ID.
	 * @param array $tags Tags to associate with the file.
	 *
	 * @return void
	 *
	 * @phpstan-param array<int, string> $tags
	 * @psalm-param   array<int, string> $tags
	 *
	 * @spec openspec/specs/file-actions/spec.md#file-creation-and-upsert-run-a-fixed-validate-write-own-tag-pipeline
	 *   (the `object:<uuid>` + caller-tag attachment step of the create/upsert pipeline)
	 */
	public function attachTagsToFile(string $fileId, array $tags = []): void;

	/**
	 * Gate access to a file node on readability.
	 *
	 * Despite the name this never changes ownership: the delegate checks
	 * `Node::isReadable()` and throws when the session cannot read the node.
	 * The former repair path (`ownFile()` → `FileMapper::setFileOwnership()`)
	 * renamed the whole `home::openregister` storage to the session user and
	 * is gone (WOO-582).
	 *
	 * @param Node $file The file node to check access for
	 *
	 * @return void
	 *
	 * @throws NotPermittedException If the session cannot read the node
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @spec exclude Readability-guard delegation; throws when the session cannot read the node, no standalone business logic.
	 */
	public function checkOwnership(Node $file): void;

	/**
	 * Creates and returns a share link for a file (or folder).
	 *
	 * See https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html#create-a-new-share.
	 *
	 * @param string $path Path (from root) to the file/folder which should be shared
	 * @param int|null $shareType The share type (0=user, 1=group, 3=public link, 4=email, etc.)
	 * @param int|null $permissions Permissions (1=read, 2=update, 4=create, 8=delete, 16=share, 31=all)
	 *
	 * @throws Exception If creating the share link fails
	 *
	 * @return string The share link
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @spec exclude Public share-link creation; deferred to the file-actions FileSharingHandler follow-up pass (see file-actions tasks.md DROP list).
	 */
	public function createShareLink(string $path, ?int $shareType = 3, ?int $permissions = null): string;

	/**
	 * Clean and extract filename from a file path that may contain folder ID prefixes.
	 *
	 * This utility method handles the common pattern of cleaning file paths and extracting
	 * just the filename from paths that might be in formats like:
	 * - "filename.ext" -> "filename.ext"
	 * - "8010/filename.ext" -> "filename.ext"
	 * - "/path/to/filename.ext" -> "filename.ext"
	 *
	 * @param string $filePath The file path to process
	 *
	 * @return array{cleanPath: string, fileName: string} Array containing the cleaned path and extracted filename
	 *
	 * @psalm-return   array{cleanPath: string, fileName: string}
	 * @phpstan-return array{cleanPath: string, fileName: string}
	 *
	 * @spec exclude Pure string/path-splitting utility; no business logic.
	 */
	public function extractFileNameFromPath(string $filePath): array;

	/**
	 * Find shares for a given file or folder.
	 *
	 * Delegates to FileSharingHandler for single-responsibility sharing operations.
	 *
	 * @param Node $file The file or folder to find shares for.
	 * @param int $shareType The share type to filter by (default: public link = 3).
	 *
	 * @return IShare[] Array of shares.
	 *
	 * @psalm-return   array<IShare>
	 * @phpstan-return array<int, IShare>
	 *
	 * @spec exclude File sharing; deferred to the file-actions FileSharingHandler follow-up pass (see file-actions tasks.md DROP list).
	 */
	public function findShares(Node $file, int $shareType = 3): array;

	/**
	 * Generate the object tag for a given ObjectEntity.
	 *
	 * Delegates to TaggingHandler for single-responsibility tag generation.
	 *
	 * @param ObjectEntity|string $objectEntity The object entity to generate the tag for.
	 *
	 * @return string The object tag (e.g., 'object:uuid').
	 *
	 * @psalm-return   string
	 * @phpstan-return string
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function generateObjectTag(ObjectEntity|string $objectEntity): string;

	/**
	 * Returns a share link for the given IShare object.
	 *
	 * @param IShare $share An IShare object we are getting the share link for
	 *
	 * @return string The share link needed to get the file or folder for the given IShare object
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
	 */
	public function getShareLink(IShare $share): string;

	/**
	 * Gets or creates the OpenRegister user for file operations.
	 *
	 * Delegates to FileOwnershipHandler.
	 *
	 * @throws Exception If OpenRegister user cannot be created.
	 *
	 * @return IUser The OpenRegister user.
	 *
	 * @psalm-return   IUser
	 * @phpstan-return IUser
	 */
	public function getUser(): IUser;

	/**
	 * Publish a file by creating a public share link using direct database operations.
	 *
	 * @param ObjectEntity|string $object The object or object ID
	 * @param string|int $file The path to the file or file ID to publish
	 *
	 * @return File The published file
	 *
	 * @throws Exception If file publishing fails
	 * @throws NotFoundException If the file is not found
	 * @throws NotPermittedException If sharing is not permitted
	 *
	 * @psalm-return   File
	 * @phpstan-return File
	 *
	 * @spec exclude File publish via public share; deferred to the file-actions FilePublishingHandler
	 *   follow-up pass (see file-actions tasks.md DROP list).
	 */
	public function publishFile(ObjectEntity|string $object, string|int $file): File;

	/**
	 * Unpublish a file by removing its public share link.
	 *
	 * @param ObjectEntity|string $object The object or object ID
	 * @param string|int $filePath The path to the file to unpublish or file ID
	 *
	 * @return File The unpublished file
	 *
	 * @throws Exception If file unpublishing fails
	 * @throws NotFoundException If the file is not found
	 * @throws NotPermittedException If sharing operations are not permitted
	 *
	 * @psalm-return   File
	 * @phpstan-return File
	 *
	 * @spec exclude File unpublish (remove public share); deferred to the file-actions FilePublishingHandler
	 *   follow-up pass (see file-actions tasks.md DROP list).
	 */
	public function unpublishFile(ObjectEntity|string $object, string|int $filePath): File;
}//end interface
