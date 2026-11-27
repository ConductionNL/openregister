<?php

/**
 * FileOwnerLookupInterface: a stored file's owning object and its cache row, by file id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The two file-cache reads the search file arm needs, so the Search area reaches
 * them without naming the Files mapper (ADR-013 Rule 1). Files\Db\FileMapperInterface
 * extends it; FileMapper implements it.
 *
 * The owning-object lookup does no access check of its own: a caller must read
 * the returned object with RBAC and multitenancy applied before showing anything.
 */
interface FileOwnerLookupInterface {

	/**
	 * Get a file from the file cache.
	 *
	 * @param int $fileId The Nextcloud filecache fileid.
	 *
	 * @return array|null The file as an associative array, or null if not found.
	 *
	 * @spec openspec/changes/content-search-index/tasks.md
	 */
	public function getFile(int $fileId): ?array;

	/**
	 * Resolve the uuid of the object that owns a file.
	 *
	 * @param int $fileId The Nextcloud filecache fileid to resolve.
	 *
	 * @return string|null The owning object's UUID, or null when it cannot be resolved.
	 *
	 * @spec openspec/changes/content-search-index/tasks.md
	 */
	public function findOwningObjectUuid(int $fileId): ?string;
}//end interface
