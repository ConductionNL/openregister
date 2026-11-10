<?php

/**
 * OpenRegister FilePropertyFiles
 *
 * The files a file property already holds on its object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use OCP\Files\File;

/**
 * Clears a file property's stored files, and tells whether the object holds a given file.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
final class FilePropertyFiles {

	/**
	 * Constructor.
	 *
	 * @param FilePropertyLog      $log         The file property log.
	 * @param ObjectFilesInterface $fileService File service for file operations.
	 */
	public function __construct(
		private readonly FilePropertyLog $log,
		private readonly ObjectFilesInterface $fileService,
	) {
	}//end __construct()

	/**
	 * Delete the files a property holds in the STORED object data and return its cleared value.
	 *
	 * The stored data is read, not the current entity state, which prepareObjectData may have
	 * changed. A file that cannot be deleted is logged and skipped.
	 *
	 * @param FilePropertyTarget $target The file property.
	 *
	 * @return array<never>|null An empty array for an array property, else null.
	 */
	public function clear(FilePropertyTarget $target): ?array {
		$this->log->write(
			level: 'info',
			message: '[FilePropertyHandler] File property deletion requested',
			context: ['propertyName' => $target->name, 'uuid' => $target->objectEntity->getUuid()]
		);
		$this->deleteStoredFiles(target: $target);

		$cleared = $this->clearedValue(target: $target);
		$this->log->write(
			level: 'info',
			message: '[FilePropertyHandler] File property set to null/empty',
			context: ['propertyName' => $target->name, 'newValue' => $cleared]
		);
		return $cleared;
	}//end clear()

	/**
	 * Whether the object holds the file with this id; a lookup failure is logged and means no.
	 *
	 * @param FileStoreRequest $request The object and property.
	 * @param mixed            $fileId  The numeric file id.
	 *
	 * @return bool
	 */
	public function holdsFile(FileStoreRequest $request, mixed $fileId): bool {
		if ($this->existingFile(request: $request, fileId: $fileId) === null) {
			return false;
		}

		$this->log->write(
			level: 'debug',
			message: '[FilePropertyHandler] Using existing file ID {fileId} for property {property}',
			context: ['fileId' => $fileId, 'property' => $request->propertyName]
		);
		return true;
	}//end holdsFile()

	/**
	 * The object's file with this id, or null when it is absent or cannot be retrieved (logged).
	 *
	 * @param FileStoreRequest $request The object.
	 * @param mixed            $fileId  The numeric file id.
	 *
	 * @return File|null
	 */
	private function existingFile(FileStoreRequest $request, mixed $fileId): ?File {
		try {
			return $this->fileService->getFile(object: $request->objectEntity, file: (int)$fileId);
		} catch (Exception $e) {
			$this->log->write(
				level: 'debug',
				message: '[FilePropertyHandler] Could not retrieve existing file {fileId}: {error}',
				context: ['fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end existingFile()

	/**
	 * The cleared value of a file property: an empty array for an array property, else null.
	 *
	 * @param FilePropertyTarget $target The file property.
	 *
	 * @return array<never>|null
	 */
	private function clearedValue(FilePropertyTarget $target): ?array {
		if ($target->isArray === true) {
			return [];
		}

		return null;
	}//end clearedValue()

	/**
	 * Delete every numeric file id a property holds in the stored object data.
	 *
	 * @param FilePropertyTarget $target The file property.
	 *
	 * @return void
	 */
	private function deleteStoredFiles(FilePropertyTarget $target): void {
		foreach ($this->storedFileIds(target: $target) as $fileId) {
			if (is_numeric($fileId) === true) {
				$this->deleteStoredFile(target: $target, fileId: $fileId);
			}
		}
	}//end deleteStoredFiles()

	/**
	 * The file ids a property holds in the stored object data, one id or a list, as a list; logged.
	 *
	 * @param FilePropertyTarget $target The file property.
	 *
	 * @return array<mixed>
	 */
	private function storedFileIds(FilePropertyTarget $target): array {
		$existingFileIds = ($target->objectEntity->getObject() ?? [])[$target->name] ?? null;
		$this->log->write(
			level: 'info',
			message: '[FilePropertyHandler] Existing file IDs for deletion',
			context: ['propertyName' => $target->name, 'existingFileIds' => $existingFileIds]
		);

		if (is_array($existingFileIds) === true) {
			return $existingFileIds;
		}

		return [$existingFileIds];
	}//end storedFileIds()

	/**
	 * Delete one stored file of a property; a failure is logged, not thrown.
	 *
	 * @param FilePropertyTarget $target The file property.
	 * @param mixed              $fileId The file id, numeric.
	 *
	 * @return void
	 */
	private function deleteStoredFile(FilePropertyTarget $target, mixed $fileId): void {
		try {
			$this->fileService->deleteFile(file: (int)$fileId, object: $target->objectEntity);
			$this->log->note(level: 'info', message: "[FilePropertyHandler] Deleted file $fileId for property {$target->name}");
		} catch (Exception $e) {
			// The file might already be deleted.
			$this->log->note(level: 'warning', message: "[FilePropertyHandler] Failed to delete file $fileId: " . $e->getMessage());
		}
	}//end deleteStoredFile()
}//end class
