<?php

/**
 * FileMapperInterface: the public surface of FileMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Files\Db;

use OCP\AppFramework\Db\Entity;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Every public method of FileMapper. Callers depend on this interface, not on the class.
 *
 * @phpstan-import-type FilecacheRow from FileMapper
 */
interface FileMapperInterface {

	/**
	 * Find an OR-side `File` row by its Nextcloud `filecache.fileid`.
	 *
	 * @param int $fileId The Nextcloud filecache fileid to look up.
	 *
	 * @return File|null The OR-side metadata row, or null when no row exists yet.
	 */
	public function findByFileId(int $fileId): ?File;

	/**
	 * Find or lazily create an OR-side `File` row for the given
	 * Nextcloud `filecache.fileid`. Useful when a write path
	 * (set description, increment download_count, acquire lock)
	 * needs an entity to mutate but the row may not exist yet.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 *
	 * @return File The persisted entity (existing or freshly created).
	 */
	public function findOrCreateByFileId(int $fileId): File;

	/**
	 * Set the OR-side description metadata for a Nextcloud file.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 * @param string|null $description The description, or null to clear.
	 *
	 * @return File The updated entity.
	 */
	public function setDescriptionForFile(int $fileId, ?string $description): File;

	/**
	 * Set the OR-side category for a Nextcloud file.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 * @param string|null $category The category, or null to clear.
	 *
	 * @return File The updated entity.
	 */
	public function setCategoryForFile(int $fileId, ?string $category): File;

	/**
	 * Set the OR-side labels for a Nextcloud file.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 * @param array|null $labels Labels (JSON-serialisable), or null to clear.
	 *
	 * @return File The updated entity.
	 */
	public function setLabelsForFile(int $fileId, ?array $labels): File;

	/**
	 * Set the publication window for a Nextcloud file.
	 *
	 * Both bounds are set together because they are one fact. Setting them
	 * separately invites the state where a depublication date precedes the
	 * publication date it belongs to, which reads as "published" to a careless
	 * comparison and as nothing at all to a careful one.
	 *
	 * @param int            $fileId      The Nextcloud filecache fileid.
	 * @param \DateTime|null $published   When the file becomes public, or null for never.
	 * @param \DateTime|null $depublished When it stops, or null for no end date.
	 *
	 * @return File The updated entity.
	 *
	 * @spec openspec/changes/file-publication-window/specs/file-publication-window/spec.md#requirement-a-file-carries-its-own-publication-window-req-fpw-101
	 */
	public function setPublicationWindowForFile(int $fileId, ?\DateTime $published, ?\DateTime $depublished): File;

	/**
	 * Increment the cached download count for a Nextcloud file.
	 * Idempotent: creates the row on first download.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 *
	 * @return File The updated entity with the incremented counter.
	 */
	public function incrementDownloadCount(int $fileId): File;

	/**
	 * Bulk fetch OR-side `File` rows for a list of Nextcloud fileids.
	 * One round trip vs. N+1.
	 *
	 * @param int[] $fileIds The Nextcloud filecache fileids to look up.
	 *
	 * @return array<int, File> Map of fileId => File for rows that exist.
	 */
	public function findByFileIds(array $fileIds): array;

	/**
	 * Get all files for a given node (parent) and/or file IDs with share information and owner data.
	 *
	 * @param int|null $node The parent node ID (optional)
	 * @param array|null $ids The file IDs to filter (optional)
	 *
	 * @return array<int, array> List of files as associative arrays with share information and owner data
	 *
	 * @phpstan-param  int|null $node
	 * @phpstan-param  array<int>|null $ids
	 * @phpstan-return list<FilecacheRow>
	 */
	public function getFiles(?int $node = null, ?array $ids = null): array;

	/**
	 * Get a single file by its fileid with share information and owner data.
	 *
	 * @param int $fileId The file ID
	 *
	 * @return array|null The file as an associative array with share information and owner data, or null if not found
	 *
	 * @phpstan-param  int $fileId
	 * @phpstan-return FilecacheRow|null
	 */
	public function getFile(int $fileId): ?array;

