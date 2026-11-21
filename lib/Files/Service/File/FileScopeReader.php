<?php

/**
 * OpenRegister FileScopeReader
 *
 * Answers the Search file-scope port from the file cache and the file read rule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Search\FileScopeInterface;

/**
 * The files of an object or a folder, and the caller's read right on one.
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
 */
final class FileScopeReader implements FileScopeInterface {

	/**
	 * The mime type of a folder in the file cache.
	 */
	public const FOLDER_MIME = 'httpd/unix-directory';

	/**
	 * Constructor.
	 *
	 * @param FileMapperInterface $files     The file cache: an object's files, a folder's children.
	 * @param FileReadScope       $readScope Whether the caller may read a file.
	 */
	public function __construct(
		private readonly FileMapperInterface $files,
		private readonly FileReadScope $readScope,
	) {
	}//end __construct()

	/**
	 * The files attached to each object, in one batched lookup.
	 *
	 * @param list<string> $uuids The object uuids.
	 *
	 * @return array<string, int[]> Uuid => its file ids; an empty list when it has none.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function fileIdsOfObjects(array $uuids): array {
		return $this->files->getFileIdsForObjects(uuids: $uuids);
	}//end fileIdsOfObjects()

	/**
	 * The files directly in a folder, its sub-folders left out.
	 *
	 * @param int $folderId The folder's file id.
	 *
	 * @return list<int> The file ids, in file-cache order.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function fileIdsInFolder(int $folderId): array {
		$ids = [];
		foreach ($this->files->getFiles(node: $folderId) as $row) {
			if (($row['mimetype'] ?? '') !== self::FOLDER_MIME) {
				$ids[] = (int) $row['fileid'];
			}
		}

		return $ids;
	}//end fileIdsInFolder()

	/**
	 * Whether the caller may read one file.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return bool True when the caller may read it.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function mayReadFile(int $fileId): bool {
		return $this->readScope->mayReadFile(fileId: $fileId);
	}//end mayReadFile()
}//end class
