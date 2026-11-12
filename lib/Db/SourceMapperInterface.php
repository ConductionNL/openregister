<?php

/**
 * SourceMapperInterface: what callers outside the Db component use of SourceMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of SourceMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to SourceMapper.
 */
interface SourceMapperInterface {

	/**
	 * Creates a source from an array
	 *
	 * @param array $object The object to create
	 *
	 * @return Source The created source
	 *
	 */
	public function createFromArray(array $object): Source;

	/**
	 * Delete a source
	 *
	 * @param Entity $entity Source entity to delete
	 *
	 * @return Source The deleted source
	 * @throws \Exception If user doesn't have delete permission or access to this organisation
	 *
	 */
	public function delete(Entity $entity): Source;

	/**
	 * Finds a source by id
	 *
	 * @param int $id The id of the source
	 *
	 * @return Source The source
	 * @throws \Exception If user doesn't have read permission
	 */
	public function find(int $id): Source;

	/**
	 * Finds all sources
	 *
	 * @param int|null   $limit   The limit of the results, null for all.
	 * @param int|null   $offset  The offset of the results
	 * @param array|null $filters The filters to apply
	 *
	 * @return Source[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Source>
	 */
	public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[]): array;

	/**
	 * Finds the sources one list query asks for.
	 *
	 * @param ListQuery $query The limit (unbounded for all), offset and column filters.
	 *
	 * @return Source[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Source>
	 */
	public function findList(ListQuery $query): array;

	/**
	 * Find all sources that have scheduled sync enabled.
	 *
	 * Intended for the SyncDataJob background context which runs as the
	 * system actor across all organisations; therefore RBAC and the
	 * organisation filter are intentionally NOT applied here. Each
	 * returned source still carries its `organisation` field so the
	 * harvest pipeline can scope the objects it creates.
	 *
	 * @return Source[] Sources with sync_enabled = true
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Source>
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function findBySyncEnabled(): array;

	/**
	 * Load a Source by id or uuid for a trusted, code-initiated system lookup —
	 * intentionally WITHOUT RBAC verification and WITHOUT the organisation filter.
	 *
	 * A `Source` is shared infrastructure config (a database connection), not
	 * tenant-owned data. Its ONLY current caller,
	 * `DbalObjectSourceProvider::resolveSource()`, resolves the `sourceId`
	 * named in an already-authorized schema's `x-openregister-object-source`
	 * config — `ObjectService::paginateObjectSource()` enforces the schema's
	 * own read RBAC (`checkPermission()`) BEFORE the provider (and therefore
	 * this lookup) ever runs. Filtering this row by the caller's active
	 * organisation adds no isolation — the caller never sees the Source row
	 * itself, only the objects it serves for a schema they were already
	 * cleared to read — and instead breaks every dbal-backed schema whose
	 * Source is configured in a different organisation than the reader's
	 * active one, most visibly under `saasMode: true` where admin override is
	 * unconditionally disabled (openregister#2089: `resolveSource()` returned
	 * null and every downstream find/count on the schema silently emptied,
	 * logged only as a warning).
	 *
	 * Mirrors the same rationale as {@see findBySyncEnabled()} (system-actor
	 * lookup, no tenant scoping) rather than the SystemOperationContext RBAC
	 * bypass: this method skips the ORGANISATION FILTER on the Source row
	 * itself, a query-builder concern `SystemOperationContext` does not touch.
	 *
	 * SECURITY: this method MUST NOT be exposed to any caller that has not
	 * already authorized the request at the schema/object level, and MUST
	 * NOT be used to serve Source data (credentials, connection config)
	 * directly to a client — only to locate the row so its provider can serve
	 * the schema's own RBAC-gated objects.
	 *
	 * @param string $sourceId The Source id (digits) or uuid.
	 *
	 * @return Source|null The source, or null when not found.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 * @spec openspec/changes/dbal-source-resolution-system-context/specs/dbal-source-resolution-system-context/spec.md
	 */
	public function findForSystem(string $sourceId): ?Source;

	/**
	 * Update an existing source
	 *
	 * @param Entity $entity Source entity to update
	 *
	 * @return Source The updated source
	 * @throws \Exception If user doesn't have update permission or access to this organisation
	 */
	public function update(Entity $entity): Source;

	/**
	 * Updates a source from an array
	 *
	 * @param int $id The id of the source to update
	 * @param array $object The object to update
	 *
	 * @return Source The updated source
	 */
	public function updateFromArray(int $id, array $object): Source;
}//end interface
