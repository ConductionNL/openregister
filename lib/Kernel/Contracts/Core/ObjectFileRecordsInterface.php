<?php
/**
 * ObjectFileRecordsInterface: which files an object has, and which object a file belongs to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The file records Core reads about objects: rendering an object's files and resolving a content hit to its object.
 *
 * Core asks; file storage (Files) keeps the records and binds its answer in its own registrar.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface ObjectFileRecordsInterface {

	/**
	 * One file record by its Nextcloud file id, with its share information and owner.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return array|null The file record, or null when there is no such file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getFile(int $fileId): ?array;

	/**
	 * Several file records in one query, keyed by file id, each shaped as getFile() returns it.
	 *
	 * @param array $fileIds The file ids (int or numeric string).
	 *
	 * @return array<int|string, array> File id => file record.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getFilesByIds(array $fileIds): array;

	/**
	 * The file records in an object's folder.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, array> The file records.
	 *
	 * @throws \RuntimeException When more than one folder matches the object's uuid.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getFilesForObject(ObjectEntity $object): array;

	/**
	 * The file ids in each object's folder, for a list of object uuids.
	 *
	 * @param string[] $uuids The object uuids.
	 *
	 * @return array<string, int[]> Uuid => file ids (empty when it has none).
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getFileIdsForObjects(array $uuids): array;

	/**
	 * The uuid of the object whose folder holds a file: an unauthenticated candidate the caller reads under RBAC.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return string|null The object's uuid, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function findOwningObjectUuid(int $fileId): ?string;

	/**
	 * The category of each file that has an OR-side metadata row, in one query: a file listing filters on it.
	 *
	 * @param int[] $fileIds The Nextcloud file ids.
	 *
	 * @return array<int, string|null> File id => its category (null when the row has none); files without a row are absent.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function findCategoriesByFileIds(array $fileIds): array;
}//end interface
