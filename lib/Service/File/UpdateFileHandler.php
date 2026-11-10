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
use OCA\OpenRegister\Service\FileHandlerSupportInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
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
	 * @var FileHandlerSupportInterface|null
	 */
	private ?FileHandlerSupportInterface $fileService = null;

	/**
	 * Constructor for UpdateFileHandler.
	 *
	 * @param UpdateFileLocator $locator Finds the file by id, in the object folder or in the user folder.
	 * @param UpdateFileGuards $guards Screens executable content, checks and transfers ownership.
	 * @param LoggerInterface $logger Logger for logging operations.
	 * @param \OCA\OpenRegister\Files\Db\FileMapperInterface|null $fileMapper Optional OR-side metadata mapper for
	 *                                                         description / category / labels writes.
	 *                                                         Null-safe so legacy fixtures keep working.
	 */
	public function __construct(
		private readonly UpdateFileLocator $locator,
		private readonly UpdateFileGuards $guards,
		private readonly LoggerInterface $logger,
		private readonly ?\OCA\OpenRegister\Files\Db\FileMapperInterface $fileMapper = null,
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
	 * @param FileMetadataChange $change The file id and the description, category and labels to write.
	 *
	 * @return \OCA\OpenRegister\Files\Db\File The persisted entity reflecting the updated state.
	 *
	 * @throws Exception When the FileMapper is not wired (legacy fixtures).
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function updateFileMetadata(FileMetadataChange $change): \OCA\OpenRegister\Files\Db\File {
		$entity = $this->writeMetadata(fileId: $change->fileId, values: $change->values());
		$this->logMetadataUpdate(fileId: $change->fileId, values: $change->values());
		return $entity;
	}//end updateFileMetadata()

	/**
	 * Write the given OR-side metadata fields, in the order description, category, labels.
	 *
	 * @param int                  $fileId The Nextcloud filecache fileid.
	 * @param array<string, mixed> $values The description, category and labels; null skips a field.
	 *
	 * @return \OCA\OpenRegister\Files\Db\File The entity after the last write.
	 *
	 * @throws Exception When the FileMapper is not wired (legacy fixtures).
	 */
	private function writeMetadata(int $fileId, array $values): \OCA\OpenRegister\Files\Db\File {
		$mapper = $this->metadataMapper();
		$entity = $mapper->findOrCreateByFileId(fileId: $fileId);
		if ($values['description'] !== null) {
			$entity = $mapper->setDescriptionForFile(fileId: $fileId, description: $values['description']);
		}

		if ($values['category'] !== null) {
			$entity = $mapper->setCategoryForFile(fileId: $fileId, category: $values['category']);
		}

		if ($values['labels'] !== null) {
			$entity = $mapper->setLabelsForFile(fileId: $fileId, labels: $values['labels']);
		}

		return $entity;
	}//end writeMetadata()

	/**
	 * The OR-side metadata mapper.
	 *
	 * @return \OCA\OpenRegister\Files\Db\FileMapperInterface The mapper.
	 *
	 * @throws Exception When the FileMapper is not wired (legacy fixtures).
	 */
	private function metadataMapper(): \OCA\OpenRegister\Files\Db\FileMapperInterface {
		if ($this->fileMapper === null) {
			throw new Exception('FileMapper is not wired; cannot update OR-side metadata');
		}

		return $this->fileMapper;
	}//end metadataMapper()

	/**
	 * Log which OR-side metadata fields a write touched.
	 *
	 * @param int                  $fileId The Nextcloud filecache fileid.
	 * @param array<string, mixed> $values The description, category and labels; null was skipped.
	 *
	 * @return void
	 */
	private function logMetadataUpdate(int $fileId, array $values): void {
		$this->logger->debug(
			message: "[UpdateFileHandler] OR-side metadata updated for file $fileId",
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'fileId' => $fileId,
				'descriptionTouched' => ($values['description'] !== null),
				'categoryTouched' => ($values['category'] !== null),
				'labelsTouched' => ($values['labels'] !== null),
			]
		);
	}//end logMetadataUpdate()

	/**
	 * Set the FileService instance for cross-handler coordination.
	 *
	 * @param FileHandlerSupportInterface $fileService The file service instance.
	 *
	 * @return void
	 */
	public function setFileService(FileHandlerSupportInterface $fileService): void {
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
		$this->note(level: 'debug', message: "Original file path received: '$filePath'");
		$file = $this->findFile(filePath: $filePath, object: $object);
		return $this->applyChange(file: $file, change: new FileContentChange(content: $content, tags: $tags));
	}//end updateFile()

	/**
	 * Write the new content when it differs, then replace the user tags.
	 *
	 * @param Node              $file   The file found for the update.
	 * @param FileContentChange $change The new content and tags.
	 *
	 * @return Node The updated file.
	 *
	 * @throws Exception If file operations fail.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function applyChange(Node $file, FileContentChange $change): Node {
		if ($change->content !== null && $file instanceof File) {
			$this->writeContentIfChanged(file: $file, content: $change->content);
		}

		$this->replaceUserTags(file: $file, tags: $change->tags);
		return $file;
	}//end applyChange()

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
		$this->note(level: 'debug', message: "File ID provided: $fileId");
		return ($this->findInObjectById(fileId: $fileId, object: $object) ?? $this->findInUserFolderById(fileId: $fileId));
	}//end findFileById()

	/**
	 * Find a file by its ID through the read handler, in the object's folder.
	 *
	 * @param int               $fileId The file ID
	 * @param ObjectEntity|null $object The object, or null to skip this lookup
	 *
	 * @return Node|null The file, or null when there is no object or it has no such file
	 */
	private function findInObjectById(int $fileId, ?ObjectEntity $object): ?Node {
		if ($object === null) {
			return null;
		}

		$file = $this->locator->readFileHandler->getFile(object: $object, file: $fileId);
		if ($file !== null) {
			$this->noteFound(where: 'Found file by ID in object folder', file: $file);
		}

		return $file;
	}//end findInObjectById()

	/**
	 * Find a file by its ID in the OpenRegister user folder.
	 *
	 * @param int $fileId The file ID
	 *
	 * @return Node The file
	 *
	 * @throws Exception When no file has this ID or the lookup fails
	 */
	private function findInUserFolderById(int $fileId): Node {
		try {
			$nodes = $this->locator->folderMgmtHandler->getOpenRegisterUserFolder()->getById($fileId);
			if (empty($nodes) === true) {
				$this->note(level: 'error', message: "No file found with ID: $fileId");
				throw new Exception("File with ID $fileId does not exist");
			}

			$this->noteFound(where: 'Found file by ID in user folder', file: $nodes[0]);
			return $nodes[0];
		} catch (Exception $e) {
			$this->note(level: 'error', message: "Error finding file by ID $fileId: " . $e->getMessage());
			throw new Exception("File with ID $fileId does not exist: " . $e->getMessage());
		}
	}//end findInUserFolderById()

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
		$this->noteCleaning(pathInfo: $pathInfo);

		$file = $this->findByPathInObject(object: $object, pathInfo: $pathInfo);
		return ($file ?? $this->findInUserFolder(filePath: $pathInfo['cleanPath']));
	}//end findFileByPath()

	/**
	 * Log the cleaned path and, when it differs, the file name taken from it.
	 *
	 * @param array $pathInfo The cleanPath and fileName of the file
	 *
	 * @return void
	 */
	private function noteCleaning(array $pathInfo): void {
		$cleanPath = $pathInfo['cleanPath'];
		$fileName = $pathInfo['fileName'];
		$this->note(level: 'debug', message: "After cleaning: '$cleanPath'");
		if ($fileName !== $cleanPath) {
			$this->note(level: 'debug', message: "Extracted filename from path: '$fileName' (from '$cleanPath')");
		}
	}//end noteCleaning()

	/**
	 * Look a path up in the object's folder, when there is an object.
	 *
	 * @param ObjectEntity|null $object   The object, or null to skip this lookup
	 * @param array             $pathInfo The cleanPath and fileName of the file
	 *
	 * @return Node|null The file, or null when there is no object or its folder does not have it
	 */
	private function findByPathInObject(?ObjectEntity $object, array $pathInfo): ?Node {
		if ($object === null) {
			$this->note(level: 'debug', message: 'No object provided, will search in user folder');
			return null;
		}

		return $this->findInObjectFolder(object: $object, pathInfo: $pathInfo);
	}//end findByPathInObject()

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
			$objectFolder = $this->locator->folderMgmtHandler->getObjectFolder($object);
			if ($objectFolder === null) {
				$this->note(level: 'warning', message: 'Could not get object folder for object ID: ' . $object->getId());
				return null;
			}

			$this->logObjectFolder(objectFolder: $objectFolder);
			return $this->getFromObjectFolder(objectFolder: $objectFolder, pathInfo: $pathInfo);
		} catch (Exception $e) {
			$this->note(level: 'error', message: 'Error accessing object folder: ' . $e->getMessage());
			return null;
		}
	}//end findInObjectFolder()

	/**
	 * Log the object folder's path, ID and file listing at debug level.
	 *
	 * @param Folder $objectFolder The object folder
	 *
	 * @return void
	 */
	private function logObjectFolder(Folder $objectFolder): void {
		$this->note(level: 'debug', message: 'Object folder path: ' . $objectFolder->getPath());
		$this->note(level: 'debug', message: 'Object folder ID: ' . $objectFolder->getId());

		try {
			$fileList = implode(', ', array_map(fn ($f) => $f->getName(), $objectFolder->getDirectoryListing()));
			$this->note(level: 'debug', message: "Files in object folder: $fileList");
		} catch (Exception $e) {
			$this->note(level: 'warning', message: 'Could not list folder contents: ' . $e->getMessage());
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
		return ($this->getByName(objectFolder: $objectFolder, fileName: $pathInfo['fileName'])
			?? $this->getByFullPath(objectFolder: $objectFolder, filePath: $pathInfo['cleanPath']));
	}//end getFromObjectFolder()

	/**
	 * Get a file from the object folder by its file name.
	 *
	 * @param Folder $objectFolder The object folder
	 * @param string $fileName     The file name
	 *
	 * @return Node|null The file, or null when the name does not match
	 */
	private function getByName(Folder $objectFolder, string $fileName): ?Node {
		$file = $this->nodeAt(folder: $objectFolder, path: $fileName);
		if ($file === null) {
			$this->note(level: 'warning', message: "File '$fileName' not found in object folder.");
			return null;
		}

		$this->noteFound(where: 'Found file in object folder', file: $file);
		return $file;
	}//end getByName()

	/**
	 * Get a file from the object folder by its full path.
	 *
	 * @param Folder $objectFolder The object folder
	 * @param string $filePath     The cleaned full path
	 *
	 * @return Node|null The file, or null when the path does not match
	 */
	private function getByFullPath(Folder $objectFolder, string $filePath): ?Node {
		$file = $this->nodeAt(folder: $objectFolder, path: $filePath);
		if ($file === null) {
			$this->note(level: 'warning', message: "File '$filePath' also not found with full path in object folder.");
			return null;
		}

		$this->note(level: 'debug', message: 'Found file using full path in object folder: ' . $file->getName());
		return $file;
	}//end getByFullPath()

	/**
	 * The node at a path inside a folder, or null when there is none.
	 *
	 * @param Folder $folder The folder
	 * @param string $path   The path
	 *
	 * @return Node|null The node
	 */
	private function nodeAt(Folder $folder, string $path): ?Node {
		try {
			return $folder->get($path);
		} catch (NotFoundException) {
			return null;
		}
	}//end nodeAt()

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
		$this->note(level: 'debug', message: "Trying user folder approach with path: '$filePath'");
		try {
			return $this->getFromUserFolder(filePath: $filePath);
		} catch (NotPermittedException|InvalidPathException $e) {
			$this->note(level: 'error', message: "Can't access file $filePath: " . $e->getMessage());
			throw new Exception("Can't access file $filePath: " . $e->getMessage());
		}
	}//end findInUserFolder()

	/**
	 * Get a file from the OpenRegister user folder by path, else by the ID it starts with.
	 *
	 * @param string $filePath The cleaned file path
	 *
	 * @return Node The file
	 *
	 * @throws NotPermittedException|InvalidPathException When the path cannot be accessed
	 * @throws Exception When the file does not exist
	 */
	private function getFromUserFolder(string $filePath): Node {
		$userFolder = null;
		try {
			$userFolder = $this->locator->folderMgmtHandler->getOpenRegisterUserFolder();
			$file = $userFolder->get(path: $filePath);
			$this->note(level: 'debug', message: "Found file in user folder at path: $filePath (ID: {$file->getId()})");
			return $file;
		} catch (NotFoundException) {
			$this->note(level: 'error', message: "File $filePath not found in user folder either.");
			return $this->requireByLeadingFolderId(userFolder: $userFolder, filePath: $filePath);
		}
	}//end getFromUserFolder()

	/**
	 * The file the leading numeric ID of a path names; its absence is an error.
	 *
	 * @param Folder|null $userFolder The user folder
	 * @param string      $filePath   The cleaned file path
	 *
	 * @return Node The file
	 *
	 * @throws Exception When the path has no ID or nothing matches
	 */
	private function requireByLeadingFolderId(?Folder $userFolder, string $filePath): Node {
		$file = $this->findByLeadingFolderId(userFolder: $userFolder, filePath: $filePath);
		if ($file === null) {
			throw new Exception("File $filePath does not exist");
		}

		return $file;
	}//end requireByLeadingFolderId()

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
		$this->note(level: 'debug', message: "Attempting to find file by ID: $fileId");
		try {
			return $this->firstById(userFolder: $userFolder, fileId: $fileId);
		} catch (Exception $e) {
			$this->note(level: 'error', message: "Error finding file by ID {$fileId}: {$e->getMessage()}");
			return null;
		}
	}//end findByLeadingFolderId()

	/**
	 * The first node with an ID in the user folder.
	 *
	 * @param Folder|null $userFolder The user folder
	 * @param int         $fileId     The ID
	 *
	 * @return Node|null The node, or null when none has the ID
	 */
	private function firstById(?Folder $userFolder, int $fileId): ?Node {
		$nodes = $userFolder->getById($fileId);
		if (empty($nodes) === true) {
			$this->note(level: 'warning', message: "No file found with ID: $fileId");
			return null;
		}

		$file = $nodes[0];
		$this->note(level: 'debug', message: "Found file by ID $fileId: {$file->getName()} at path: {$file->getPath()}");
		return $file;
	}//end firstById()

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
			$this->note(level: 'error', message: "Can't write content to file: " . $e->getMessage());
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
			// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- Md5 is the checksum Nextcloud stores for the file, compared to skip an unchanged write.
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
	 * magic bytes before the session's rights are checked and the content written.
	 *
	 * @param File  $file    The file to write
	 * @param mixed $content A byte string or a readable stream resource
	 *
	 * @return void
	 *
	 * @throws NotPermittedException When the session may not write the file
	 */
	private function writeContent(File $file, mixed $content): void {
		$content = $this->decodedBase64(content: $content);
		$this->guards->fileValidHandler->blockExecutableFile(fileName: $file->getName(), fileContent: $this->executableCheckBytes(content: $content));
		$this->assertWritable(file: $file);

		$file->putContent(data: $content);
		$this->note(level: 'debug', message: 'Successfully updated file content: ' . $file->getName());

		$this->guards->fileOwnershipHandler->transferFileOwnershipIfNeeded($file);
	}//end writeContent()

	/**
	 * String content decoded when it is base64; a stream resource as given.
	 *
	 * @param mixed $content A byte string or a readable stream resource
	 *
	 * @return mixed The content to write
	 */
	private function decodedBase64(mixed $content): mixed {
		if (is_resource($content) === false && base64_encode(base64_decode($content, true)) === $content) {
			return base64_decode($content);
		}

		return $content;
	}//end decodedBase64()

	/**
	 * The bytes the executable check reads: a string as given, a stream's bounded prefix.
	 *
	 * Executable signatures live at offset 0, so for a stream only a bounded prefix
	 * is read, and the stream is rewound before writing.
	 *
	 * @param mixed $content A byte string or a readable stream resource
	 *
	 * @return mixed The bytes to check
	 */
	private function executableCheckBytes(mixed $content): mixed {
		$execCheckBytes = $content;
		if (is_resource($content) === true) {
			$execCheckBytes = (string)fread($content, self::EXECUTABLE_MAGIC_BYTE_PREFIX_LENGTH);
			rewind($content);
		}

		return $execCheckBytes;
	}//end executableCheckBytes()

	/**
	 * Refuse a write the session may not make.
	 *
	 * Reaching the file (owned or shared) is not enough: writing also needs write
	 * permission. NC enforces it on putContent(); this fails fast with a clear message.
	 *
	 * @param File $file The file to write
	 *
	 * @return void
	 *
	 * @throws NotPermittedException When the session may not reach or write the file
	 */
	private function assertWritable(File $file): void {
		$this->guards->fileValidHandler->checkOwnership($file);
		if ($file->isUpdateable() === false) {
			throw new NotPermittedException("File {$file->getName()} is not writable by the current session");
		}
	}//end assertWritable()

	/**
	 * Replace a file's tags, keeping the `object:` tags it already has; no tags leaves them as they are.
	 *
	 * @param Node  $file The file
	 * @param array $tags The new tags
	 *
	 * @return void
	 */
	private function replaceUserTags(Node $file, array $tags): void {
		if (empty($tags) === true) {
			return;
		}

		$existingTags = $this->fileService->getFileTags(fileId: (string)$file->getId());
		$objectTags = array_filter($existingTags, static fn (string $tag): bool => str_starts_with($tag, 'object:'));
		$allTags = array_unique(array_merge($objectTags, $tags));

		$this->fileService->attachTagsToFile(fileId: (string)$file->getId(), tags: $allTags);
		$this->note(level: 'debug', message: 'Successfully updated file tags: ' . $file->getName());
	}//end replaceUserTags()

	/**
	 * Log that a lookup found a file, with its name and ID.
	 *
	 * @param string $where What found it
	 * @param Node   $file  The file
	 *
	 * @return void
	 */
	private function noteFound(string $where, Node $file): void {
		$this->note(level: 'debug', message: "$where: " . $file->getName() . ' (ID: ' . $file->getId() . ')');
	}//end noteFound()

	/**
	 * Log one updateFile line at a level.
	 *
	 * @param string $level   `debug`, `warning` or `error`
	 * @param string $message The message, without the handler prefix
	 *
	 * @return void
	 */
	private function note(string $level, string $message): void {
		$message = '[UpdateFileHandler] updateFile: ' . $message;
		$context = ['file' => __FILE__, 'line' => __LINE__];
		match ($level) {
			'debug' => $this->logger->debug(message: $message, context: $context),
			'warning' => $this->logger->warning(message: $message, context: $context),
			default => $this->logger->error(message: $message, context: $context),
		};
	}//end note()
}//end class
