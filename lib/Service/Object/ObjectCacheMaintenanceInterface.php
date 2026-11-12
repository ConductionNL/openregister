<?php

/**
 * ObjectCacheMaintenanceInterface: administers the object and name caches.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;

/**
 * The administrative clears and counts of CacheHandler.
 *
 * Callers that need only this role depend on it, not on the whole class.
 */
interface ObjectCacheMaintenanceInterface extends ObjectNameCacheInterface {

	/**
	 * Clear the cache (legacy method - kept for backward compatibility)
	 *
	 * @deprecated Use clearAllCaches() instead
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearCache(): void;

	/**
	 * Clear object name caches
	 *
	 * Removes all cached object names from both in-memory and distributed caches.
	 * Called when objects are modified to ensure name consistency.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearNameCache(): void;

	/**
	 * Get the name cache count from distributed cache metadata
	 *
	 * Returns the count of names stored in the distributed cache,
	 * useful for cross-request statistics.
	 *
	 * @return int The number of names in distributed cache, or 0 if unavailable
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getDistributedNameCacheCount(): int;
}//end interface
