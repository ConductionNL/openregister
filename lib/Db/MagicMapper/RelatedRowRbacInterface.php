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
	 * @param RbacPermissionAsk $ask The schema, the action, and optionally the object owner and data
	 *
	 * @return bool True if user has permission
	 *
	 * @see MagicRbacHandler::hasPermission()
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function hasPermission(RbacPermissionAsk $ask): bool;

	/**
	 * Check if a user has permission to perform an action on one stored row.
	 *
	 * @param RbacPermissionAsk $ask The schema and the action; the row supplies every object fact
	 * @param ObjectEntity      $row The stored row: owner, match envelope, own `_authorization` and uuid
	 *
	 * @return bool True if user has permission
	 *
	 * @see MagicRbacHandler::hasRowPermission()
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function hasRowPermission(RbacPermissionAsk $ask, ObjectEntity $row): bool;

	/**
	 * The RBAC row predicate for rows carried under an alias in a subquery.
	 *
	 * @param Schema      $schema The schema of the rows the subquery reads.
	 * @param AliasedRows $rows   The alias, action and bare magic table of those rows.
	 *
	 * @return string A predicate, always non-empty, safe to AND into a WHERE.
	 *
	 * @throws InvalidArgumentException When the alias is not a plain identifier.
	 *
	 * @see MagicRbacHandler::buildRbacPredicateForAlias()
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function buildRbacPredicateForAlias(Schema $schema, AliasedRows $rows): string;
}//end interface
