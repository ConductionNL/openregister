<?php

/**
 * ObjectNameWriterInterface: records and reads one object's display name.
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
 * The single-name write and read of CacheHandler.
 *
 * Callers that need only this role depend on it, not on the whole class.
 */
interface ObjectNameWriterInterface {

	/**
	 * Set object name in cache
	 *
	 * Stores the name of an object in both in-memory and distributed caches
	 * for ultra-fast frontend rendering without full object retrieval.
	 *
	 * @param string|int $identifier Object ID or UUID
	 * @param string $name Object name to cache
	 * @param string|null $organisation Owning organisation UUID (SEC-CTRL-2 step 2)
	 * @param string|null $source Where the name came from: NAME_SOURCE_ORGANISATION or
	 *                            "<registerId>:<schemaId>" (see objectNameSource()). Without
	 *                            one the entry is never served; the next lookup resolves it again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function setObjectName(string|int $identifier, string $name, ?string $organisation = null, ?string $source = null): void;

	/**
	 * Get single object name from cache or database
	 *
	 * Provides ultra-fast name lookup for frontend rendering.
	 * Falls back to database if not cached.
	 *
	 * The database LOOKUP is unscoped (`findAcrossAllSources(..., _rbac: false,
	 * _multitenancy: false)`, organisations first), so it can find anything. The
	 * ANSWER is not: an object's name is only returned when the caller may read
	 * the object (the object read path decides, see visibleNames()), and an
	 * organisation's name only inside the caller's organisation scope. The same
	 * decision gates every cache hit.
	 *
	 * It used to back `GET /api/names/{id}`, which was `#[PublicPage]` — any
	 * anonymous caller holding a UUID could read that object's name across tenant
	 * boundaries. That endpoint was removed (SEC-CTRL-2, gate-7); this method
	 * survives only as an internal cache primitive and has no caller in `lib/`.
	 *
	 * A numeric id or slug is never answered from cache: the read path is asked
	 * by UUID, so it goes to the database, which knows the UUID.
	 *
	 * @param string|int $identifier Object ID or UUID
	 *
	 * @return string|null Object name or null if not found
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function getSingleObjectName(string|int $identifier): ?string;
}//end interface
