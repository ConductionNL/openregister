<?php

/**
 * The contract callers of ObjectScopeResolver type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The narrow contract of ObjectScopeResolver, bound in ContractBindings.
 *
 * Every method is described on ObjectScopeResolver.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
interface ObjectScopeResolverInterface {

	/**
	 * The authorization-block key carrying the scope.
	 *
	 * @var string
	 */
	public const SCOPE_KEY = 'scope';

	/**
	 * The default scope: the object answers to the schema's rules.
	 *
	 * @var string
	 */
	public const SCOPE_ORGANISATION = 'organisation';

	/**
	 * The private scope: owner, administrators and invited principals only.
	 *
	 * @var string
	 */
	public const SCOPE_PRIVATE = 'private';

	/**
	 * The authorization-block key carrying the OWNING GROUP.
	 *
	 * A second owner, which is a group rather than a person. It is stored beside
	 * `scope` in the same block, for the reason `scope` is stored there: the
	 * object's `_authorization` column already is the per-object access record,
	 * and a new column would need a migration on every magic table to say
	 * something the block can already carry.
	 *
	 * It admits on exactly the terms the named owner does, which is what makes a
	 * colleague able to edit the record without being handed it first. It is NOT
	 * a grant: a grant re-opens a private object within the schema's ceiling,
	 * while the owning group IS an owner and so is admitted unconditionally.
	 *
	 * @var string
	 */
	public const OWNER_GROUP_KEY = 'ownerGroup';

	/**
	 * Read the scope declared by one authorization block.
	 *
	 * @param array|null $authorization An authorization block, or null.
	 *
	 * @return string|null The declared scope, or null when the block declares none.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function declaredScope(?array $authorization): ?string;

	/**
	 * Resolve the effective scope for one object.
	 *
	 * @param array|null $objectAuthorization The object's `_authorization` column.
	 * @param array|null $schemaAuthorization The schema's (or register's) authorization block.
	 *
	 * @return string One of the SCOPE_* constants.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function effectiveScope(?array $objectAuthorization, ?array $schemaAuthorization): string;

	/**
	 * Whether one object is private.
	 *
	 * @param array|null $objectAuthorization The object's `_authorization` column.
	 * @param array|null $schemaAuthorization The schema's authorization block.
	 *
	 * @return bool True when the object answers only to owner, admins and invitations.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function isPrivate(?array $objectAuthorization, ?array $schemaAuthorization): bool;

	/**
	 * Whether a schema makes its objects private by default.
	 *
	 * @param array|null $schemaAuthorization The schema's authorization block.
	 *
	 * @return bool True when objects of this schema are private unless they say otherwise.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function schemaDefaultIsPrivate(?array $schemaAuthorization): bool;

	/**
	 * Whether a caller is admitted to a private object without an invitation.
	 *
	 * @param string|null $userId The caller, or null when anonymous.
	 * @param array $userGroups The caller's group IDs.
	 * @param string|null $objectOwner The object's owner UID.
	 * @param array|null $authorization The object's `_authorization` block, which may name an
	 *                                  owning group. Omit it and only the named owner admits,
	 *                                  which is what this method did before group ownership.
	 *
	 * @return bool True when the caller is an owner or an administrator.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function admitsUnconditionally(?string $userId, array $userGroups, ?string $objectOwner, ?array $authorization = null): bool;

	/**
	 * The group that owns one object, or null when no group does.
	 *
	 * @param array|null $authorization The object's `_authorization` block.
	 *
	 * @return string|null The owning group id, or null.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function ownerGroup(?array $authorization): ?string;

	/**
	 * One platform-appropriate predicate for "this row is NOT private".
	 *
	 * @param AuthorizationColumn $column         The `_authorization` column (qualified by the caller) and whether the
	 *                                            connected platform is PostgreSQL.
	 * @param bool                $defaultPrivate Whether the schema makes its objects private by default.
	 *
	 * @return string A SQL predicate that is true for rows that are not private.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md
	 */
	public function notPrivateSql(AuthorizationColumn $column, bool $defaultPrivate): string;

	/**
	 * The predicate for "this caller is in the group that owns this row".
	 *
	 * @param AuthorizationColumn $column           The `_authorization` column (qualified by the caller) and whether
	 *                                              the connected platform is PostgreSQL.
	 * @param string[]            $quotedUserGroups The caller's group ids, ALREADY quoted as SQL literals.
	 *
	 * @return string|null The predicate, or null when the caller is in no group.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function ownedByMyGroupSql(AuthorizationColumn $column, array $quotedUserGroups): ?string;

	/**
	 * The predicate for "this row is reachable at all by this caller".
	 *
	 * @param string      $notPrivateSql The not-private term for the row, from notPrivateSql().
	 * @param GrantedRows $granted       The `_uuid` column and the granted uuids, quoted.
	 *
	 * @return string A SQL predicate true for rows this caller may reach.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function notPrivateOrGrantedSql(string $notPrivateSql, GrantedRows $granted): string;
}//end interface
