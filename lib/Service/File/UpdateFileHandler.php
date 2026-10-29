<?php

/**
 * UpdateFileHandler
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

/**
 * Handles file update operations with Single Responsibility.
 *
 * This handler is responsible ONLY for:
 * - Updating file content
 * - Updating file metadata
 * - Updating file tags
 * - Handling ownership transfer during updates
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The file lookup runs through several fallbacks (object
 *   folder by name and path, user folder by path and leading ID), one small method each.
 */
class UpdateFileHandler {

	/**
	 * Number of leading bytes read from a stream resource for the magic-byte
	 * executable check. Executable signatures live at offset 0, so a small
	 * bounded prefix gives full parity with the string path without buffering
	 * the whole file into memory.
	 *
	 * @var int
	 */
	private const EXECUTABLE_MAGIC_BYTE_PREFIX_LENGTH = 512;

	/**
	 * Reference to FileService for cross-handler coordination (circular dependency break).
	 *
	 * @var FileService|null
	 */
	private ?FileService $fileService = null;

	/**
	 * Constructor for UpdateFileHandler.
	 *
	 * @param IRootFolder $rootFolder Root folder for file operations.
	 * @param FolderManagementHandler $folderMgmtHandler Folder management handler.
	 * @param FileValidationHandler $fileValidHandler File validation handler.
	 * @param FileOwnershipHandler $fileOwnershipHandler File ownership handler.
	 * @param ReadFileHandler $readFileHandler Read file handler.
	 * @param ISystemTagManager $systemTagManager System tag manager.
	 * @param ISystemTagObjectMapper $systemTagMapper System tag object mapper.
	 * @param LoggerInterface $logger Logger for logging operations.
	 * @param \OCA\OpenRegister\Db\FileMapper|null $fileMapper Optional OR-side metadata mapper for
	 *                                                         description / category / labels writes.
	 *                                                         Null-safe so legacy fixtures keep working.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly FolderManagementHandler $folderMgmtHandler,
		private readonly FileValidationHandler $fileValidHandler,
		private readonly FileOwnershipHandler $fileOwnershipHandler,
		private readonly ReadFileHandler $readFileHandler,
		private readonly ISystemTagManager $systemTagManager,
		private readonly ISystemTagObjectMapper $systemTagMapper,
		private readonly LoggerInterface $logger,
		private readonly ?\OCA\OpenRegister\Db\FileMapper $fileMapper = null,
	) {
	}//end __construct()

	/**
	 * Update OR-side metadata (description / category / labels) for
	 * a Nextcloud file. Each parameter is optional — pass only the
	 * fields you want to update. Pass `null` to leave the existing
	 * value unchanged; pass an explicit value (including empty
	 * string for clearing string fields, or `[]` for clearing
	 * labels) to overwrite.
	 *
	 * The actual write goes through `FileMapper::setDescriptionForFile`
	 * / `setCategoryForFile` / `setLabelsForFile`, each of which is
	 * lazy-create-on-miss so no pre-existing OR-side row is required.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 * @param string|null $description The new description, empty string to clear, null to skip.
	 * @param string|null $category The new category, empty string to clear, null to skip.
	 * @param array<string>|null $labels The new labels, empty array to clear, null to skip.
	 *
	 * @return \OCA\OpenRegister\Db\File The persisted entity reflecting the updated state.
	 *
	 * @throws Exception When the FileMapper is not wired (legacy fixtures).
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function updateFileMetadata(
		int $fileId,
		?string $description = null,
		?string $category = null,
		?array $labels = null,
	): \OCA\OpenRegister\Db\File {
		if ($this->fileMapper === null) {
			throw new Exception('FileMapper is not wired; cannot update OR-side metadata');
		}

		$entity = $this->fileMapper->findOrCreateByFileId(fileId: $fileId);

		if ($description !== null) {
			$entity = $this->fileMapper->setDescriptionForFile(fileId: $fileId, description: $description);
		}

		if ($category !== null) {
			$entity = $this->fileMapper->setCategoryForFile(fileId: $fileId, category: $category);
		}

		if ($labels !== null) {
			$entity = $this->fileMapper->setLabelsForFile(fileId: $fileId, labels: $labels);
		}

		$this->logger->debug(
			message: "[UpdateFileHandler] OR-side metadata updated for file $fileId",
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'fileId' => $fileId,
				'descriptionTouched' => ($description !== null),
				'categoryTouched' => ($category !== null),
				'labelsTouched' => ($labels !== null),
			]
		);

		return $entity;
	}//end updateFileMetadata()

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
	 * Update a file's content, metadata, and tags.
	 *
	 * This method updates the content and/or tags of an existing file. When updating tags,
	 * it preserves any existing 'object:' tags while replacing other user-defined tags.
	 *
	 * @param string|int $filePath The path or file ID.
	 * @param string|resource|null $content Optional content of the file: a byte string, a readable
	 *                                      stream resource (streamed straight to storage), or null
	 *                                      to update only metadata/tags.
	 * @param array $tags Optional array of tags.
	 * @param ObjectEntity|null $object Optional object entity.
	 *
	 * @return File The updated file.
	 *
	 * @throws Exception If the file doesn't exist or if file operations fail.
	 *
	 * @phpstan-param array<int, string> $tags
	 * @psalm-param   array<int, string> $tags
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function updateFile(
		string|int $filePath,
		mixed $content = null,
		array $tags = [],
		?ObjectEntity $object = null,
	): File {
		$this->logger->debug(
			message: "[UpdateFileHandler] updateFile: Original file path received: '$filePath'",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$file = $this->findFile(filePath: $filePath, object: $object);
		if ($content !== null && $file instanceof File) {
			$this->writeContentIfChanged(file: $file, content: $content);
		}

		if (empty($tags) === false) {
			$this->replaceUserTags(file: $file, tags: $tags);
		}

		return $file;
	}//end updateFile()

	/**
	 * Find the file to update by ID or by path.
	 *
	 * @param string|int        $filePath The path or file ID
	 * @param ObjectEntity|null $object   The object whose folder is searched first
	 *
	 * @return Node The file
	 *
	 * @throws Exception When the file does not exist or cannot be accessed
	 */
	private function findFile(string|int $filePath, ?ObjectEntity $object): Node {
		if (is_int($filePath) === true) {
			return $this->findFileById(fileId: $filePath, object: $object);
		}

		return $this->findFileByPath(filePath: $filePath, object: $object);
	}//end findFile()

