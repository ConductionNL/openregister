<?php

/**
 * OpenRegister NearDuplicateScope
 *
 * Turns the scope of a near-duplicate request (objects, a folder or files)
 * into the files the caller may read, before any text is compared.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Quality
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

namespace OCA\OpenRegister\Search\Service\Quality;

use InvalidArgumentException;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\FileReadScope;

/**
 * The readable files of one near-duplicate scope.
 */
final class NearDuplicateScope {

	/**
	 * The most files one request may compare.
	 */
	public const MAX_FILES = 5000;

	/**
	 * The mime type of a folder in the file cache.
	 */
	private const FOLDER_MIME = 'httpd/unix-directory';

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
	 * The files of the scope the caller may read, in ascending id order.
	 *
	 * Exactly one of the three scopes must be given. A folder scope takes the
	 * files directly in the folder. Every file is checked for read access
	 * before it is returned, so an unreadable file never reaches a comparison.
	 *
	 * @param list<string> $objectIds The objects whose attached files are compared.
	 * @param int|null     $folderId  The folder whose files are compared.
	 * @param list<int>    $fileIds   The files compared.
	 *
	 * @return list<int> The readable file ids.
	 *
	 * @throws InvalidArgumentException When not exactly one scope is given, or it holds more than MAX_FILES files.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function readableFileIds(array $objectIds, ?int $folderId, array $fileIds): array {
		$given = (int) ($objectIds !== []) + (int) ($folderId !== null) + (int) ($fileIds !== []);
		if ($given !== 1) {
			throw new InvalidArgumentException('Give exactly one scope: objectIds, folderId or fileIds');
		}

		$candidates = $this->scopeFileIds(objectIds: $objectIds, folderId: $folderId, fileIds: $fileIds);
		if (count($candidates) > self::MAX_FILES) {
			throw new InvalidArgumentException('A near-duplicate scope may hold at most '.self::MAX_FILES.' files');
		}

		$readable = [];
		foreach ($candidates as $fileId) {
			if ($this->readScope->mayReadFile(fileId: $fileId) === true) {
				$readable[] = $fileId;
			}
		}

		return $readable;
	}//end readableFileIds()

	/**
	 * The distinct file ids the scope names, before any read check.
	 *
	 * @param list<string> $objectIds The objects.
	 * @param int|null     $folderId  The folder.
	 * @param list<int>    $fileIds   The files.
	 *
	 * @return list<int> The file ids, ascending.
	 */
	private function scopeFileIds(array $objectIds, ?int $folderId, array $fileIds): array {
		$ids = $fileIds;
		if ($objectIds !== []) {
			$ids = [];
			foreach ($this->files->getFileIdsForObjects(uuids: $objectIds) as $objectFileIds) {
				array_push($ids, ...$objectFileIds);
			}
		}

		if ($folderId !== null) {
			$ids = [];
			foreach ($this->files->getFiles(node: $folderId) as $row) {
				if (($row['mimetype'] ?? '') !== self::FOLDER_MIME) {
					$ids[] = (int) $row['fileid'];
				}
			}
		}

		$ids = array_values(array_unique(array_map('intval', $ids)));
		sort($ids);

		return $ids;
	}//end scopeFileIds()
}//end class
