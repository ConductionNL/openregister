<?php

/**
 * RelatedRowRbacInterface: the RBAC answers the related-object read paths ask of MagicRbacHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
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

namespace OCA\OpenRegister\Db\MagicMapper;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The RBAC answers the related-object read paths ask of MagicRbacHandler.
 *
 * Callers outside lib/Db type against this role; it is bound to
 * MagicRbacHandler, so the decisions are the handler's own.
 */
interface RelatedRowRbacInterface {
	/**
	 * Check if current user is admin.
	 *
	 * @return bool True if user is in admin group
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function isAdmin(): bool;

	/**
	 * Get the current user ID.
	 *
	 * @return string|null The current user ID or null if not authenticated
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getCurrentUserId(): ?string;

	/**
	 * Check if a user has permission to perform an action on a schema.
	 *
	 * @param Schema      $schema              Schema to check
	 * @param string      $action              CRUD action to check
	 * @param string|null $objectOwner         Optional object owner for ownership check
	 * @param array|null  $objectData          Optional object data for conditional checks
	 *
	 * @return bool True if user has permission
	 *
	 * @see MagicRbacHandler::hasPermission()
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function hasPermission(
		Schema $schema,
		string $action,
		?string $objectOwner = null,
		?array $objectData = null,
	): bool;

	/**
	 * Check if a user has permission to perform an action on one stored row.
	 *
	 * @param Schema       $schema Schema to check
	 * @param string       $action CRUD action to check
	 * @param ObjectEntity $row    The stored row: owner, match envelope, own `_authorization` and uuid
	 *
	 * @return bool True if user has permission
	 *
	 * @see MagicRbacHandler::hasRowPermission()
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function hasRowPermission(Schema $schema, string $action, ObjectEntity $row): bool;

	/**
	 * The RBAC row predicate for rows carried under an alias in a subquery.
	 *
	 * @param Schema      $schema    The schema of the rows the subquery reads.
	 * @param string      $alias     The alias those rows carry in the subquery.
	 * @param string      $action    The CRUD action being filtered.
	 * @param string|null $tableName The bare magic table of the aliased rows.
	 *
	 * @return string A predicate, always non-empty, safe to AND into a WHERE.
	 *
	 * @throws InvalidArgumentException When the alias is not a plain identifier.
	 *
	 * @see MagicRbacHandler::buildRbacPredicateForAlias()
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function buildRbacPredicateForAlias(Schema $schema, string $alias, string $action = 'read', ?string $tableName = null): string;
}//end interface