	/**
	 * Find a file by its ID, first in the object's folder, then in the user folder.
	 *
	 * @param int               $fileId The file ID
	 * @param ObjectEntity|null $object The object whose folder is searched first
	 *
	 * @return Node The file
	 *
	 * @throws Exception When no file has this ID
	 */
	private function findFileById(int $fileId, ?ObjectEntity $object): Node {
		$this->logger->debug(
			message: "[UpdateFileHandler] updateFile: File ID provided: $fileId",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		if ($object !== null) {
			$file = $this->readFileHandler->getFile(object: $object, file: $fileId);
			if ($file !== null) {
				$fileName = $file->getName();
				$foundId = $file->getId();
				$this->logger->debug(
					message: "[UpdateFileHandler] updateFile: Found file by ID in object folder: $fileName (ID: $foundId)",
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				return $file;
			}
		}

		try {
			$nodes = $this->folderMgmtHandler->getOpenRegisterUserFolder()->getById($fileId);
			if (empty($nodes) === true) {
				$this->logger->error(
					message: "[UpdateFileHandler] updateFile: No file found with ID: $fileId",
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				throw new Exception("File with ID $fileId does not exist");
			}

			$file = $nodes[0];
			$fileName = $file->getName();
			$foundId = $file->getId();
			$this->logger->debug(
				message: "[UpdateFileHandler] updateFile: Found file by ID in user folder: $fileName (ID: $foundId)",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $file;
		} catch (Exception $e) {
			$this->logger->error(
				message: "[UpdateFileHandler] updateFile: Error finding file by ID $fileId: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception("File with ID $fileId does not exist: " . $e->getMessage());
		}//end try
	}//end findFileById()

	/**
	 * Find a file by its path, first in the object's folder, then in the user folder.
	 *
	 * @param string            $filePath The file path, optionally prefixed with a folder ID
	 * @param ObjectEntity|null $object   The object whose folder is searched first
	 *
	 * @return Node The file
	 *
	 * @throws Exception When the file does not exist or cannot be accessed
	 */
	private function findFileByPath(string $filePath, ?ObjectEntity $object): Node {
		$pathInfo = $this->fileService->extractFileNameFromPath($filePath);
		$cleanPath = $pathInfo['cleanPath'];
		$fileName = $pathInfo['fileName'];

		$this->logger->debug(
			message: "[UpdateFileHandler] updateFile: After cleaning: '$cleanPath'",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		if ($fileName !== $cleanPath) {
			$this->logger->debug(
				message: "[UpdateFileHandler] updateFile: Extracted filename from path: '$fileName' (from '$cleanPath')",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		$file = null;
		if ($object !== null) {
			$file = $this->findInObjectFolder(object: $object, pathInfo: $pathInfo);
		}

		if ($object === null) {
			$this->logger->debug(
				message: '[UpdateFileHandler] updateFile: No object provided, will search in user folder',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		if ($file === null) {
			$file = $this->findInUserFolder(filePath: $cleanPath);
		}

		return $file;
	}//end findFileByPath()

	/**
	 * Look a file up in the object's folder, by file name and then by full path.
	 *
	 * Failures are logged, never thrown: the caller falls back to the user folder.
	 *
	 * @param ObjectEntity $object   The object owning the folder
	 * @param array        $pathInfo The cleanPath and fileName of the file
	 *
	 * @return Node|null The file, or null when the object folder does not have it
	 */
	private function findInObjectFolder(ObjectEntity $object, array $pathInfo): ?Node {
		try {
			$objectFolder = $this->folderMgmtHandler->getObjectFolder($object);
			if ($objectFolder === null) {
				$this->logger->warning(
					message: '[UpdateFileHandler] updateFile: Could not get object folder for object ID: ' . $object->getId(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				return null;
			}

			$this->logObjectFolder(objectFolder: $objectFolder);
			return $this->getFromObjectFolder(objectFolder: $objectFolder, pathInfo: $pathInfo);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[UpdateFileHandler] updateFile: Error accessing object folder: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}//end try
	}//end findInObjectFolder()

	/**
	 * Log the object folder's path, ID and file listing at debug level.
	 *
	 * @param Folder $objectFolder The object folder
	 *
	 * @return void
	 */
	private function logObjectFolder(Folder $objectFolder): void {
		$this->logger->debug(
			message: '[UpdateFileHandler] updateFile: Object folder path: ' . $objectFolder->getPath(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$this->logger->debug(
			message: '[UpdateFileHandler] updateFile: Object folder ID: ' . $objectFolder->getId(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		try {
			$fileNames = array_map(fn ($f) => $f->getName(), $objectFolder->getDirectoryListing());
			$fileList = implode(', ', $fileNames);
			$this->logger->debug(
				message: "[UpdateFileHandler] updateFile: Files in object folder: $fileList",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[UpdateFileHandler] updateFile: Could not list folder contents: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end logObjectFolder()

	/**
	 * Get a file from the object folder by file name, falling back to the full path.
	 *
	 * @param Folder $objectFolder The object folder
	 * @param array  $pathInfo     The cleanPath and fileName of the file
	 *
	 * @return Node|null The file, or null when neither name matches
	 */
	private function getFromObjectFolder(Folder $objectFolder, array $pathInfo): ?Node {
		$fileName = $pathInfo['fileName'];
		$filePath = $pathInfo['cleanPath'];
		try {
			$file = $objectFolder->get($fileName);
			$this->logger->debug(
				message: '[UpdateFileHandler] updateFile: Found file in object folder: ' . $file->getName() . ' (ID: ' . $file->getId() . ')',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $file;
		} catch (NotFoundException) {
			$this->logger->warning(
				message: "[UpdateFileHandler] updateFile: File '$fileName' not found in object folder.",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		try {
			$file = $objectFolder->get($filePath);
			$this->logger->debug(
				message: '[UpdateFileHandler] updateFile: Found file using full path in object folder: ' . $file->getName(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $file;
		} catch (NotFoundException) {
			$this->logger->warning(
				message: "[UpdateFileHandler] updateFile: File '$filePath' also not found with full path in object folder.",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end getFromObjectFolder()

	/**
	 * Find a file in the OpenRegister user folder by path.
	 *
	 * A path that starts with a numeric folder ID (`12/file.txt`) falls back to a
	 * lookup of that ID.
	 *
	 * @param string $filePath The cleaned file path
	 *
	 * @return Node The file
	 *
	 * @throws Exception When the file does not exist or cannot be accessed
	 */
	private function findInUserFolder(string $filePath): Node {
		$this->logger->debug(
			message: "[UpdateFileHandler] updateFile: Trying user folder approach with path: '$filePath'",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$userFolder = null;
		try {
			$userFolder = $this->folderMgmtHandler->getOpenRegisterUserFolder();
			$file = $userFolder->get(path: $filePath);
			$fileId = $file->getId();
			$this->logger->debug(
				message: "[UpdateFileHandler] updateFile: Found file in user folder at path: $filePath (ID: $fileId)",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $file;
		} catch (NotFoundException $e) {
			$this->logger->error(
				message: "[UpdateFileHandler] updateFile: File $filePath not found in user folder either.",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			$file = $this->findByLeadingFolderId(userFolder: $userFolder, filePath: $filePath);
			if ($file === null) {
				throw new Exception("File $filePath does not exist");
			}

			return $file;
		} catch (NotPermittedException|InvalidPathException $e) {
			$this->logger->error(
				message: "[UpdateFileHandler] updateFile: Can't access file $filePath: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception("Can't access file $filePath: " . $e->getMessage());
		}//end try
	}//end findInUserFolder()

	/**
	 * Look a file up by the numeric ID a path starts with (`12/file.txt`).
	 *
	 * @param Folder|null $userFolder The user folder
	 * @param string      $filePath   The cleaned file path
	 *
	 * @return Node|null The file, or null when the path has no ID or nothing matches
	 */
	private function findByLeadingFolderId(?Folder $userFolder, string $filePath): ?Node {
		if (preg_match('/^(\d+)\//', $filePath, $matches) !== 1) {
			return null;
		}

		$fileId = (int)$matches[1];
		$this->logger->debug(
			message: "[UpdateFileHandler] updateFile: Attempting to find file by ID: $fileId",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		try {
			$nodes = $userFolder->getById($fileId);
			if (empty($nodes) === true) {
				$this->logger->warning(
					message: "[UpdateFileHandler] updateFile: No file found with ID: $fileId",
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				return null;
			}

			$file = $nodes[0];
			$fileName = $file->getName();
			$path = $file->getPath();
			$this->logger->debug(
				message: "[UpdateFileHandler] updateFile: Found file by ID $fileId: $fileName at path: $path",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $file;
		} catch (Exception $e) {
			$this->logger->error(
				message: "[UpdateFileHandler] updateFile: Error finding file by ID {$fileId}: {$e->getMessage()}",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}//end try
	}//end findByLeadingFolderId()

	/**
	 * Write new content to a file unless it is byte-identical to the stored content.
	 *
	 * The incoming hash is computed memory-bounded: a stream resource is hashed
	 * chunk by chunk and rewound, so a re-synced identical file skips the write
	 * and its version bump on both paths.
	 *
	 * @param File  $file    The file to write
	 * @param mixed $content A byte string or a readable stream resource
	 *
	 * @return void
	 *
	 * @throws Exception When the content cannot be written
	 */
	private function writeContentIfChanged(File $file, mixed $content): void {
		if ($file->hash(type: 'md5') === $this->contentMd5(content: $content)) {
			return;
		}

		try {
			$this->writeContent(file: $file, content: $content);
		} catch (NotPermittedException $e) {
			$this->logger->error(
				message: "[UpdateFileHandler] updateFile: Can't write content to file: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception("Can't write content to file: " . $e->getMessage());
		}
	}//end writeContentIfChanged()

	/**
	 * The md5 of incoming content; a stream is hashed chunk by chunk and rewound.
	 *
	 * @param mixed $content A byte string or a readable stream resource
	 *
	 * @return string The md5 hex digest
	 */
	private function contentMd5(mixed $content): string {
		if (is_resource($content) === false) {
			return md5(string: $content);
		}

		$hashContext = hash_init('md5');
		hash_update_stream($hashContext, $content);
		$md5 = hash_final($hashContext);
		rewind($content);

		return $md5;
	}//end contentMd5()

	/**
	 * Validate and write content to a file, then hand ownership to OpenRegister.
	 *
	 * Base64 string content is decoded first. Executable content is blocked by its
	 * magic bytes; for a stream only a bounded prefix is read and the stream is
	 * rewound before writing.
	 *
	 * @param File  $file    The file to write
	 * @param mixed $content A byte string or a readable stream resource
	 *
	 * @return void
	 *
	 * @throws NotPermittedException When the session may not write the file
	 */
	private function writeContent(File $file, mixed $content): void {
		if (is_resource($content) === false && base64_encode(base64_decode($content, true)) === $content) {
			$content = base64_decode($content);
		}

		$execCheckBytes = $content;
		if (is_resource($content) === true) {
			$execCheckBytes = (string)fread($content, self::EXECUTABLE_MAGIC_BYTE_PREFIX_LENGTH);
			rewind($content);
		}

		$this->fileValidHandler->blockExecutableFile(fileName: $file->getName(), fileContent: $execCheckBytes);

		// Reaching the file (owned or shared) is not enough: writing also needs write
		// permission. NC enforces it on putContent(); this fails fast with a clear message.
		$this->fileValidHandler->checkOwnership($file);
		if ($file->isUpdateable() === false) {
			throw new NotPermittedException("File {$file->getName()} is not writable by the current session");
		}

		$file->putContent(data: $content);
		$this->logger->debug(
			message: '[UpdateFileHandler] updateFile: Successfully updated file content: ' . $file->getName(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$this->fileOwnershipHandler->transferFileOwnershipIfNeeded($file);
	}//end writeContent()

	/**
	 * Replace a file's tags, keeping the `object:` tags it already has.
	 *
	 * @param Node  $file The file
	 * @param array $tags The new tags
	 *
	 * @return void
	 */
	private function replaceUserTags(Node $file, array $tags): void {
		$existingTags = $this->fileService->getFileTags(fileId: (string)$file->getId());
		$objectTags = array_filter(
			$existingTags,
			static function (string $tag): bool {
				return str_starts_with($tag, 'object:');
			}
		);

		$allTags = array_unique(array_merge($objectTags, $tags));

		$this->fileService->attachTagsToFile(fileId: (string)$file->getId(), tags: $allTags);
		$this->logger->debug(
			message: '[UpdateFileHandler] updateFile: Successfully updated file tags: ' . $file->getName(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end replaceUserTags()
}//end class
