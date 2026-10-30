<?php

/**
 * ObjectAccessRulesInterface: the schema access rules for the current caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * Answers whether the current caller may act on a schema's objects, and builds
 * the access predicate a subquery over another schema's rows must carry.
 * Implemented by Db\MagicMapper\MagicRbacHandler.
 */
interface ObjectAccessRulesInterface {

	/**
	 * Get the current user ID
	 *
	 * @return string|null The current user ID or null if not authenticated
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function getCurrentUserId(): ?string;

	/**
	 * Check if a user has permission to perform an action on a schema
	 *
	 * This checks whether the user has any possible access to the schema. For
	 * conditional rules, it returns true when the user qualifies for the group
	 * and, when object data is supplied, the object satisfies the match clause.
	 *
	 * @param SchemaInterface $schema Schema to check
	 * @param string $action CRUD action to check
	 * @param string|null $objectOwner Optional object owner for ownership check
	 * @param array|null $objectData Optional object data for conditional checks
	 * @param array|null $objectAuthorization Optional per-object `_authorization`, carrying its scope
	 * @param string|null $objectUuid Optional object UUID, for per-object grant resolution
	 *
	 * @return bool True if user has permission
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function hasPermission(
		SchemaInterface $schema,
		string $action,
		?string $objectOwner = null,
		?array $objectData = null,
		?array $objectAuthorization = null,
		?string $objectUuid = null,
	): bool;

	/**
	 * Check if current user is admin
	 *
	 * @return bool True if user is in admin group
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function isAdmin(): bool;

	/**
	 * The access predicate for one table, under one alias, as a single string.
	 *
	 * A bypass (admin) is returned as `TRUE` and no conditions at all (deny
	 * all) as `FALSE`, so the predicate is never empty.
	 *
	 * @param SchemaInterface $schema The schema of the rows the subquery reads.
	 * @param string $alias  The alias those rows carry in the subquery.
	 * @param string $action The CRUD action being filtered.
	 * @param string|null $tableName The bare magic table of the aliased rows; a `_status` column it lacks is left out of the draft predicate.
	 *
	 * @return string A predicate, always non-empty, safe to AND into a WHERE.
	 *
	 * @throws \InvalidArgumentException When the alias is not a plain identifier.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function buildRbacPredicateForAlias(SchemaInterface $schema, string $alias, string $action = 'read', ?string $tableName = null): string;
}//end interface