	/**
	 * Batch-load multiple files by their fileids in a single query (PERF-1).
	 *
	 * Avoids the N+1 pattern of calling getFile() once per file during list rendering.
	 * Returns a map keyed by the (string) fileid so callers can do O(1) lookups, with
	 * the same per-file shape (share urls, owner, published, etc.) as getFile().
	 *
	 * @param array $fileIds List of file ids (int|string) to load
	 *
	 * @return array<int|string, array> Map of fileid => file record. The key type
	 *         is int|string, not string: a stringified fileid is a canonical
	 *         numeric string, which PHP coerces to an int array key.
	 *
	 * @phpstan-param  array<int, int|string> $fileIds
	 * @phpstan-return array<int|string, FilecacheRow>
	 */
	public function getFilesByIds(array $fileIds): array;

	/**
	 * Get all files for a given ObjectEntity by using its folder property as the node id.
	 * If the folder property is empty, search oc_filecache for a row where name matches the object's uuid.
	 * If one result, use its fileid as node id; if more than one, throw an error; if zero, return empty array.
	 *
	 * @param ObjectEntity $object The object entity whose folder property is used as node id
	 *
	 * @return array<int, array> List of files as associative arrays with share information
	 *
	 * @throws \RuntimeException If more than one node is found for the object's uuid
	 *
	 * @phpstan-param  ObjectEntity $object
	 * @phpstan-return list<FilecacheRow>
	 */
	public function getFilesForObject(ObjectEntity $object): array;

	/**
	 * Batched lookup: file IDs for a list of object UUIDs.
	 *
	 * Resolves UUID -> folder node via oc_filecache.name (matching the fallback path of
	 * getFilesForObject), then fetches all child file IDs in a second query. Total of
	 * two queries regardless of input size, vs. N+1 if callers loop over getFilesForObject.
	 *
	 * Note: this method does NOT consider an object's explicit `folder` property — only
	 * pass UUIDs. Full file metadata (renderFiles via _extend[]=@self.files) still uses
	 * getFilesForObject which respects the explicit folder property.
	 *
	 * @param string[] $uuids Object UUIDs to look up.
	 *
	 * @return array<string, int[]> Map of UUID -> list of file IDs (empty array if none).
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Two-step batched lookup with input
	 *                                               validation; complexity is intrinsic.
	 */
	public function getFileIdsForObjects(array $uuids): array;

	/**
	 * Resolve the owning object's UUID for a Nextcloud `filecache.fileid`.
	 *
	 * Inverse of the {@see getFilesForObject()} fallback path: an object's attached
	 * files live under a folder node whose `filecache.name` equals the object's UUID.
	 * This looks up the file's parent folder and returns that folder's name.
	 *
	 * Best-effort only: an object with an explicit `folder` property pointing at a
	 * node whose name is NOT the object UUID (e.g. a manually re-parented folder)
	 * will not resolve here. Callers MUST treat a `null` return as "no owning object
	 * found" and skip silently rather than error — this mirrors the same convention
	 * `getFilesForObject()` already relies on for its own fallback path.
	 *
	 * SECURITY BOUNDARY: this join is intentionally NOT scoped by storage / tenant /
	 * owner — `filecache.fileid` is globally unique across all storages and any
	 * returned UUID is treated as an *unauthenticated candidate*. The RBAC /
	 * multitenancy safety net lives ONE layer up in
	 * {@see ContentSearchHandler::resolveOwningObject()}, which passes the UUID to
	 * `MagicMapper::find($uuid, _rbac: $callerRbac, _multitenancy: $callerMultitenancy)`.
	 * Those flags are propagated from the outer caller (see
	 * `QueryHandler::searchObjectsPaginatedDatabase`) and default to `true` on the
	 * ContentSearchHandler entry point. **When the outer caller opts out of either
	 * flag** (system contexts, batch jobs, some test paths using
	 * `searchObjectsPaginatedDatabase(_rbac: false, _multitenancy: false)`) **this
	 * method's join becomes an unbounded read across tenants and the caller must
	 * supply its own scope guard** — the built-in safety net collapses. Do NOT
	 * reuse this method in contexts that bypass the follow-up `MagicMapper::find()`
	 * call OR that call it with the RBAC/multitenancy flags disabled, or a
	 * low-privileged user could probe cross-tenant chunk-content by guessing
	 * monotonic fileids. Defence-in-depth (join in `oc_storages` and filter to
	 * accessible storages up-front) is a follow-up if we ever call this from a
	 * hotter path or expose it to callers that opt out of RBAC.
	 *
	 * @param int $fileId The Nextcloud filecache fileid to resolve.
	 *
	 * @return string|null The owning object's UUID, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function findOwningObjectUuid(int $fileId): ?string;

	/**
	 * Publish a file by creating a public share directly in the database.
	 *
	 * @param int $fileId The file ID to publish
	 * @param string $sharedBy The user who is sharing the file
	 * @param string $shareOwner The owner of the file
	 * @param \DateTime|null $depublished When the share stops working, or null for no end date
	 *
	 * @return (int|string)[]
	 *
	 * @spec openspec/changes/file-publication-window/specs/file-publication-window/spec.md#requirement-a-depublication-date-expires-the-public-share-req-fpw-102
	 *
	 * @throws \Exception If the share creation fails
	 *
	 * @phpstan-param int $fileId
	 * @phpstan-param string $sharedBy
	 * @phpstan-param string $shareOwner
	 *
	 * @phpstan-return array{id: int, token: string, accessUrl: string, downloadUrl: string, published: string}
	 *
	 * @psalm-return array{id: int, token: string, accessUrl: string, downloadUrl: string, published: string}
	 */
	public function publishFile(int $fileId, string $sharedBy, string $shareOwner, ?\DateTime $depublished = null): array;

