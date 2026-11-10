<?php

/**
 * ObjectCacheInvalidationInterface: evicts cached objects and searches after a change.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The invalidation of CacheHandler, called when objects change.
 *
 * Callers that need only this role depend on it, not on the whole class.
 */
interface ObjectCacheInvalidationInterface {

	/**
	 * Invalidate caches when objects are modified (CRUD operations)
	 *
	 * **MAIN CACHE INVALIDATION METHOD**: Called when objects are created,
	 * updated, or deleted to ensure cache consistency across the application.
	 *
	 * @param ObjectEntity|null $object The object that was modified (null for bulk operations)
	 * @param string $operation The operation performed (create/update/delete)
	 * @param int|null $registerId Register ID for targeted invalidation
	 * @param int|null $schemaId Schema ID for targeted invalidation
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function invalidateForObjectChange(
		?ObjectEntity $object = null, string $operation = 'unknown', ?int $registerId = null, ?int $schemaId = null
	): void;

	/**
	 * Clear query result caches
	 *
	 * This method clears cached search results. Called when objects are modified
	 * to ensure cache consistency.
	 *
	 * @param string|null $pattern Optional pattern to clear specific cache entries
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearSearchCache(?string $pattern = null): void;
}//end interface
