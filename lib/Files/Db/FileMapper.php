<?php

/**
 * FileMapper
 *
 * This file contains the class for handling read-only file operations
 * on the oc_filecache table with share information from oc_share table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Files\Db;

use DateTime;
use Exception;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Class FileMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * The alias below describes a filecache ROW, not the {@see File} entity. It was
 * named `File`, which shadowed that entity in every docblock in this file: a
 * mapper method annotated `@return File` read as an array shape, which is why
 * the phpstan baseline carried an entry for each one. Renamed rather than
 * baselined.
 *
 * @phpstan-type FilecacheRow array{
 *   fileid: int,
 *   storage: int,
 *   path: string,
 *   path_hash: string,
 *   parent: int,
 *   name: string,
 *   mimetype: string,
 *   mimepart: string,
 *   size: int,
 *   mtime: int,
 *   storage_mtime: int,
 *   encrypted: int,
 *   unencrypted_size: int,
 *   etag: string,
 *   permissions: int,
 *   checksum: string,
 *   share_token: string|null,
 *   share_stime: int|null,
 *   storage_id: string|null,
 *   owner: string|null,
 *   accessUrl: string|null,
 *   downloadUrl: string|null,
 *   published: string|null
 * }
 *
 * @template-extends QBMapper<File>
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Read-only filecache/oc_share query surface;
 *   each method is one focused query, splitting the mapper would scatter it without
 *   reducing complexity.
 */
class FileMapper extends QBMapper implements FileMapperInterface {

	/**
	 * The permissions of a public share: read only.
	 */
	private const PUBLIC_SHARE_PERMISSIONS = 1;

	/**
	 * The text patterns of the untracked-file filter, in the order they are bound.
	 */
	private const UNTRACKED_FILE_PATTERNS = [
		'dirType'     => 'httpd/unix-directory',
		'homePattern' => 'home::%',
		'trashPat'    => '%files_trashbin%',
		'appdataPat'  => 'appdata_%',
		'versionPat'  => '%files_versions%',
		'cachePat'    => '%cache%',
		'thumbPat'    => '%thumbnails%',
		'filesPat'    => 'files/%',
	];

	/**
	 * The filecache, mimetype, share and storage columns a file read returns.
	 */
	private const FILECACHE_COLUMNS = [
		'fc.fileid',
		'fc.storage',
		'fc.path',
		'fc.path_hash',
		'fc.parent',
		'fc.name',
		'mt.mimetype',
		'mp.mimetype as mimepart',
		'fc.size',
		'fc.mtime',
		'fc.storage_mtime',
		'fc.encrypted',
		'fc.unencrypted_size',
		'fc.etag',
		'fc.permissions',
		'fc.checksum',
		's.token as share_token',
		's.stime as share_stime',
		'st.id as storage_id',
	];

	/**
	 * Builds share and file links for the host the visitor used
	 *
	 * @var ShareUrlBuilder
	 */
	private readonly ShareUrlBuilder $shareUrls;

	/**
	 * Constructor
	 *
	 * @param IDBConnection $db Database connection
	 * @param ShareUrlBuilder $shareUrls Share and file link builder
	 */
	public function __construct(
		IDBConnection $db,
		ShareUrlBuilder $shareUrls,
	) {
		parent::__construct(db: $db, tableName: 'openregister_files', entityClass: File::class);
		$this->shareUrls = $shareUrls;
	}//end __construct()

	/**
	 * Find an OR-side `File` row by its Nextcloud `filecache.fileid`.
	 *
	 * @param int $fileId The Nextcloud filecache fileid to look up.
	 *
	 * @return File|null The OR-side metadata row, or null when no row exists yet.
	 */
	public function findByFileId(int $fileId): ?File {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_files')
			->where(
				$qb->expr()->eq(
					'file_id',
					$qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)
				)
			);

