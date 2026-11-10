<?php

/**
 * The contract callers of DenyResolver type against.
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
 * The narrow contract of DenyResolver, bound in ContractBindings.
 *
 * Every method is described on DenyResolver.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
interface DenyResolverInterface {

	/**
	 * The authorization-block key carrying the denials.
	 *
	 * @var string
	 */
	public const DENY_KEY = 'deny';

	/**
	 * The pseudo-principal every caller carries, authenticated or not.
	 *
	 * @var string
	 */
	public const PRINCIPAL_PUBLIC = 'public';

	/**
	 * The pseudo-principal every signed-in caller carries.
	 *
	 * @var string
	 */
	public const PRINCIPAL_AUTHENTICATED = 'authenticated';

	/**
	 * The prefix that makes a deny entry name one user rather than a group.
	 *
	 * @var string
	 */
	public const USER_PREFIX = 'user:';

	/**
	 * The deny sub-block of one authorization block.
	 *
	 * @param array|null $authorization An authorization block, or null.
	 *
	 * @return array<string, array> The deny block, keyed by action; empty when none is declared.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function denyBlock(?array $authorization): array;

	/**
	 * Whether a block declares any denial at all.
	 *
	 * @param array|null $authorization An authorization block, or null.
	 *
	 * @return bool True when at least one action carries a non-empty deny list.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function declaresAnyDeny(?array $authorization): bool;

	/**
	 * The deny entries written for one action.
	 *
	 * @param array|null $authorization An authorization block, or null.
	 * @param string     $action        The verb being decided.
	 *
	 * @return array<int, mixed> The entries, in declaration order.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function entriesFor(?array $authorization, string $action): array;

	/**
	 * Union two deny blocks, so a narrower level can only ADD denials.
	 *
	 * @param array|null $baseline The deny block resolved from the schema or register.
	 * @param array|null $override The deny block declared closer to the object.
	 *
	 * @return array<string, array> The union, keyed by action.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function mergeDeny(?array $baseline, ?array $override): array;

	/**
	 * The principal names a deny entry may use to reach one caller.
	 *
	 * @param string|null $userId     The caller, or null when anonymous.
	 * @param string[]    $userGroups The caller's group IDs.
	 *
	 * @return string[] The principal names, deduplicated.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function principalsFor(?string $userId, array $userGroups): array;

	/**
	 * Whether one deny entry names one of these principals.
	 *
	 * @param mixed    $entry      A single deny entry.
	 * @param string[] $principals The caller's principal names.
	 *
	 * @return string|null The principal the entry named, or null when it names none.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function entryNames(mixed $entry, array $principals): ?string;

	/**
	 * The first denial that removes the verb outright, whatever the object.
	 *
	 * @param array|null $authorization The resolved authorization block.
	 * @param string     $action        The verb being decided.
	 * @param string[]   $principals    The caller's principal names.
	 *
	 * @return array{rule: mixed, principal: string, action: string, conditional: bool}|null The denial, or null.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function unconditionalDenial(?array $authorization, string $action, array $principals): ?array;

	/**
	 * The denials that only bite on the rows their `match` clause selects.
	 *
	 * @param array|null $authorization The resolved authorization block.
	 * @param string     $action        The verb being decided.
	 * @param string[]   $principals    The caller's principal names.
	 *
	 * @return array<int, array{rule: mixed, principal: string, action: string, conditional: bool}> The conditional denials.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function conditionalDenials(?array $authorization, string $action, array $principals): array;

	/**
	 * One platform-appropriate predicate for "this row is NOT denied to this caller".
	 *
	 * @param string   $columnName The `_authorization` column, qualified by the caller.
	 * @param string   $action     The verb being filtered.
	 * @param bool     $isPostgres Whether the connected platform is PostgreSQL.
	 * @param string[] $principals The caller's principal names.
	 *
	 * @return string A SQL predicate true for rows this caller is not denied.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function notDeniedRowSql(string $columnName, string $action, bool $isPostgres, array $principals): string;
}//end interface
