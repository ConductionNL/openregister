<?php

/**
 * CreateFileHandler
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
use OCA\OpenRegister\Service\FileHandlerSupportInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;

/**
 * Handles file creation operations with Single Responsibility.
 *
 * This handler is responsible ONLY for:
 * - Creating new files with content
 * - Adding files to objects
 * - Upsert operations (saveFile)
 * - Coordinating tags, sharing, and ownership during creation
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
class CreateFileHandler {

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
	 * Present participle of each upsert action, for the log line of a failure.
	 *
	 * @var array<string, string>
	 */
	private const ACTION_GERUNDS = ['create' => 'creating', 'save' => 'saving'];

	/**
	 * Folder management handler.
	 *
	 * @var FolderManagementHandler
	 */
	private readonly FolderManagementHandler $folderMgmtHandler;

	/**
	 * Object entity mapper.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectEntityMapper;

	/**
	 * Logger for logging operations.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor for CreateFileHandler.
	 *
	 * @param CreateFileSources             $sources              The object folders, the object mapper and the logger a create works with.
	 * @param FileValidationHandler         $fileValidHandler     File validation handler.
	 * @param FileOwnershipHandlerInterface $fileOwnershipHandler File ownership handler.
	 */
	public function __construct(
		CreateFileSources $sources,
		private readonly FileValidationHandler $fileValidHandler,
		private readonly FileOwnershipHandlerInterface $fileOwnershipHandler,
	) {
		$this->folderMgmtHandler = $sources->folderMgmtHandler;
		$this->objectEntityMapper = $sources->objectEntityMapper;
		$this->logger = $sources->logger;
	}//end __construct()

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
	 * Add a file to an object with content, tags, and sharing.
	 *
	 * This method automatically adds an 'object:' tag containing the object's UUID
	 * in addition to any user-provided tags.
	 *
	 * @param FileTarget $target The object entity to add the file to, and its register when the object does not name it.
	 * @param FileUpload $upload The file name, content, share toggle and tags.
	 *
	 * @return File The created file.
	 *
	 * @throws NotPermittedException If file creation fails due to permissions.
	 * @throws Exception If file creation fails for other reasons.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function addFile(FileTarget $target, FileUpload $upload): File {
		$run = new FileUploadRun(action: 'create', upload: $upload);
		$run->object = $target->object;
		return $this->createFile(run: $run, registerId: $target->registerId);
	}//end addFile()

	/**
	 * Write the upload into the object's folder, then share and tag it.
	 *
	 * @param FileUploadRun   $run        The create run.
	 * @param int|string|null $registerId The registerId of the object to add the file to.
	 *
	 * @return File The created file.
	 *
	 * @throws NotPermittedException If file creation fails due to permissions.
	 * @throws Exception If file creation fails for other reasons.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function createFile(FileUploadRun $run, int|string|null $registerId): File {
		try {
			$run->object = $this->resolveObject(objectEntity: $run->object);

			// Use the new ID-based folder approach.
			$folder = $this->folderMgmtHandler->getObjectFolder(objectEntity: $run->object, registerId: $registerId);
			$run->content = $this->decodedContent(content: $run->upload->content);
			$this->assertCreatable(run: $run);
			$file = $this->writeNewFile(run: $run, folder: $folder);
			$this->shareIfRequested(file: $file, share: $run->upload->share);
			$this->tagNewFile(run: $run, file: $file);
			return $file;
		} catch (Exception $e) {
			throw $this->failure(error: $e, run: $run);
		}
	}//end createFile()

	/**
	 * Save a file (upsert operation - create or update).
	 *
	 * This method provides a generic save functionality that checks if a file already exists
	 * for the given object. If it exists, the file will be updated; if not, a new file will
	 * be created. This is particularly useful for synchronization scenarios where you want
	 * to "upsert" files.
	 *
	 * @param ObjectEntity $objectEntity The object entity to save the file to.
	 * @param FileUpload $upload The file name, content, share toggle (only for new files) and tags.
	 *
	 * @return File The saved file.
	 *
	 * @throws NotPermittedException If file operations fail due to permissions.
	 * @throws Exception If file operations fail for other reasons.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function saveFile(ObjectEntity $objectEntity, FileUpload $upload): File {
		$run = new FileUploadRun(action: 'save', upload: $upload);
		$run->object = $objectEntity;
		try {
			// Check if the file already exists for this object.
			$existingFile = $this->fileService->getFile(object: $objectEntity, file: $upload->fileName);
			$this->logUpsert(run: $run, exists: $existingFile !== null);
			if ($existingFile === null) {
				return $this->addFile(target: new FileTarget(object: $objectEntity), upload: $upload);
			}

			// Update the existing file - pass the object so updateFile can find it in the object folder.
			return $this->fileService->overwriteFile(
				overwrite: new FileOverwrite(filePath: $existingFile->getId(), content: $upload->content, tags: $upload->tags, object: $objectEntity)
			);
		} catch (Exception $e) {
			throw $this->failure(error: $e, run: $run);
		}
	}//end saveFile()

	/**
	 * The object entity for a string id, or the id itself when the object does not exist yet.
	 *
	 * The object may be created later in a process (for example: synchronization),
	 * so a file is still created for the given uuid.
	 *
	 * @param ObjectEntity|string $objectEntity The object or its id.
	 *
	 * @return ObjectEntity|string The entity, or the id as given.
	 */
	private function resolveObject(ObjectEntity|string $objectEntity): ObjectEntity|string {
		if (is_string($objectEntity) === false) {
			return $objectEntity;
		}

		try {
			return $this->objectEntityMapper->find($objectEntity);
		} catch (DoesNotExistException) {
			return $objectEntity;
		}
	}//end resolveObject()

	/**
	 * String content with a base64 data URI or plain base64 decoded; a stream resource as given.
	 *
	 * The skip for a stream resource is behaviour-preserving, not a security
	 * control: the streamed (binary-download) path already produced decoded bytes.
	 *
	 * @param string|resource $content The content.
	 *
	 * @return string|resource The content to write.
	 */
	private function decodedContent(mixed $content): mixed {
		if (is_resource($content) === true) {
			return $content;
		}

		$content = $this->dataUriPayload(content: $content);
		$decodedContent = base64_decode($content, true);
		if ($decodedContent !== false && base64_encode($decodedContent) === $content) {
			return $decodedContent;
		}

		return $content;
	}//end decodedContent()

	/**
	 * The base64 payload of a `data:mime/type;base64,...` URI; any other string as given.
	 *
	 * A data URI that is not base64-encoded is left as is (it might be URL-encoded).
	 *
	 * @param string $content The content.
	 *
	 * @return string The payload or the content.
	 */
	private function dataUriPayload(string $content): string {
		if (str_starts_with($content, 'data:') === false) {
			return $content;
		}

		$parts = explode(',', $content, 2);
		if (count($parts) === 2 && str_contains($parts[0], 'base64') === true) {
			return $parts[1];
		}

		return $content;
	}//end dataUriPayload()

	/**
	 * Refuse a nameless file and, by its magic bytes, an executable one.
	 *
	 * @param FileUploadRun $run The run: the object, the file name and the decoded content.
	 *
	 * @return void
	 *
	 * @throws Exception When the name is empty or the content is executable.
	 */
	private function assertCreatable(FileUploadRun $run): void {
		$fileName = $run->upload->fileName;
		if (empty($fileName) === true) {
			$objectId = $run->object->getId();
			throw new Exception('Failed to create file because no filename has been provided for object ' . $objectId);
		}

		// Security: Block executable files.
		$this->fileValidHandler->blockExecutableFile(fileName: $fileName, fileContent: $this->executableProbe(content: $run->content));
	}//end assertCreatable()

	/**
	 * The bytes the executable check reads.
	 *
	 * Executable magic-byte signatures live at offset 0, so on the streamed
	 * (resource) path a bounded prefix is read and the stream rewound before
	 * writing: full parity with the string path at a fixed, small memory cost.
	 * A string is checked directly.
	 *
	 * @param string|resource $content The content.
	 *
	 * @return mixed The content, or the stream's leading bytes.
	 */
	private function executableProbe(mixed $content): mixed {
		if (is_resource($content) === false) {
			return $content;
		}

		$prefix = (string)fread($content, self::EXECUTABLE_MAGIC_BYTE_PREFIX_LENGTH);
		rewind($content);
		return $prefix;
	}//end executableProbe()

	/**
	 * Create the node, assert the session can reach it, then write and re-own it.
	 *
	 * The newFile() call already enforces create permission on the folder; it
	 * creates the node owned by the folder's mount owner (the openregister
	 * system user for the OpenRegister folder, or the original owner for a
	 * folder linked from outside it). Re-owning to the openregister user is only
	 * a fallback when the session lacks write rights.
	 *
	 * @param FileUploadRun $run    The run: the file name and the decoded content.
	 * @param Folder|null   $folder The object folder.
	 *
	 * @return File The written file.
	 *
	 * @throws NotPermittedException When the folder or the new node refuses the session.
	 */
	private function writeNewFile(FileUploadRun $run, ?Folder $folder): File {
		$file = $folder->newFile($run->upload->fileName);
		$this->fileValidHandler->checkOwnership($file);
		$file->putContent($run->content);
		$this->fileOwnershipHandler->transferFileOwnershipIfNeeded($file);
		return $file;
	}//end writeNewFile()

	/**
	 * Create a share link for the file when asked.
	 *
	 * @param File $file  The file.
	 * @param bool $share Whether to share it.
	 *
	 * @return void
	 */
	private function shareIfRequested(File $file, bool $share): void {
		if ($share === true) {
			$this->fileService->createShareLink(path: $file->getPath());
		}
	}//end shareIfRequested()

	/**
	 * Tag the file with the object's automatic tag followed by the caller's tags.
	 *
	 * $allTags always contains at least the object tag, so it is never empty.
	 *
	 * @param FileUploadRun $run  The run: the object and the caller's tags.
	 * @param File          $file The file.
	 *
	 * @return void
	 */
	private function tagNewFile(FileUploadRun $run, File $file): void {
		$allTags = array_merge([$this->fileService->generateObjectTag($run->object)], $run->upload->tags);
		$this->fileService->attachTagsToFile(fileId: (string)$file->getId(), tags: $allTags);
	}//end tagNewFile()

	/**
	 * Log an upsert's decision.
	 *
	 * @param FileUploadRun $run    The run: the file name and the object.
	 * @param bool          $exists Whether the file already exists.
	 *
	 * @return void
	 */
	private function logUpsert(FileUploadRun $run, bool $exists): void {
		$fileName = $run->upload->fileName;
		$objectId = $run->object->getId();
		$message = "[CreateFileHandler] File {$fileName} does not exist for object {$objectId}, creating...";
		if ($exists === true) {
			$message = "[CreateFileHandler] File {$fileName} already exists for object {$objectId}, updating...";
		}

		$this->logger->info(message: $message, context: ['file' => __FILE__, 'line' => __LINE__]);
	}//end logUpsert()

	/**
	 * Log a failed create or save and build the exception that reports it.
	 *
	 * A permission refusal stays a NotPermittedException; anything else is a plain Exception.
	 *
	 * @param Exception     $error The failure.
	 * @param FileUploadRun $run   The run: its action (`create` or `save`) and file name.
	 *
	 * @return Exception The exception to throw.
	 */
	private function failure(Exception $error, FileUploadRun $run): Exception {
		$action   = $run->action;
		$fileName = $run->upload->fileName;
		$reason = $error->getMessage();
		if ($error instanceof NotPermittedException) {
			$this->logger->error(
				message: '[CreateFileHandler] Permission denied '.self::ACTION_GERUNDS[$action]." file $fileName: $reason",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return new NotPermittedException("Cannot $action file $fileName: $reason");
		}

		$this->logger->error(
			message: "[CreateFileHandler] Failed to $action file $fileName: $reason",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return new Exception("Failed to $action file $fileName: $reason");
	}//end failure()

}//end class