		try {
			return $this->findEntity(query: $qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}//end findByFileId()

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
	public function findOrCreateByFileId(int $fileId): File {
		$existing = $this->findByFileId(fileId: $fileId);
		if ($existing !== null) {
			return $existing;
		}

		$file = new File();
		$file->setFileId($fileId);
		$file->setDownloadCount(0);
		$file->setCreated(new DateTime());

		return $this->insert(entity: $file);
	}//end findOrCreateByFileId()

	/**
	 * Set the OR-side description metadata for a Nextcloud file.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 * @param string|null $description The description, or null to clear.
	 *
	 * @return File The updated entity.
	 */
	public function setDescriptionForFile(int $fileId, ?string $description): File {
		$file = $this->findOrCreateByFileId(fileId: $fileId);
		$file->setDescription($description);
		$file->setUpdated(new DateTime());
		return $this->update(entity: $file);
	}//end setDescriptionForFile()

	/**
	 * Set the OR-side category for a Nextcloud file.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 * @param string|null $category The category, or null to clear.
	 *
	 * @return File The updated entity.
	 */
	public function setCategoryForFile(int $fileId, ?string $category): File {
		$file = $this->findOrCreateByFileId(fileId: $fileId);
		$file->setCategory($category);
		$file->setUpdated(new DateTime());
		return $this->update(entity: $file);
	}//end setCategoryForFile()

	/**
	 * Set the OR-side labels for a Nextcloud file.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 * @param array|null $labels Labels (JSON-serialisable), or null to clear.
	 *
	 * @return File The updated entity.
	 */
	public function setLabelsForFile(int $fileId, ?array $labels): File {
		$file = $this->findOrCreateByFileId(fileId: $fileId);
		$file->setLabels($labels);
		$file->setUpdated(new DateTime());
		return $this->update(entity: $file);
	}//end setLabelsForFile()

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
	public function setPublicationWindowForFile(
		int $fileId,
		?\DateTime $published,
		?\DateTime $depublished
	): File {
		$file = $this->findOrCreateByFileId(fileId: $fileId);
		$file->setPublished($published);
		$file->setDepublished($depublished);
		$file->setUpdated(new DateTime());
		return $this->update(entity: $file);
	}//end setPublicationWindowForFile()

	/**
	 * Increment the cached download count for a Nextcloud file.
	 * Idempotent: creates the row on first download.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 *
	 * @return File The updated entity with the incremented counter.
	 */
	public function incrementDownloadCount(int $fileId): File {
		$file = $this->findOrCreateByFileId(fileId: $fileId);
		$current = ($file->getDownloadCount() ?? 0);
		$file->setDownloadCount($current + 1);
		$file->setUpdated(new DateTime());
		return $this->update(entity: $file);
	}//end incrementDownloadCount()

	/**
	 * Bulk fetch OR-side `File` rows for a list of Nextcloud fileids.
	 * One round trip vs. N+1.
	 *
	 * @param int[] $fileIds The Nextcloud filecache fileids to look up.
	 *
	 * @return array<int, File> Map of fileId => File for rows that exist.
	 */
	public function findByFileIds(array $fileIds): array {
		if (empty($fileIds) === true) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_files')
			->where(
				$qb->expr()->in(
					'file_id',
					$qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)
				)
			);

		$entities = $this->findEntities(query: $qb);
		$map = [];
		foreach ($entities as $entity) {
			$map[(int)$entity->getFileId()] = $entity;
		}

		return $map;
	}//end findByFileIds()

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
	public function getFiles(?int $node = null, ?array $ids = null): array {
		$qb = $this->db->getQueryBuilder();
		$this->selectFilecacheWithShares(qb: $qb);
		if ($node !== null) {
			$qb->andWhere($qb->expr()->eq('fc.parent', $qb->createNamedParameter($node, IQueryBuilder::PARAM_INT)));
		}

		if ($ids !== null && count($ids) > 0) {
			$qb->andWhere($qb->expr()->in('fc.fileid', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		}

		return array_map(fn (array $row): array => $this->withLinksAndOwner(row: $row), $this->fetchRows(qb: $qb));
	}//end getFiles()

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
	public function getFile(int $fileId): ?array {
		// Create a new query builder instance.
		$qb = $this->db->getQueryBuilder();

		$this->selectFilecacheWithShares(qb: $qb);
		$qb->where($qb->expr()->eq('fc.fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		// Execute the query and fetch the result using proper Nextcloud method.
		$result = $qb->executeQuery();
		$file = $result->fetch();
		$result->closeCursor();

		// Return null if file not found.
		if ($file === false) {
			return null;
		}

		return $this->withLinksAndOwner(row: $file);
	}//end getFile()

	/**
	 * Select filecache rows with their mimetype, public link share and storage.
	 *
	 * @param IQueryBuilder $qb The builder to select on.
	 *
	 * @return void
	 */
	private function selectFilecacheWithShares(IQueryBuilder $qb): void {
		$qb->select(...self::FILECACHE_COLUMNS)
			->from('filecache', 'fc')
			->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
			->leftJoin('fc', 'mimetypes', 'mp', $qb->expr()->eq('fc.mimepart', 'mp.id'));
		$publicLink = $qb->expr()->andX(
			$qb->expr()->eq('s.file_source', 'fc.fileid'),
			$qb->expr()->eq('s.share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT))
		);
		$qb->leftJoin('fc', 'share', 's', $publicLink)
			->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'));
	}//end selectFilecacheWithShares()

	/**
	 * Add the access and download links, publication time and owner to a filecache row.
	 *
	 * A row with a public link share gets the share links, any other row the
	 * links that need a login.
	 *
	 * @param array $row The filecache row as selected by selectFilecacheWithShares().
	 *
	 * @return array The row with accessUrl, downloadUrl, published and owner.
	 */
	private function withLinksAndOwner(array $row): array {
		$row['accessUrl'] = $this->generateAuthenticatedAccessUrl(fileId: $row['fileid']);
		$row['downloadUrl'] = $this->generateAuthenticatedDownloadUrl(fileId: $row['fileid']);
		if (empty($row['share_token']) === false) {
			$row['accessUrl'] = $this->generateShareUrl(token: $row['share_token']);
			$row['downloadUrl'] = $this->generateShareUrl(token: $row['share_token']) . '/download';
		}

		$row['published'] = null;
		if (empty($row['share_stime']) === false) {
			$row['published'] = (new DateTime())->setTimestamp($row['share_stime'])->format('c');
		}

		$row['owner'] = $this->storageOwner(storageId: $row['storage_id']);
		return $row;
	}//end withLinksAndOwner()

	/**
	 * The owner a storage id names: the user of a `home::<user>` storage, else the id itself.
	 *
	 * @param string|null $storageId The storage id.
	 *
	 * @return string|null The owner, or null for an empty storage id.
	 */
	private function storageOwner(?string $storageId): ?string {
		if (empty($storageId) === true) {
			return null;
		}

		if (str_starts_with($storageId, 'home::') === true) {
			return substr($storageId, 6);
		}

		return $storageId;
	}//end storageOwner()

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
	public function getFilesByIds(array $fileIds): array {
		// Normalise to unique positive integers; ignore non-numeric entries.
		$normalisedIds = [];
		foreach ($fileIds as $fileId) {
			if (is_numeric($fileId) === true) {
				$normalisedIds[(int)$fileId] = (int)$fileId;
			}
		}

		if (empty($normalisedIds) === true) {
			return [];
		}

		// Reuse the existing batch query (single WHERE fileid IN (...)).
		$files = $this->getFiles(node: null, ids: array_values($normalisedIds));

		$filesById = [];
		foreach ($files as $file) {
			$filesById[(string)$file['fileid']] = $file;
		}

		return $filesById;
	}//end getFilesByIds()

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
	public function getFilesForObject(ObjectEntity $object): array {
		// Retrieve the folder property from the object entity.
		$folder = $object->getFolder();

		// If folder is set, use it as the node id.
		if ($folder !== null) {
			$nodeId = (int)$folder;
			return $this->getFiles(node: $nodeId);
		}

		// If folder is not set, search oc_filecache for a node with name equal to the object's uuid.
		$uuid = $object->getUuid();
		if ($uuid === null) {
			// If uuid is not set, return empty array.
			return [];
		}

		// Create a new query builder instance.
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid')
			->from('filecache')
			->where($qb->expr()->eq('name', $qb->createNamedParameter($uuid)));

		// Execute the query and fetch all matching rows using proper Nextcloud method.
		$result = $qb->executeQuery();
		$rows = [];

		// Fetch all rows manually.
		$row = $result->fetch();
		while ($row !== false) {
			$rows[] = $row;
			$row = $result->fetch();
		}

		$result->closeCursor();

		// Handle the number of results.
		$count = count($rows);
		if ($count === 1) {
			// Use the fileid as the node id.
			$nodeId = (int)$rows[0]['fileid'];
			return $this->getFiles(node: $nodeId);
		}

		if ($count > 1) {
			// Multiple folders found with same UUID - pick the oldest one (lowest fileid).
			// TODO: Add nightly cron job to cleanup orphaned folders and logs.
			usort(
				$rows,
				function ($a, $b) {
					return (int)$a['fileid'] - (int)$b['fileid'];
				}
			);
			$oldestNodeId = (int)$rows[0]['fileid'];
			return $this->getFiles(node: $oldestNodeId);
		}

		// No results found, return empty array.
		return [];
	}//end getFilesForObject()

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
	public function getFileIdsForObjects(array $uuids): array {
		$result = [];
		foreach ($uuids as $uuid) {
			if (is_string($uuid) === true && $uuid !== '') {
				$result[$uuid] = [];
			}
		}

		if (empty($result) === true) {
			return [];
		}

		// Step 1: resolve UUIDs to folder node IDs via filecache.name.
		// Multiple folders may share a UUID name; the oldest (lowest fileid) wins,
		// matching the resolution rule in getFilesForObject.
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid', 'name')
			->from('filecache')
			->where(
				$qb->expr()->in(
					'name',
					$qb->createNamedParameter(array_keys($result), IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
			->orderBy('fileid', 'ASC');

		$stmt = $qb->executeQuery();
		$uuidToNodeId = [];
		$row = $stmt->fetch();
		while ($row !== false) {
			$name = (string)$row['name'];
			// First (oldest) folder wins for duplicates.
			if (isset($uuidToNodeId[$name]) === false) {
				$uuidToNodeId[$name] = (int)$row['fileid'];
			}

			$row = $stmt->fetch();
		}

		$stmt->closeCursor();

		if (empty($uuidToNodeId) === true) {
			return $result;
		}

		// Step 2: fetch child file IDs for each folder node in one query.
		$qb2 = $this->db->getQueryBuilder();
		$qb2->select('fileid', 'parent')
			->from('filecache')
			->where(
				$qb2->expr()->in(
					'parent',
					$qb2->createNamedParameter(array_values($uuidToNodeId), IQueryBuilder::PARAM_INT_ARRAY)
				)
			);

		$stmt2 = $qb2->executeQuery();
		$nodeIdToFileIds = [];
		$childRow = $stmt2->fetch();
		while ($childRow !== false) {
			$parent = (int)$childRow['parent'];
			$nodeIdToFileIds[$parent][] = (int)$childRow['fileid'];
			$childRow = $stmt2->fetch();
		}

		$stmt2->closeCursor();

		// Step 3: project node IDs back to UUIDs.
		foreach ($uuidToNodeId as $uuid => $nodeId) {
			$result[$uuid] = $nodeIdToFileIds[$nodeId] ?? [];
		}

		return $result;
	}//end getFileIdsForObjects()

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
	public function findOwningObjectUuid(int $fileId): ?string {
		$qb = $this->db->getQueryBuilder();
		// SECURITY: unscoped filecache join — see docblock. Safety comes from
		// the MagicMapper::find() call in the sole caller (ContentSearchHandler).
		$qb->select('parentNode.name')
			->from('filecache', 'file')
			->innerJoin('file', 'filecache', 'parentNode', $qb->expr()->eq('file.parent', 'parentNode.fileid'))
			->where($qb->expr()->eq('file.fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$name = $result->fetchOne();
		$result->closeCursor();

		if ($name === false || $name === null || $name === '') {
			return null;
		}

		return (string)$name;
	}//end findOwningObjectUuid()

	/**
	 * Generate a share URL from a share token.
	 *
	 * @param string $token The share token
	 *
	 * @phpstan-param string $token
	 *
	 * @return string
	 *
	 * @phpstan-return string
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
	 */
	private function generateShareUrl(string $token): string {
		return $this->shareUrls->shareLink(token: $token);
	}//end generateShareUrl()

	/**
	 * Generate an authenticated access URL for a file (requires login).
	 *
	 * This URL uses Nextcloud's file preview/access endpoint which requires
	 * the user to be authenticated to access the file.
	 *
	 * @param int $fileId The file ID
	 *
	 * @phpstan-param int $fileId
	 *
	 * @return string
	 *
	 * @phpstan-return string
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
	 */
	private function generateAuthenticatedAccessUrl(int $fileId): string {
		return $this->shareUrls->absolute(path: '/index.php/core/preview?fileId=' . $fileId . '&x=1920&y=1080&a=1');
	}//end generateAuthenticatedAccessUrl()

	/**
	 * Generate an authenticated download URL for a file (requires login).
	 *
	 * This URL uses Nextcloud's direct download endpoint which requires
	 * the user to be authenticated to download the file.
	 *
	 * @param int $fileId The file ID
	 *
	 * @phpstan-param int $fileId
	 *
	 * @return string
	 *
	 * @phpstan-return string
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
	 */
	private function generateAuthenticatedDownloadUrl(int $fileId): string {
		return $this->shareUrls->absolute(path: '/index.php/apps/openregister/api/files/' . $fileId . '/download');
	}//end generateAuthenticatedDownloadUrl()

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
	public function publishFile(
		int $fileId,
		string $sharedBy,
		string $shareOwner,
		?\DateTime $depublished = null
	): array {
		// Check if a public share already exists for this file.
		$existingShare = $this->getPublicShare(fileId: $fileId);
		if ($existingShare !== null) {
			return $this->shareInfo(share: $existingShare);
		}

		return $this->createPublicShare(
			request: new PublicShareRequest(fileId: $fileId, sharedBy: $sharedBy, shareOwner: $shareOwner, depublished: $depublished)
		);
	}//end publishFile()

	/**
	 * Create the public link share and answer what was created.
	 *
	 * @param PublicShareRequest $request The file, the sharer, the owner and the end of the window.
	 *
	 * @return array{id: int, token: string, accessUrl: string, downloadUrl: string, published: string}
	 *
	 * @throws \Exception If the share creation fails
	 */
	private function createPublicShare(PublicShareRequest $request): array {
		// Generate a unique token for the share, then insert it.
		$row     = self::shareRow(request: $request, token: $this->generateShareToken());
		$shareId = $this->insertShare(row: $row);

		return $this->shareInfo(share: ['id' => $shareId, 'token' => $row['token'][0], 'stime' => $row['stime'][0]]);
	}//end createPublicShare()

	/**
	 * The share row, column by column, as `[value, parameter type]`, in insert order.
	 *
	 * @param PublicShareRequest $request The file, the sharer, the owner and the end of the window.
	 * @param string             $token   The new share's token.
	 *
	 * @return array<string, array{0: mixed, 1: int|string}> The row.
	 */
	private static function shareRow(PublicShareRequest $request, string $token): array {
		return [
			// 3 = public link.
			'share_type' => [3, IQueryBuilder::PARAM_INT],
			'share_with' => [null, IQueryBuilder::PARAM_STR],
			'password' => [null, IQueryBuilder::PARAM_STR],
		] + self::shareSourceColumns(request: $request) + self::sharePublicationColumns(request: $request, token: $token);
	}//end shareRow()

	/**
	 * Who shares what: the owner, the initiator and the file.
	 *
	 * @param PublicShareRequest $request The file, the sharer and the owner.
	 *
	 * @return array<string, array{0: mixed, 1: int|string}> The columns.
	 */
	private static function shareSourceColumns(PublicShareRequest $request): array {
		return [
			'uid_owner' => [$request->shareOwner, IQueryBuilder::PARAM_STR],
			'uid_initiator' => [$request->sharedBy, IQueryBuilder::PARAM_STR],
			'parent' => [null, IQueryBuilder::PARAM_STR],
			'item_type' => ['file', IQueryBuilder::PARAM_STR],
			'item_source' => [$request->fileId, IQueryBuilder::PARAM_INT],
			'item_target' => ['/' . $request->fileId, IQueryBuilder::PARAM_STR],
			'file_source' => [$request->fileId, IQueryBuilder::PARAM_INT],
			'file_target' => ['/' . $request->fileId, IQueryBuilder::PARAM_STR],
		];
	}//end shareSourceColumns()

	/**
	 * How the link is published: read only, from now, until the end of the window.
	 *
	 * @param PublicShareRequest $request The end of the window.
	 * @param string             $token   The new share's token.
	 *
	 * @return array<string, array{0: mixed, 1: int|string}> The columns.
	 */
	private static function sharePublicationColumns(PublicShareRequest $request, string $token): array {
		return [
			'permissions' => [self::PUBLIC_SHARE_PERMISSIONS, IQueryBuilder::PARAM_INT],
			'stime' => [time(), IQueryBuilder::PARAM_INT],
			'accepted' => [0, IQueryBuilder::PARAM_INT],
			// The share carries the end of the window, so Nextcloud stops
			// serving the link on the date with nothing having to run.
			// An OR-side flag alone would leave a public URL that still
			// works, which is not a depublication.
			'expiration' => [$request->depublished?->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR],
			'token' => [$token, IQueryBuilder::PARAM_STR],
			'mail_send' => [0, IQueryBuilder::PARAM_INT],
			'hide_download' => [0, IQueryBuilder::PARAM_INT],
		];
	}//end sharePublicationColumns()

	/**
	 * Insert one share row.
	 *
	 * @param array<string, array{0: mixed, 1: int|string}> $row The row, column by column.
	 *
	 * @return int The new share's id.
	 *
	 * @throws \Exception If the share creation fails
	 */
	private function insertShare(array $row): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('share');
		$values = [];
		foreach ($row as $column => $cell) {
			$values[$column] = $qb->createNamedParameter($cell[0], $cell[1]);
		}

		$qb->values(values: $values);
		if ($qb->executeStatement() !== 1) {
			throw new Exception('Failed to create public share in database');
		}

		// Get the ID of the newly created share.
		return $qb->getLastInsertId();
	}//end insertShare()

	/**
	 * What a publication answers: the share id and token, its links and when it was published.
	 *
	 * @param array{id: int, token: string, stime: int|string} $share The share row.
	 *
	 * @return array{id: int, token: string, accessUrl: string, downloadUrl: string, published: string}
	 */
	private function shareInfo(array $share): array {
		return [
			'id' => $share['id'],
			'token' => $share['token'],
			'accessUrl' => $this->generateShareUrl(token: $share['token']),
			'downloadUrl' => $this->generateShareUrl(token: $share['token']) . '/download',
			'published' => (new DateTime())->setTimestamp($share['stime'])->format('c'),
		];
	}//end shareInfo()

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
	public function depublishFile(int $fileId): array {
		// Delete all public shares for this file.
		$qb = $this->db->getQueryBuilder();
		$qb->delete('share')
			->where($qb->expr()->eq('file_source', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)));
		// 3 = public link.
		$deletedCount = $qb->executeStatement();

		return [
			'deleted_shares' => $deletedCount,
			'file_id' => $fileId,
		];
	}//end depublishFile()

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
	public function isFilePublished(int $fileId): bool {
		return $this->getPublicShare(fileId: $fileId) !== null;
	}//end isFilePublished()

	/**
	 * Get an existing public share for a file.
	 *
	 * @param int $fileId The file ID
	 *
	 * @return array|null The share information or null if not found
	 *
	 * @phpstan-param  int $fileId
	 * @phpstan-return array{id: int, token: string, stime: int}|null
	 */
	private function getPublicShare(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'token', 'stime')
			->from('share')
			->where($qb->expr()->eq('file_source', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$share = $result->fetch();
		$result->closeCursor();

		if ($share === false) {
			return null;
		}

		return $share;
	}//end getPublicShare()

	/**
	 * Generate a unique share token.
	 *
	 * @return string A unique share token
	 *
	 * @phpstan-return string
	 */
	private function generateShareToken(): string {
		// Generate a random token similar to how Nextcloud does it.
		// Using a combination of letters and numbers, 15 characters long.
		$characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$token = '';
		$max = strlen($characters) - 1;

		for ($i = 0; $i < 15; $i++) {
			$token .= $characters[random_int(0, $max)];
		}

		// Ensure the token is unique by checking if it already exists.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('share')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		$result = $qb->executeQuery();
		$exists = $result->fetch();
		$result->closeCursor();

		// If token exists, generate a new one recursively.
		if ($exists !== false) {
			return $this->generateShareToken();
		}

		return $token;
	}//end generateShareToken()

	/**
	 * Count all files in the Nextcloud installation
	 *
	 * @return int Total number of files in oc_filecache
	 *
	 * @phpstan-return int
	 */
	public function countAllFiles(): int {
		$qb = $this->db->getQueryBuilder();
		$dirType = $qb->createNamedParameter('httpd/unix-directory', IQueryBuilder::PARAM_STR);
		$qb->select($qb->func()->count('fc.fileid', 'count'))
			->from('filecache', 'fc')
			->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
			->where($qb->expr()->neq('mt.mimetype', $dirType));
		// Exclude directories.
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['count'] ?? 0);
	}//end countAllFiles()

	/**
	 * Get total storage size of all files in the Nextcloud installation
	 *
	 * @return int Total size in bytes of all files in oc_filecache
	 *
	 * @phpstan-return int
	 */
	public function getTotalFilesSize(): int {
		$qb = $this->db->getQueryBuilder();
		$dirType = $qb->createNamedParameter('httpd/unix-directory', IQueryBuilder::PARAM_STR);
		$qb->selectAlias($qb->createFunction('SUM(fc.size)'), 'total_size')
			->from('filecache', 'fc')
			->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
			->where($qb->expr()->neq('mt.mimetype', $dirType));
		// Exclude directories.
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['total_size'] ?? 0);
	}//end getTotalFilesSize()

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
	public function sumSizesByFileIds(array $fileIds): int {
		$total = 0;
		foreach (array_chunk(array_values(array_unique($fileIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->createFunction('SUM(fc.size)'), 'total_size')
				->from('filecache', 'fc')
				->where($qb->expr()->in('fc.fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->gte('fc.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
			$total += (int)($row['total_size'] ?? 0);
		}

		return $total;
	}//end sumSizesByFileIds()

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
	public function findUntrackedFiles(int $limit = 100, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();

		$params = $this->untrackedFileParameters(qb: $qb);
		$qb->select('fc.fileid', 'fc.path', 'fc.name', 'mt.mimetype', 'fc.size', 'fc.mtime', 'fc.checksum');
		$this->joinFilesWithoutChunks(qb: $qb);
		$this->whereUntrackedUserFile(qb: $qb, params: $params);
		$qb->setMaxResults($limit)
			->setFirstResult($offset)
			->orderBy('fc.fileid', 'ASC');

		return $this->fetchRows(qb: $qb);
	}//end findUntrackedFiles()

	/**
	 * Run a select and return all of its rows.
	 *
	 * @param IQueryBuilder $qb The select.
	 *
	 * @return array<int, array<string, mixed>> The rows, in order.
	 */
	private function fetchRows(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = [];
		$row = $result->fetch();
		while ($row !== false) {
			$rows[] = $row;
			$row = $result->fetch();
		}

		$result->closeCursor();
		return $rows;
	}//end fetchRows()

	/**
	 * Count untracked files
	 *
	 * Count files that exist in Nextcloud but haven't been tracked in file_texts table
	 *
	 * @return int Number of untracked files
	 */
	public function countUntrackedFiles(): int {
		$qb = $this->db->getQueryBuilder();

		$params = $this->untrackedFileParameters(qb: $qb);
		$qb->select($qb->createFunction('COUNT(DISTINCT fc.fileid) as count'));
		$this->joinFilesWithoutChunks(qb: $qb);
		$this->whereUntrackedUserFile(qb: $qb, params: $params);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countUntrackedFiles()

	/**
	 * The bound values of the untracked-file filter, created in a fixed order.
	 *
	 * @param IQueryBuilder $qb The builder the parameters belong to.
	 *
	 * @return array<string, mixed> The parameters by name.
	 */
	private function untrackedFileParameters(IQueryBuilder $qb): array {
		$params = [];
		foreach (self::UNTRACKED_FILE_PATTERNS as $name => $pattern) {
			$params[$name] = $qb->createNamedParameter($pattern, IQueryBuilder::PARAM_STR);
		}

		$params['zeroSize'] = $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT);
		return $params;
	}//end untrackedFileParameters()

	/**
	 * Read filecache rows with their mimetype and storage, keeping only files without a chunk.
	 *
	 * @param IQueryBuilder $qb The builder to add the source and joins to.
	 *
	 * @return void
	 */
	private function joinFilesWithoutChunks(IQueryBuilder $qb): void {
		$qb->from('filecache', 'fc')
			->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
			->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'));
		$fileChunk = $qb->expr()->andX(
			$qb->expr()->eq('fc.fileid', 'ch.source_id'),
			$qb->expr()->eq('ch.source_type', $qb->createNamedParameter('file', IQueryBuilder::PARAM_STR))
		);
		$qb->leftJoin('fc', 'openregister_chunks', 'ch', $fileChunk)
			->where($qb->expr()->isNull('ch.id'));
	}//end joinFilesWithoutChunks()

	/**
	 * Keep non-empty files under a user's `files/` folder, outside trash, appdata,
	 * versions, caches and thumbnails, and never directories.
	 *
	 * @param IQueryBuilder $qb The builder to filter.
	 * @param array<string, mixed> $params The parameters from untrackedFileParameters().
	 *
	 * @return void
	 */
	private function whereUntrackedUserFile(IQueryBuilder $qb, array $params): void {
		$qb->andWhere($qb->expr()->neq('mt.mimetype', $params['dirType']))
			->andWhere($qb->expr()->like('st.id', $params['homePattern']));
		foreach (['trashPat', 'appdataPat', 'versionPat', 'cachePat', 'thumbPat'] as $excluded) {
			$qb->andWhere($qb->expr()->notLike('fc.path', $params[$excluded]));
		}

		$qb->andWhere($qb->expr()->like('fc.path', $params['filesPat']))
			->andWhere($qb->expr()->gt('fc.size', $params['zeroSize']));
	}//end whereUntrackedUserFile()
}//end class
