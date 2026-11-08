<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Finds a file in an object's folder, by id or by path, logging each step.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Resolves a file id or path to the file in an object's folder: by id, else
 * by file name, else by full path. Every step is logged under the prefix of
 * the operation the lookup serves.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ObjectFileFinder
{

	/**
	 * The log prefix, naming the handler and the operation.
	 *
	 * @var string
	 */
	public string $prefix = '';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface                        $logger      The logger.
	 * @param \OCA\OpenRegister\Service\FileService $fileService The file service.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly \OCA\OpenRegister\Service\FileService $fileService,
	) {
	}//end __construct()

	/**
	 * The file an id or path names in an object's folder.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int|string   $file   The file id or path.
	 *
	 * @return File The file, its ownership checked.
	 *
	 * @throws \Exception When no file is found, or the node is not a file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function find(ObjectEntity $object, int|string $file): File {
		$lookup = new ObjectFileLookup(object: $object, file: $file);
		$lookup->prefix = $this->prefix;
		return $this->resolve(lookup: $lookup);
	}//end find()

	/**
	 * The file a lookup names: by id in the object's folder, else by name and then by full path.
	 *
	 * The node must be a File, and its ownership is checked (a workaround for
	 * Nextcloud rights issues that otherwise surface as "File not found").
	 *
	 * @param ObjectFileLookup $lookup The lookup.
	 *
	 * @return File The file.
	 *
	 * @throws \Exception When no file is found, or the node is not a file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function resolve(ObjectFileLookup $lookup): File {
		if (is_int($lookup->file) === true) {
			return $this->checkedFile(lookup: $lookup, node: $this->objectFileById(lookup: $lookup));
		}

		return $this->checkedFile(lookup: $lookup, node: $this->objectFileByPath(lookup: $lookup));
	}//end resolve()

	/**
	 * The node a lookup found, once it is known to be a File and its ownership is checked.
	 *
	 * @param ObjectFileLookup $lookup The lookup.
	 * @param Node             $node   The node it found.
	 *
	 * @return File The file.
	 *
	 * @throws \Exception When the node is not a file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function checkedFile(ObjectFileLookup $lookup, Node $node): File {
		if ($node instanceof File === false) {
			$nodeClass = get_class($node);
			$this->logger->error(
				message: "{$lookup->prefix} Found node is not a File instance, it's a: $nodeClass",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception('File not found.');
		}

		$this->fileService->checkOwnership($node);
		return $node;
	}//end checkedFile()

	/**
	 * The file with a lookup's id in the object's folder.
	 *
	 * @param ObjectFileLookup $lookup The lookup, by id.
	 *
	 * @return File The file.
	 *
	 * @throws \Exception When there is no file with that id.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function objectFileById(ObjectFileLookup $lookup): File {
		$this->logger->debug(message: "{$lookup->prefix} File ID provided: {$lookup->file}", context: ['file' => __FILE__, 'line' => __LINE__]);
		$fileNode = $this->fileService->getFile(object: $lookup->object, file: $lookup->file);
		if ($fileNode === null) {
			$this->logger->error(message: "{$lookup->prefix} No file found with ID: {$lookup->file}", context: ['file' => __FILE__, 'line' => __LINE__]);
			throw new Exception("File with ID {$lookup->file} does not exist");
		}

		$foundMsg = "{$lookup->prefix} Found file by ID: " . $fileNode->getName();
		$foundMsg .= ' (ID: ' . $fileNode->getId() . ')';
		$this->logger->debug(message: $foundMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
		return $fileNode;
	}//end objectFileById()

	/**
	 * The node at a lookup's path in the object's folder.
	 *
	 * @param ObjectFileLookup $lookup The lookup, by path.
	 *
	 * @return Node The node.
	 *
	 * @throws \Exception When the folder or the file is not found.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function objectFileByPath(ObjectFileLookup $lookup): Node {
		$pathInfo = $this->fileService->extractFileNameFromPath($lookup->file);
		$lookup->filePath = $pathInfo['cleanPath'];
		$lookup->fileName = $pathInfo['fileName'];
		$this->logger->debug(message: "{$lookup->prefix} After cleaning: '{$lookup->filePath}'", context: ['file' => __FILE__, 'line' => __LINE__]);
		if ($lookup->fileName !== $lookup->filePath) {
			$extractMsg = "{$lookup->prefix} Extracted filename from path: '{$lookup->fileName}' (from '{$lookup->filePath}')";
			$this->logger->debug(message: $extractMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
		}

		$lookup->folder = $this->objectFolder(lookup: $lookup);
		$this->logFolderListing(lookup: $lookup);
		return $this->nodeInObjectFolder(lookup: $lookup);
	}//end objectFileByPath()

	/**
	 * The folder of a lookup's object.
	 *
	 * @param ObjectFileLookup $lookup The lookup.
	 *
	 * @return \OCP\Files\Folder The object's folder.
	 *
	 * @throws \Exception When the object has no folder.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function objectFolder(ObjectFileLookup $lookup): \OCP\Files\Folder {
		$objectFolder = $this->fileService->getObjectFolder($lookup->object);
		if ($objectFolder === null) {
			$this->error(message: "{$lookup->prefix} Could not get object folder for object: " . $lookup->object->getId());
			throw new Exception('Object folder not found.');
		}

		$this->debug(message: "{$lookup->prefix} Object folder path: " . $objectFolder->getPath());
		return $objectFolder;
	}//end objectFolder()

	/**
	 * Log the names of the files in the object's folder; a listing failure is logged, not thrown.
	 *
	 * @param ObjectFileLookup $lookup The lookup, its folder found.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function logFolderListing(ObjectFileLookup $lookup): void {
		try {
			$objectFileNames = array_map(static fn ($file) => $file->getName(), $lookup->folder->getDirectoryListing());
			$this->debug(message: "{$lookup->prefix} Files in object folder: " . json_encode($objectFileNames));
		} catch (\Exception $e) {
			$this->error(message: "{$lookup->prefix} Error listing object folder contents: " . $e->getMessage());
		}
	}//end logFolderListing()

	/**
	 * The node a lookup's file name names in the object's folder, else its full path.
	 *
	 * @param ObjectFileLookup $lookup The lookup, its folder found.
	 *
	 * @return Node The node.
	 *
	 * @throws \Exception When neither the name nor the full path is found.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function nodeInObjectFolder(ObjectFileLookup $lookup): Node {
		try {
			$this->debug(message: "{$lookup->prefix} Attempting to get file '{$lookup->fileName}' from object folder");
			$fileNode = $lookup->folder->get($lookup->fileName);
			$this->debug(message: "{$lookup->prefix} Successfully found file: " . $fileNode->getName() . ' at ' . $fileNode->getPath());
			return $fileNode;
		} catch (NotFoundException $e) {
			return $this->nodeAtFullPath(lookup: $lookup);
		} catch (\Exception $e) {
			$this->error(message: "{$lookup->prefix} Unexpected error getting file from object folder: " . $e->getMessage());
			throw new Exception('File not found.');
		}
	}//end nodeInObjectFolder()

	/**
	 * The node at a lookup's full path in the object's folder.
	 *
	 * @param ObjectFileLookup $lookup The lookup, its folder found.
	 *
	 * @return Node The node.
	 *
	 * @throws \Exception When the full path is not found either.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function nodeAtFullPath(ObjectFileLookup $lookup): Node {
		try {
			$this->debug(message: "{$lookup->prefix} Attempting to get file '{$lookup->filePath}' (full path) from object folder");
			$fileNode = $lookup->folder->get($lookup->filePath);
			$found = $fileNode->getName() . ' at ' . $fileNode->getPath();
			$this->debug(message: "{$lookup->prefix} Successfully found file using full path: " . $found);
			return $fileNode;
		} catch (NotFoundException $e2) {
			$missing = "'{$lookup->fileName}' and '{$lookup->filePath}' not found in object folder";
			$this->error(message: "{$lookup->prefix} File $missing. NotFoundException: " . $e2->getMessage());
			throw new Exception('File not found.');
		}
	}//end nodeAtFullPath()

	/**
	 * Log a debug line.
	 *
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function debug(string $message): void {
		$this->logger->debug(message: $message, context: ['file' => __FILE__, 'line' => __LINE__]);
	}//end debug()

	/**
	 * Log an error line.
	 *
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function error(string $message): void {
		$this->logger->error(message: $message, context: ['file' => __FILE__, 'line' => __LINE__]);
	}//end error()
}//end class
