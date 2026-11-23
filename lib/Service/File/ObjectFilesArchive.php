<?php

/**
 * ObjectFilesArchive: file storage's answer to the object API's archive download.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\BulkDownloadArchive;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFilesArchiveInterface;
use OCA\OpenRegister\Service\FileService;

/**
 * Builds an object's ZIP and writes its one bulk-download audit entry through FileService.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ObjectFilesArchive implements ObjectFilesArchiveInterface {

	/**
	 * Constructor.
	 *
	 * @param FileService $files The file service: the archive, the object's files and their audit handler.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		private readonly FileService $files,
	) {
	}//end __construct()

	/**
	 * Build a temporary ZIP of every file in the object's folder.
	 *
	 * @param ObjectEntity $object  The object whose files are archived.
	 * @param string|null  $zipName The requested file name, or null for the default.
	 *
	 * @return array The temporary file's path, name, MIME type and size.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function createZip(ObjectEntity $object, ?string $zipName): array {
		return $this->files->createObjectFilesZip(object: $object, zipName: $zipName);
	}//end createZip()

	/**
	 * Record the download as one audit entry naming every file of the object.
	 *
	 * @param ObjectEntity $object  The object whose files were downloaded.
	 * @param array        $zipInfo The archive as createZip() answered it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function recordDownload(ObjectEntity $object, array $zipInfo): void {
		$files = $this->files->getFiles($object);
		$this->files->getAuditHandler()->logBulkDownload(
			object: $object,
			archive: new BulkDownloadArchive(
				fileIds: array_map(static fn ($file) => $file->getId(), $files),
				fileNames: array_map(static fn ($file) => $file->getName(), $files),
				zipName: $zipInfo['filename'],
				totalBytes: $zipInfo['size'] ?? null
			)
		);
	}//end recordDownload()
}//end class
