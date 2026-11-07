<?php

/**
 * FilePublishingHandler
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
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCP\Files\File;
use OCP\IServerContainer;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Handles file publishing and archiving operations.
 *
 * This handler is responsible for:
 * - Publishing files (creating public shares)
 * - Unpublishing files (removing public shares)
 * - Creating ZIP archives of object files
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FilePublishingHandler {

	/**
	 * Reference to FileService for cross-handler coordination (circular dependency break).
	 *
	 * @var FileService|null
	 */
	private ?FileService $fileService = null;

	/**
	 * Constructor for FilePublishingHandler.
	 *
	 * @param MagicObjectFinderInterface $objectEntityMapper Object entity mapper for fetching objects.
	 * @param FileMapperInterface $fileMapper File mapper for share operations.
	 * @param LoggerInterface $logger Logger for logging operations.
	 * @param IServerContainer $container Container, for the lazily resolved party indicator guard.
	 */
	public function __construct(
		private readonly MagicObjectFinderInterface $objectEntityMapper,
		private readonly FileMapperInterface $fileMapper,
		private readonly LoggerInterface $logger,
		private readonly IServerContainer $container,
	) {
	}//end __construct()

	/**
	 * The party indicator guard, resolved at the act rather than injected.
	 *
	 * Deliberately NOT wrapped in a try/catch that degrades to "no guard". A
	 * control that cannot run must stop the act, not wave it through: an
	 * indicator whose effect is refuse publication exists precisely for the
	 * case where publishing is the harm.
	 *
	 * @return PartyIndicatorGuard The guard.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	private function partyIndicatorGuard(): PartyIndicatorGuard {
		return $this->container->get(PartyIndicatorGuard::class);
	}//end partyIndicatorGuard()

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
	 * Publish a file by creating a public share link.
	 *
	 * This method makes a file publicly accessible by creating a public share link.
	 * It handles both file IDs and file paths, creating appropriate shares and tags.
	 *
	 * @param ObjectEntity|string $object The object entity or ID.
	 * @param string|int $file The file ID or path.
	 *
	 * @return File The published file node.
	 *
	 * @throws Exception If publishing fails.
	 *
	 * @phpstan-return File
	 * @psalm-return   File
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function publishFile(ObjectEntity|string $object, string|int $file): File {
		// If string ID provided, try to find the object entity.
		if (is_string($object) === true) {
			$object = $this->objectEntityMapper->find($object);
		}

		// AN INDICATOR ON A PARTY IS EVALUATED WHERE THE ACT HAPPENS.
		//
		// Making a file on an object publicly readable IS the publication a
		// protected address refuses. Checking it in the component that draws
		// the chip would leave the refusal to whoever remembered to read a
		// banner; checking it here means the share is never created. The
		// guard is resolved lazily because FileService already sits in this
		// handler's graph through a setter for exactly the same reason: a
		// constructor dependency on the party model would close that cycle.
		$this->partyIndicatorGuard()->assertPublicationAllowed(
			objectUuid: (string)($object->getUuid() ?? '')
		);

		$this->logger->debug(
			message: "[FilePublishingHandler] publishFile: Original file parameter received: '$file'",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$fileNode = $this->fileFinder(operation: 'publishFile')->find(object: $object, file: $file);

		$this->logger->debug(
			message: '[FilePublishingHandler] publishFile: Creating share link for file: ' . $fileNode->getPath(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		// A restricted file is never published: a public link is served without asking the restriction.
		if ($this->container->get(FileRestrictionGuard::class)->isRestricted(fileId: (int)$fileNode->getId()) === true) {
			throw new Exception('A restricted file cannot be published');
		}

		$this->createPublicShare(fileNode: $fileNode);
		$this->logger->debug(
			message: '[FilePublishingHandler] publishFile: Successfully published file: ' . $fileNode->getName(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return $fileNode;
	}//end publishFile()

	/**
	 * Create the read-only public share of a file directly in the database.
	 *
	 * @param File $fileNode The file.
	 *
	 * @return void
	 *
	 * @throws Exception When the share cannot be created.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function createPublicShare(File $fileNode): void {
		try {
			$openRegisterUser = $this->fileService->getUser();
			$shareInfo = $this->fileMapper->publishFile(
				fileId: $fileNode->getId(),
				sharedBy: $openRegisterUser->getUID(),
				shareOwner: $openRegisterUser->getUID(),
			);

			$message = '[FilePublishingHandler] publishFile: Successfully created public share via FileMapper';
			$message .= " - ID: {$shareInfo['id']}, Token: {$shareInfo['token']}, URL: {$shareInfo['accessUrl']}";
			$this->logger->debug(message: $message, context: ['file' => __FILE__, 'line' => __LINE__]);
		} catch (Exception $e) {
			$errMsg = '[FilePublishingHandler] publishFile: Failed to create share via FileMapper: ' . $e->getMessage();
			$this->logger->error(message: $errMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
			throw new Exception('Failed to create share link: ' . $e->getMessage());
		}
	}//end createPublicShare()

	/**
	 * Unpublish a file by removing public share links.
	 *
	 * This method removes public accessibility from a file by deleting its public
	 * share links and associated tags.
	 *
	 * @param ObjectEntity|string $object The object entity or ID.
	 * @param string|int $filePath The file ID or path.
	 *
	 * @return File The unpublished file node.
	 *
	 * @throws Exception If unpublishing fails.
	 *
	 * @phpstan-return File
	 * @psalm-return   File
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function unpublishFile(ObjectEntity|string $object, string|int $filePath): File {
		// If string ID provided, try to find the object entity.
		if (is_string($object) === true) {
			$object = $this->objectEntityMapper->find($object);
		}

		$this->logger->debug(
			message: "[FilePublishingHandler] unpublishFile: Original file path received: '$filePath'",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$file = $this->fileFinder(operation: 'unpublishFile')->find(object: $object, file: $filePath);

		$this->logger->debug(
			message: '[FilePublishingHandler] unpublishFile: Removing share links for file: ' . $file->getPath(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$this->removePublicShares(file: $file);
		$this->logger->debug(
			message: '[FilePublishingHandler] unpublishFile: Successfully unpublished file: ' . $file->getName(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return $file;
	}//end unpublishFile()

	/**
	 * Remove every public share of a file directly from the database.
	 *
	 * @param File $file The file.
	 *
	 * @return void
	 *
	 * @throws Exception When the shares cannot be removed.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function removePublicShares(File $file): void {
		try {
			$deletionInfo = $this->fileMapper->depublishFile($file->getId());
			$message = '[FilePublishingHandler] unpublishFile: Successfully removed public shares via FileMapper - ';
			$message .= "Deleted shares: {$deletionInfo['deleted_shares']}, File ID: {$deletionInfo['file_id']}";
			$this->logger->debug(message: $message, context: ['file' => __FILE__, 'line' => __LINE__]);

			if ($deletionInfo['deleted_shares'] === 0) {
				$noSharesMsg = '[FilePublishingHandler] unpublishFile: No public shares were found to delete for file: ';
				$noSharesMsg .= $file->getName();
				$this->logger->debug(message: $noSharesMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
			}
		} catch (Exception $e) {
			$errMsg = '[FilePublishingHandler] unpublishFile: Failed to remove shares via FileMapper: ' . $e->getMessage();
			$this->logger->error(message: $errMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
			throw new Exception('Failed to remove share links: ' . $e->getMessage());
		}
	}//end removePublicShares()

	/**
	 * A finder of files in an object's folder, its log lines naming one operation of this handler.
	 *
	 * Built per call: the file service arrives through a setter, after construction.
	 *
	 * @param string $operation The operation, named in every log line.
	 *
	 * @return ObjectFileFinder The finder.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function fileFinder(string $operation): ObjectFileFinder {
		$finder = new ObjectFileFinder(logger: $this->logger, fileService: $this->fileService);
		$finder->prefix = "[FilePublishingHandler] {$operation}:";
		return $finder;
	}//end fileFinder()

	/**
	 * Create a ZIP archive of all files for an object.
	 *
	 * This method collects all files associated with an object and creates a ZIP
	 * archive containing them. The archive is stored in the system temporary directory.
	 *
	 * @param ObjectEntity|string $object The object entity or ID.
	 * @param string|null $zipName Optional custom name for the ZIP file.
	 *
	 * @return (int|string)[]
	 *
	 * @throws Exception If ZIP creation fails.
	 *
	 * @phpstan-return array{path: string, filename: string, size: int, mimeType: string}
	 *
	 * @psalm-return array{path: string, filename: string, size: int, mimeType: 'application/zip'}
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function createObjectFilesZip(ObjectEntity|string $object, ?string $zipName = null): array {
		$object = $this->zipSubject(object: $object);

		$this->logger->debug(
			message: '[FilePublishingHandler] Creating ZIP archive for object: ' . $object->getId(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		if (class_exists('ZipArchive') === false) {
			throw new Exception('PHP ZipArchive extension is not available');
		}

		// Only the files the caller is admitted to; restricted ones are left out.
		$files = $this->container->get(FileRestrictionGuard::class)->readableNodes(nodes: $this->fileService->getFiles($object));
		if (empty($files) === true) {
			throw new Exception('No files found for this object');
		}

		$this->logger->debug(
			message: '[FilePublishingHandler] Found ' . count($files) . ' files for object ' . $object->getId(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$zipName = $this->zipNameFor(object: $object, zipName: $zipName);
		$tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;

		$zip = new ZipArchive();
		$result = $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		if ($result !== true) {
			throw new Exception('Cannot create ZIP file: ' . $this->getZipErrorMessage(errorCode: $result));
		}

		$addedFiles = 0;
		foreach ($files as $file) {
			$entry = $this->zipEntryOf(file: $file);
			if ($entry === null) {
				continue;
			}

			if ($zip->addFromString($entry['name'], $entry['content']) === false) {
				$this->logger->error(
					message: '[FilePublishingHandler] Failed to add file to ZIP: ' . $entry['name'],
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				continue;
			}

			$addedFiles++;
			$this->logger->debug(
				message: '[FilePublishingHandler] Added file to ZIP: ' . $entry['name'],
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}//end foreach

		if ($zip->close() === false) {
			throw new Exception('Failed to finalize ZIP archive');
		}

		$skippedFiles = count($files) - $addedFiles;
		$this->logger->debug(
			message: "[FilePublishingHandler] ZIP creation completed. Added: $addedFiles files, Skipped: $skippedFiles files",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $this->zipResult(path: $tempZipPath, zipName: $zipName);
	}//end createObjectFilesZip()

	/**
	 * Describe a finished archive.
	 *
	 * @param string $path    Where the archive was written.
	 * @param string $zipName Its file name.
	 *
	 * @return array{path: string, filename: string, size: int, mimeType: 'application/zip'} The description.
	 *
	 * @throws Exception When the archive is missing or its size cannot be read.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function zipResult(string $path, string $zipName): array {
		if (file_exists($path) === false) {
			throw new Exception('ZIP file was not created successfully');
		}

		$fileSize = filesize($path);
		if ($fileSize === false) {
			throw new Exception('Cannot determine ZIP file size');
		}

		return [
			'path' => $path,
			'filename' => $zipName,
			'size' => $fileSize,
			'mimeType' => 'application/zip',
		];
	}//end zipResult()

	/**
	 * The object to zip, looked up when given by id.
	 *
	 * @param ObjectEntity|string $object The object entity or ID.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws Exception When no object has that id.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function zipSubject(ObjectEntity|string $object): ObjectEntity {
		if (is_string($object) === false) {
			return $object;
		}

		try {
			return $this->objectEntityMapper->find($object);
		} catch (Exception $e) {
			throw new Exception('Object not found: ' . $e->getMessage());
		}
	}//end zipSubject()

	/**
	 * The archive's file name: the given name with a `.zip` extension, or one
	 * made from the object's uuid (or id) and the current time.
	 *
	 * @param ObjectEntity $object  The object.
	 * @param string|null  $zipName The requested name, if any.
	 *
	 * @return string The file name.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function zipNameFor(ObjectEntity $object, ?string $zipName): string {
		if ($zipName === null) {
			$objectIdentifier = $object->getUuid() ?? (string)$object->getId();
			return 'object_' . $objectIdentifier . '_files_' . date('Y-m-d_H-i-s') . '.zip';
		}

		if (pathinfo($zipName, PATHINFO_EXTENSION) !== 'zip') {
			$zipName .= '.zip';
		}

		return $zipName;
	}//end zipNameFor()

	/**
	 * The name and content to archive for one node. A folder, or a file the
	 * caller cannot read, is logged and skipped.
	 *
	 * @param mixed $file The node, a file or a folder.
	 *
	 * @return array{name: string, content: string}|null The entry, or null to skip the node.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function zipEntryOf($file): ?array {
		if ($file instanceof File === false) {
			$this->logger->warning(
				message: '[FilePublishingHandler] Skipping non-file node: ' . $file->getName(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		try {
			// @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
			$this->fileService->checkOwnership($file);
			return ['name' => $file->getName(), 'content' => $file->getContent()];
		} catch (Exception $e) {
			$this->logger->error(
				message: '[FilePublishingHandler] Error processing file ' . $file->getName() . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end zipEntryOf()

	/**
	 * Get a human-readable error message for ZipArchive error codes.
	 *
	 * @param int $errorCode The ZipArchive error code.
	 *
	 * @return string
	 *
	 * @psalm-return   string
	 * @phpstan-return string
	 */
	private function getZipErrorMessage(int $errorCode): string {
		return match ($errorCode) {
			\ZipArchive::ER_OK => 'No error',
			\ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
			\ZipArchive::ER_RENAME => 'Renaming temporary file failed',
			\ZipArchive::ER_CLOSE => 'Closing zip archive failed',
			\ZipArchive::ER_SEEK => 'Seek error',
			\ZipArchive::ER_READ => 'Read error',
			\ZipArchive::ER_WRITE => 'Write error',
			\ZipArchive::ER_CRC => 'CRC error',
			\ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
			\ZipArchive::ER_NOENT => 'No such file',
			\ZipArchive::ER_EXISTS => 'File already exists',
			\ZipArchive::ER_OPEN => 'Can\'t open file',
			\ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
			\ZipArchive::ER_ZLIB => 'Zlib error',
			\ZipArchive::ER_MEMORY => 'Memory allocation failure',
			\ZipArchive::ER_CHANGED => 'Entry has been changed',
			\ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
			\ZipArchive::ER_EOF => 'Premature EOF',
			\ZipArchive::ER_INVAL => 'Invalid argument',
			\ZipArchive::ER_NOZIP => 'Not a zip archive',
			\ZipArchive::ER_INTERNAL => 'Internal error',
			\ZipArchive::ER_INCONS => 'Zip archive inconsistent',
			\ZipArchive::ER_REMOVE => 'Can\'t remove file',
			\ZipArchive::ER_DELETED => 'Entry has been deleted',
			default => "Unknown error code: $errorCode"
		};//end match
	}//end getZipErrorMessage()
}//end class
