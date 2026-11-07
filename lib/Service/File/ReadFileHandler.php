<?php

/**
 * ReadFileHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * Handles file retrieval operations with Single Responsibility.
 *
 * This handler is responsible ONLY for:
 * - Getting files by ID, name, or path
 * - Retrieving all files for an object
 * - Finding and searching for files
 * - Reading file metadata
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ReadFileHandler {

	/**
	 * Reference to FileService for cross-handler coordination (circular dependency break).
	 *
	 * @var FileService|null
	 */
	private ?FileService $fileService = null;

	/**
	 * Constructor for ReadFileHandler.
	 *
	 * @param IRootFolder              $rootFolder        Root folder for file operations.
	 * @param FolderManagementHandler  $folderMgmtHandler Folder management handler.
	 * @param FileValidationHandler    $fileValidHandler  File validation handler.
	 * @param MagicMapperInterface     $objectMapper      Object mapper for magic table operations.
	 * @param LoggerInterface          $logger            Logger for logging operations.
	 * @param FileMapperInterface|null $fileMapper        Optional OR-side metadata mapper for category-based
	 *                                                    filtering. Null-safe so legacy fixtures keep working.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly FolderManagementHandler $folderMgmtHandler,
		private readonly FileValidationHandler $fileValidHandler,
		private readonly MagicMapperInterface $objectMapper,
		private readonly LoggerInterface $logger,
		private readonly ?FileMapperInterface $fileMapper = null,
	) {
	}//end __construct()

	/**
	 * Set the FileService instance for cross-handler coordination.
	 *
	 * @param FileService $fileService The file service instance.
	 *
	 * @return void
	 */
	public function setFileService(FileService $fileService): void {
		$this->fileService = $fileService;
	}//end setFileService()

	/**
	 * Get a file by file identifier (ID or name/path) or by object and file name/path.
	 *
	 * If $file is an integer or a string that is an integer (e.g. '23234234'), the file will be fetched by ID
	 * and the $object parameter will be ignored. Otherwise, the file will be fetched by name/path within the object folder.
	 *
	 * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation
	 * on the File class.
	 * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation
	 * on the Node superclass.
	 *
	 * @param ObjectEntity|string|null $object The object or object ID to fetch files for
	 *                                         (ignored if $file is an ID).
	 * @param string|int $file The file name/path within the object folder,
	 *                         or the file ID (int or numeric string).
	 *
	 * @psalm-param   ObjectEntity|string|null $object
	 * @psalm-param   string|int $file
	 * @phpstan-param ObjectEntity|string|null $object
	 * @phpstan-param string|int $file
	 *
	 * @return File|null The file if found, null otherwise.
	 *
	 * @psalm-return   File|null
	 * @phpstan-return File|null
	 *
	 * @throws NotFoundException If the folder is not found.
	 * @throws DoesNotExistException If the object ID is not found.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getFile(ObjectEntity|string|null $object = null, string|int $file = ''): ?File {
		$folder = $this->folderMgmtHandler->getObjectFolder($this->resolveObject(object: $object));

		// If $file is an integer or a string that is an integer, treat as file ID.
		if (is_int($file) === true || ctype_digit($file) === true) {
			return $this->fileInFolderById(folder: $folder, fileId: (int)$file);
		}

		return $this->fileInFolderByPath(folder: $folder, file: (string)$file);
	}//end getFile()

	/**
	 * The object a file lookup runs in: a non-empty string id is looked up first.
	 *
	 * @param ObjectEntity|string|null $object The object or its id.
	 *
	 * @return ObjectEntity|string|null The object entity, or the value as given.
	 *
	 * @throws DoesNotExistException If the object ID is not found.
	 */
	private function resolveObject(ObjectEntity|string|null $object): ObjectEntity|string|null {
		if (is_string($object) === true && empty($object) === false) {
			return $this->objectMapper->find($object);
		}

		return $object;
	}//end resolveObject()

	/**
	 * A readable file by id inside an object folder; any failure, refusal included, is null.
	 *
	 * @param Folder|null $folder The object folder.
	 * @param int         $fileId The file id.
	 *
	 * @return File|null The file, or null.
	 */
	private function fileInFolderById(?Folder $folder, int $fileId): ?File {
		try {
			$node = ($folder->getById($fileId)[0] ?? null);
			if ($node instanceof File) {
				return $this->readable(node: $node);
			}
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ReadFileHandler] getFile: Error finding file by ID ' . $fileId . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return null;
	}//end fileInFolderById()

	/**
	 * A readable node by name, else by full path, inside an object folder.
	 *
	 * @param Folder|null $folder The object folder.
	 * @param string      $file   The file name or path.
	 *
	 * @return Node|null The node, or null without a folder or when neither path exists.
	 *
	 * @throws NotPermittedException If the session cannot read the node.
	 */
	private function fileInFolderByPath(?Folder $folder, string $file): ?Node {
		$pathInfo = $this->fileService->extractFileNameFromPath($file);
		if ($folder instanceof Folder === false) {
			return null;
		}

		// First try with just the filename, then with the full path.
		$node = ($this->nodeAt(folder: $folder, path: $pathInfo['fileName']) ?? $this->nodeAt(folder: $folder, path: $pathInfo['cleanPath']));
		if ($node === null) {
			return null;
		}

		return $this->readable(node: $node);
	}//end fileInFolderByPath()

	/**
	 * The node at a path inside a folder, or null when there is none.
	 *
	 * @param Folder $folder The folder.
	 * @param string $path   The path.
	 *
	 * @return Node|null The node.
	 */
	private function nodeAt(Folder $folder, string $path): ?Node {
		try {
			return $folder->get($path);
		} catch (NotFoundException) {
			return null;
		}
	}//end nodeAt()

	/**
	 * The node, once the session is known to be able to read it.
	 *
	 * @param Node $node The node.
	 *
	 * @return Node The same node.
	 *
	 * @template T of Node
	 * @phpstan-param  T $node
	 * @phpstan-return T
	 *
	 * @throws NotPermittedException If the session cannot read it.
	 */
	private function readable(Node $node): Node {
		// Check ownership for NextCloud rights issues.
		$this->fileValidHandler->checkOwnership($node);
		return $node;
	}//end readable()


	/**
	 * Get a file by its Nextcloud file ID without needing object context.
	 *
	 * This method retrieves a file directly using its Nextcloud file ID,
	 * which is useful for authenticated file access endpoints.
	 *
	 * @param int $fileId The Nextcloud file ID.
	 *
	 * @return File|null The file node or null if not found.
	 *
	 * @throws \Exception If there's an error accessing the file.
	 *
	 * @phpstan-param  int $fileId
	 * @phpstan-return File|null
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getFileById(int $fileId): ?File {
		try {
			// Use root folder to search for file by ID; file IDs are unique, so the first node is it.
			$node = ($this->rootFolder->getById($fileId)[0] ?? null);
			if (($node instanceof File) === false) {
				return null;
			}

			return $this->readable(node: $node);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ReadFileHandler] getFileById: Error finding file by ID ' . $fileId . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}//end try
	}//end getFileById()

	/**
	 * Get all files for an object.
	 *
	 * @param ObjectEntity|string $object The object or object ID to fetch files for.
	 * @param bool|null $sharedFilesOnly Whether to return only shared files.
	 * @param string|null $category Optional FileMapper category to filter on.
	 *
	 * @return array Array of file nodes.
	 *
	 * @throws DoesNotExistException If the object ID is not found.
	 *
	 * @psalm-return   list<\OCP\Files\Node>
	 * @phpstan-return array<int, \OCP\Files\Node>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag is intentional for simple filter toggle
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getFiles(
		ObjectEntity|string $object,
		?bool $sharedFilesOnly = false,
		?string $category = null,
	): array {
		$files = $this->fileService->getFilesForEntity(entity: $this->entityOf(object: $object), sharedFilesOnly: $sharedFilesOnly);

		if ($category === null || $this->fileMapper === null) {
			return $files;
		}

		return $this->filterByCategory(files: $files, category: $category);
	}//end getFiles()

	/**
	 * The entity a file listing runs for: a string id is found across all magic tables.
	 *
	 * @param ObjectEntity|string $object The object or its id.
	 *
	 * @return ObjectEntity The entity.
	 *
	 * @throws DoesNotExistException If the object ID is not found.
	 */
	private function entityOf(ObjectEntity|string $object): ObjectEntity {
		if (is_string($object) === false) {
			return $object;
		}

		return $this->objectMapper->findAcrossAllSources(identifier: $object, _multitenancy: false, _rbac: false)['object'];
	}//end entityOf()

	/**
	 * Filter a node list by OR-side category metadata.
	 *
	 * Uses one bulk lookup to fetch the OR-side rows for every
	 * candidate fileId, then keeps only nodes whose row carries the
	 * requested category. Files without an OR-side row are excluded
	 * because they have no category — matching SQL `WHERE category =
	 * :cat` semantics on a left-join.
	 *
	 * @param array<int, mixed> $files The candidate file nodes.
	 * @param string $category The category to match exactly.
	 *
	 * @return array<int, mixed> The filtered nodes (sequential keys).
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function filterByCategory(array $files, string $category): array {
		$fileIds = array_values(array_filter(array_map(fn (mixed $node): int => $this->nodeId(node: $node), $files)));
		if (empty($fileIds) === true) {
			return [];
		}

		$orMap = $this->fileMapper->findByFileIds(fileIds: $fileIds);
		$matched = [];
		foreach ($files as $node) {
			if ($this->hasCategory(row: ($orMap[$this->nodeId(node: $node)] ?? null), category: $category) === true) {
				$matched[] = $node;
			}
		}

		return $matched;
	}//end filterByCategory()

	/**
	 * The positive id of a node, or 0 when it has none that can be used.
	 *
	 * @param mixed $node The candidate node.
	 *
	 * @return int The id, or 0.
	 */
	private function nodeId(mixed $node): int {
		if (is_object($node) === false || method_exists($node, 'getId') === false) {
			return 0;
		}

		try {
			return max(0, (int)$node->getId());
		} catch (\Throwable) {
			// Node without a usable id — skip; it can't match.
			return 0;
		}
	}//end nodeId()

	/**
	 * Whether an OR-side row carries the category.
	 *
	 * @param mixed  $row      The row, or null when the file has none.
	 * @param string $category The category to match exactly.
	 *
	 * @return bool True on an exact match.
	 */
	private function hasCategory(mixed $row, string $category): bool {
		if ($row === null) {
			return false;
		}

		try {
			return $row->getCategory() === $category;
		} catch (\Throwable) {
			return false;
		}
	}//end hasCategory()

}//end class
