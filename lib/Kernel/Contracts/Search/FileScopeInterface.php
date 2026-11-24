<?php

/**
 * FileScopeInterface: the files a Search comparison reaches, as file storage answers it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Search;

/**
 * Which files an object or a folder holds, and whether the caller may read one.
 *
 * Near-duplicate detection (Search) asks before it compares any text; Files
 * keeps the file cache and the read rule and binds its answer in its own wiring.
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
 */
interface FileScopeInterface {

	/**
	 * The files attached to each object, in one batched lookup.
	 *
	 * @param list<string> $uuids The object uuids.
	 *
	 * @return array<string, int[]> Uuid => its file ids; an empty list when it has none.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function fileIdsOfObjects(array $uuids): array;

	/**
	 * The files directly in a folder, its sub-folders left out.
	 *
	 * @param int $folderId The folder's file id.
	 *
	 * @return list<int> The file ids, in file-cache order.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function fileIdsInFolder(int $folderId): array;

	/**
	 * Whether the caller may read one file.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return bool True when the caller may read it.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function mayReadFile(int $fileId): bool;
}//end interface
