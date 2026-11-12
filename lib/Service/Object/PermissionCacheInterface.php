<?php

/**
 * PermissionCacheInterface: Resets the per-request permission caches.
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

/**
 * The cache resets of PermissionHandler.
 *
 * Callers that need only this role depend on it, not on the whole handler.
 */
interface PermissionCacheInterface {

	/**
	 * Reset the per-request permission verdict cache.
	 *
	 * Long-running CLI processes (e.g. background jobs that span multiple
	 * requests within a single PHP process) can call this to invalidate the
	 * memoised verdicts without instantiating a new handler.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
	 */
	public function clearPermissionCache(): void;

	/**
	 * Clear the per-request inheritFromPublic resolution cache.
	 *
	 * The cache keys purely on schema id, which is collision-free only while
	 * schema/register/IAppConfig authorization is immutable mid-request. Any
	 * path that mutates authorization and then re-reads it within the same
	 * request must bust this cache to avoid serving a stale verdict.
	 *
	 * @param int|null $schemaId Specific schema to evict, or null to clear all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function clearInheritFromPublicCache(?int $schemaId = null): void;
}//end interface
