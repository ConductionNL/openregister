<?php

/**
 * SchemaCacheHandlerInterface: what callers outside the Service component ask of SchemaCacheHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Schema cache invalidation, as the schemas controller uses it. Only the
 * methods that caller calls.
 */
interface SchemaCacheHandlerInterface {

	/**
	 * Drop every cached entry of one schema.
	 *
	 * @param integer $schemaId The schema ID to invalidate.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	public function invalidate(int $schemaId): void;

	/**
	 * Invalidate a schema's cache after it was created, updated or deleted.
	 *
	 * @param integer $schemaId  The schema ID to invalidate.
	 * @param string  $operation The operation performed (create/update/delete).
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	public function invalidateForSchemaChange(int $schemaId, string $operation = 'update'): void;

	/**
	 * Remove one schema from the cache.
	 *
	 * @param integer $schemaId The schema ID to remove from cache.
	 *
	 * @return void
	 */
	public function clearSchemaCache(int $schemaId): void;
}//end interface
