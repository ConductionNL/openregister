<?php

/**
 * ObjectEntityCacheInterface: reads objects through the in-memory object cache.
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
 * The object reads and preloads of CacheHandler.
 *
 * Callers that need only this role depend on it, not on the whole class.
 */
interface ObjectEntityCacheInterface {

	/**
	 * Get an object from cache or database
	 *
	 * This method first checks the in-memory cache before falling back to the database.
	 * It automatically caches retrieved objects for future use.
	 *
	 * @param int|string $identifier The object ID or UUID
	 *
	 * @return ObjectEntity|null The object or null if not found
	 *
	 * @phpstan-return ObjectEntity|null
	 * @psalm-return   ObjectEntity|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getObject(int|string $identifier): ?ObjectEntity;

	/**
	 * Bulk preload objects to warm the cache
	 *
	 * This method loads multiple objects in a single database query and caches them
	 * all, significantly improving performance for operations that access many objects.
	 *
	 * With a register, the identifiers are looked up in that register's tables
	 * first and only the ones not found there in every table. A reference
	 * nearly always points into its own register, and one lookup across every
	 * magic table is a UNION over all of them (1.7k tables: 1.5-3.6 s).
	 *
	 * @param array $identifiers Array of object IDs/UUIDs to preload
	 * @param int|null $registerId The register to look in first, or null for every table.
	 *
	 * @return ObjectEntity[]
	 *
	 * @phpstan-param array<int|string> $identifiers
	 *
	 * @phpstan-return array<ObjectEntity>
	 *
	 * @psalm-param array<int|string> $identifiers
	 *
	 * @psalm-return array<ObjectEntity>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function preloadObjects(array $identifiers, ?int $registerId = null): array;
}//end interface