	/**
	 * Depublish a file by removing all public shares directly from the database.
	 *
	 * @param int $fileId The file ID to depublish
	 *
	 * @return array Information about the deletion operation
	 *
	 * @throws \Exception If the share deletion fails
	 *
	 * @phpstan-param  int $fileId
	 * @phpstan-return array{deleted_shares: int, file_id: int}
	 */
	public function depublishFile(int $fileId): array;

	/**
	 * Check whether a file has at least one active public share (share_type=3).
	 *
	 * Used by anonymous-accessible endpoints (e.g. preview, download)
	 * to gate access — only published files are exposed to unauthenticated
	 * callers.
	 *
	 * @param int $fileId The file ID to check.
	 *
	 * @return bool True when a public share exists, false otherwise.
	 *
	 * @phpstan-param  int $fileId
	 * @phpstan-return bool
	 */
	public function isFilePublished(int $fileId): bool;

	/**
	 * Count all files in the Nextcloud installation
	 *
	 * @return int Total number of files in oc_filecache
	 *
	 * @phpstan-return int
	 */
	public function countAllFiles(): int;

	/**
	 * Get total storage size of all files in the Nextcloud installation
	 *
	 * @return int Total size in bytes of all files in oc_filecache
	 *
	 * @phpstan-return int
	 */
	public function getTotalFilesSize(): int;

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

	/**
	 * Find files in Nextcloud that are not tracked in the extraction system yet
	 *
	 * This queries oc_filecache for files that don't have a corresponding record
	 * in oc_openregister_file_texts. These are "untracked" files that need to be
	 * added to the extraction system.
	 *
	 * Only includes user files from home:: storages, excluding:
	 * - Directories
	 * - System files (appdata, previews, etc)
	 * - Trashed files
	 * - External/temporary storages
	 *
	 * @param int $limit  Maximum number of untracked files to return
	 * @param int $offset Number of rows to skip. Files that fail extraction keep
	 *                    matching this query — nothing records the failure — and
	 *                    the fileid ordering keeps them at the head of every
	 *                    window. The offset lets a caller step over them instead
	 *                    of re-reading the same unreadable files forever
	 *                    (WOO-576).
	 *
	 * @return array List of untracked files with basic metadata
	 *
	 * @phpstan-param  int $limit
	 * @phpstan-param  int $offset
	 * @phpstan-return list<array{
	 *     fileid: int, path: string, name: string, mimetype: string,
	 *     size: int, mtime: int, checksum: string|null
	 * }>
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function findUntrackedFiles(int $limit = 100, int $offset = 0): array;

	/**
	 * Count untracked files
	 *
	 * Count files that exist in Nextcloud but haven't been tracked in file_texts table
	 *
	 * @return int Number of untracked files
	 */
	public function countUntrackedFiles(): int;

	/**
	 * Insert through the mapper (QBMapper).
	 *
	 * @param File $entity The entity.
	 *
	 * @return File The stored entity.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update through the mapper (QBMapper).
	 *
	 * @param File $entity The entity.
	 *
	 * @return File The stored entity.
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete through the mapper (QBMapper).
	 *
	 * @param File $entity The entity.
	 *
	 * @return File The stored entity.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * InsertOrUpdate through the mapper (QBMapper).
	 *
	 * @param File $entity The entity.
	 *
	 * @return File The stored entity.
	 */
	public function insertOrUpdate(Entity $entity): Entity;
}//end interface
