<?php

/**
 * Object scope resolver — the single definition of the `private` scope.
 *
 * An object's SCOPE answers a different question from an authorization RULE.
 * A rule says "who does this admit"; the scope says "does this object answer to
 * the schema's rules at all". `private` means it does not: the owner, Nextcloud
 * administrators, and principals invited on that one object are the only way in.
 *
 * WHY this lives in one class rather than in each enforcement path. The scope has
 * to be honoured in FOUR places — the single-object verdict
 * ({@see \OCA\OpenRegister\Service\Object\PermissionHandler}), the relation-path
 * verdict ({@see \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler::hasPermission()}),
 * and both list emitters (QueryBuilder and raw SQL). The predecessor change found
 * TWO real divergences between those paths in a single week: a dotted dynamic
 * token honoured only on `find`, and the `authenticated` pseudo-group honoured
 * only on `list`. A principal honoured on some paths and not others is an
 * access-control defect in both directions — over-filtering hides objects a
 * caller is entitled to, under-filtering leaks objects they are not. So the
 * vocabulary, the PHP verdict, and the SQL predicate are defined here exactly
 * once and every path is a caller.
 *
 * STORAGE. The scope is the `scope` key of an authorization block, at two levels:
 *
 *   - the object's `_authorization` column — `{"scope": "private"}`
 *   - the schema's (or register's) `authorization` block — the DEFAULT for
 *     objects of that schema
 *
 * The object wins. This mirrors `inheritFromPublic`, which is already a
 * non-action key in the same block with its own cascade, so no new storage
 * concept is introduced. The schema value is a DEFAULT, not a ceiling: an owner
 * may put their own object back to `organisation`, exactly as a Files user may
 * share a file that started out private.
 *
 * FAIL-CLOSED. Only `organisation` is recognised as non-private. Any other
 * present value — a typo, a boolean, a renamed scope from a future version — is
 * treated as private. That direction hides an object that should have been
 * visible, which is recoverable; the other direction leaks one, which is not.
 * An ABSENT value is not the same as an unrecognised one: absent falls through
 * to the level below, and an object with nothing set anywhere is decided exactly
 * as it was before this capability existed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Resolves the effective object scope and emits the matching SQL predicate.
 */
class ObjectScopeResolver {

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
	 * The administrator group that bypasses every RBAC decision.
	 *
	 * @var string
	 */
	private const ADMIN_GROUP = 'admin';

	/**
	 * Read the scope declared by one authorization block.
	 *
	 * `null` and the empty string mean UNSET — the caller falls through to the
	 * next level. Every other value is a declaration, and only the exact string
	 * `organisation` declares a non-private scope.
	 *
	 * @param array|null $authorization An authorization block, or null.
	 *
	 * @return string|null The declared scope, or null when the block declares none.
	 */
	public function declaredScope(?array $authorization): ?string {
		if (is_array($authorization) === false) {
			return null;
		}

		$raw = ($authorization[self::SCOPE_KEY] ?? null);
		if ($raw === null || $raw === '') {
			return null;
		}

		if (is_string($raw) === true && $raw === self::SCOPE_ORGANISATION) {
			return self::SCOPE_ORGANISATION;
		}

		// Present but unrecognised — including a non-string value. Fail closed.
		return self::SCOPE_PRIVATE;
	}//end declaredScope()

	/**
	 * Resolve the effective scope for one object.
	 *
	 * Precedence: the object's own declaration, then the schema's default, then
	 * `organisation`.
	 *
	 * @param array|null $objectAuthorization The object's `_authorization` column.
	 * @param array|null $schemaAuthorization The schema's (or register's) authorization block.
	 *
	 * @return string One of the SCOPE_* constants.
	 */
	public function effectiveScope(?array $objectAuthorization, ?array $schemaAuthorization): string {
		$onObject = $this->declaredScope(authorization: $objectAuthorization);
		if ($onObject !== null) {
			return $onObject;
		}

		$onSchema = $this->declaredScope(authorization: $schemaAuthorization);
		if ($onSchema !== null) {
			return $onSchema;
		}

		return self::SCOPE_ORGANISATION;
	}//end effectiveScope()

	/**
	 * Whether one object is private.
	 *
	 * @param array|null $objectAuthorization The object's `_authorization` column.
	 * @param array|null $schemaAuthorization The schema's authorization block.
	 *
	 * @return bool True when the object answers only to owner, admins and invitations.
	 */
	public function isPrivate(?array $objectAuthorization, ?array $schemaAuthorization): bool {
		return $this->effectiveScope(
			objectAuthorization: $objectAuthorization,
			schemaAuthorization: $schemaAuthorization
		) === self::SCOPE_PRIVATE;
	}//end isPrivate()

	/**
	 * Whether a schema makes its objects private by default.
	 *
	 * The list emitters need this as a PHP-side constant: the schema default is
	 * known when the query is BUILT, so it selects which of the two row
	 * predicates {@see notPrivateSql()} emits, rather than being tested per row.
	 *
	 * @param array|null $schemaAuthorization The schema's authorization block.
	 *
	 * @return bool True when objects of this schema are private unless they say otherwise.
	 */
	public function schemaDefaultIsPrivate(?array $schemaAuthorization): bool {
		return $this->declaredScope(authorization: $schemaAuthorization) === self::SCOPE_PRIVATE;
	}//end schemaDefaultIsPrivate()

	/**
	 * Whether a caller is admitted to a private object without an invitation.
	 *
	 * The owner and administrators, and nobody else. This is evaluated BEFORE
	 * any scope or rule evaluation and is never conditional on either — an owner
	 * who makes their own object private must not thereby lock themselves out of
	 * it, which is the failure mode that makes a privacy feature unusable.
	 *
	 * Per-object invitations are the other way in; they are resolved by the
	 * grant layer and composed on top of this verdict.
	 *
	 * @param string|null $userId The caller, or null when anonymous.
	 * @param array $userGroups The caller's group IDs.
	 * @param string|null $objectOwner The object's owner UID.
	 * @param array|null $authorization The object's `_authorization` block, which may name an
	 *                                  owning group. Omit it and only the named owner admits,
	 *                                  which is what this method did before group ownership.
	 *
	 * @return bool True when the caller is an owner or an administrator.
	 */
	public function admitsUnconditionally(
		?string $userId,
		array $userGroups,
		?string $objectOwner,
		?array $authorization = null,
	): bool {
		if (in_array(needle: self::ADMIN_GROUP, haystack: $userGroups, strict: true) === true) {
			return true;
		}

		// The OWNING GROUP admits on the same terms as the named owner. Checked
		// before the uid comparison because a member of the owning group need
		// not be the named owner, which is the whole point of having one.
		$ownerGroup = $this->ownerGroup(authorization: $authorization);
		if ($ownerGroup !== null && in_array(needle: $ownerGroup, haystack: $userGroups, strict: true) === true) {
			return true;
		}

		if ($userId === null || $objectOwner === null) {
			return false;
		}

		return $objectOwner === $userId;
	}//end admitsUnconditionally()

	/**
	 * The group that owns one object, or null when no group does.
	 *
	 * Only a non-empty string counts. Anything else — an array, a boolean, an
	 * empty string — is read as "no owning group", because an unreadable value
	 * must not admit anybody.
	 *
	 * @param array|null $authorization The object's `_authorization` block.
	 *
	 * @return string|null The owning group id, or null.
	 */
	public function ownerGroup(?array $authorization): ?string {
		if (is_array($authorization) === false) {
			return null;
		}

		$raw = ($authorization[self::OWNER_GROUP_KEY] ?? null);
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		return $raw;
	}//end ownerGroup()

	/**
	 * One platform-appropriate predicate for "this row is NOT private".
	 *
	 * Emitted as a raw fragment so BOTH list emitters can use the same string —
	 * the QueryBuilder path wraps it in `createFunction()`. QueryBuilder cannot
	 * express JSON member extraction portably, so a second implementation there
	 * would be a second definition of the vocabulary, which is precisely what
	 * this class exists to prevent.
	 *
	 * The two shapes differ only in what an ABSENT object-level declaration
	 * means, and that is decided by the schema default the caller passes in:
	 *
	 *   schema default organisation → absent means organisation → NOT private
	 *   schema default private      → absent means private     → private
	 *
	 * COST. This predicate lands on list queries for schemas that have no
	 * authorization block at all, because an OBJECT may declare itself private
	 * on an otherwise open schema — skipping it there would be a silent leak on
	 * exactly the schemas nobody is watching. The `IS NULL` disjunct is first so
	 * the common row, whose `_authorization` was never written, is decided
	 * without touching the JSON at all.
	 *
	 * @param string $columnName The `_authorization` column, qualified by the caller.
	 * @param bool $defaultPrivate Whether the schema makes its objects private by default.
	 * @param bool $isPostgres Whether the connected platform is PostgreSQL.
	 *
	 * @return string A SQL predicate that is true for rows that are not private.
	 */
	public function notPrivateSql(string $columnName, bool $defaultPrivate, bool $isPostgres): string {
		$scope = $this->jsonKeySql(columnName: $columnName, key: self::SCOPE_KEY, isPostgres: $isPostgres);

		$organisation = "'" . self::SCOPE_ORGANISATION . "'";

		if ($defaultPrivate === true) {
			// Under a private default an unwritten column means private, so the
			// `IS NULL` short-circuit is deliberately absent here: only an
			// explicit organisation declaration takes a row back out.
			// COALESCE keeps the fragment two-valued: without it a row whose
			// block carries no `scope` key yields NULL, which a WHERE clause
			// reads as false but a caller wrapping this in NOT would not.
			return "({$columnName} IS NOT NULL AND COALESCE(({$scope}) = {$organisation}, FALSE))";
		}

		// Absent (never written, or written without a scope) or explicitly
		// organisation. Every other value is private, including one this
		// version does not recognise.
		return "({$columnName} IS NULL OR ({$scope}) IS NULL OR ({$scope}) = '' OR ({$scope}) = {$organisation})";
	}//end notPrivateSql()

	/**
	 * One key of the JSON block, as a platform-appropriate expression.
	 *
	 * Extracted so the scope and the owning group are read the same way on both
	 * platforms. Two hand-written extractions of the same JSON object is how the
	 * predecessor change grew its divergences.
	 *
	 * @param string $columnName The `_authorization` column, qualified by the caller.
	 * @param string $key The block key to read.
	 * @param bool $isPostgres Whether the connected platform is PostgreSQL.
	 *
	 * @return string An SQL expression yielding the key's value as text.
	 */
	private function jsonKeySql(string $columnName, string $key, bool $isPostgres): string {
		if ($isPostgres === true) {
			return "({$columnName})::jsonb ->> '" . $key . "'";
		}

		return "JSON_UNQUOTE(JSON_EXTRACT({$columnName}, '$." . $key . "'))";
	}//end jsonKeySql()

	/**
	 * The predicate for "this caller is in the group that owns this row".
	 *
	 * The list half of the owning group, and the reason it is here rather than in
	 * either emitter: the single-object verdict admits a member of the owning
	 * group unconditionally, so a list that filtered the row out would hide an
	 * object its reader may open and edit.
	 *
	 * IT BELONGS BESIDE THE OWNER ADMIT, NOT INSIDE "not private". That placement
	 * is what makes it mean the same thing on both sides: an owner admit is ORed
	 * over the whole query, while the not-private predicate is ANDed with the
	 * schema's rules. Putting the group in the second one would have admitted it
	 * subject to the rules in a list while {@see admitsUnconditionally()} admitted
	 * it regardless on a single read — a principal honoured differently per path,
	 * which is exactly what this class exists to prevent.
	 *
	 * @param string $authColumn The `_authorization` column, qualified by the caller.
	 * @param bool $isPostgres Whether the connected platform is PostgreSQL.
	 * @param string[] $quotedUserGroups The caller's group ids, ALREADY quoted as SQL literals.
	 *
	 * @return string|null The predicate, or null when the caller is in no group.
	 */
	public function ownedByMyGroupSql(string $authColumn, bool $isPostgres, array $quotedUserGroups): ?string {
		if ($quotedUserGroups === []) {
			return null;
		}

		$group = $this->jsonKeySql(columnName: $authColumn, key: self::OWNER_GROUP_KEY, isPostgres: $isPostgres);

		return '(' . $group . ' IN (' . implode(', ', $quotedUserGroups) . '))';
	}//end ownedByMyGroupSql()

	/**
	 * The predicate for "this row is reachable at all by this caller".
	 *
	 * A grant makes a private row behave, for this caller, exactly as an
	 * ordinary row — and the schema's rules then decide, as they already do
	 * (design D3b). So the whole grant feature is this one disjunct, and there
	 * is no second admit path in either emitter to keep in step with the first:
	 *
	 *     owner OR ((notPrivate OR grantedToMe) AND rules)
	 *
	 * The schema remains the CEILING. A grant re-opens a private row within what
	 * the rules would have allowed; it cannot admit somebody the schema refuses,
	 * which is what the spec means by "`private` cannot widen access".
	 *
	 * @param string $authColumn The `_authorization` column, qualified by the caller.
	 * @param bool $defaultPrivate Whether the schema makes its objects private by default.
	 * @param bool $isPostgres Whether the connected platform is PostgreSQL.
	 * @param string $uuidColumn The `_uuid` column, qualified by the caller.
	 * @param string[] $quotedUuids Granted object UUIDs, ALREADY quoted as SQL literals.
	 *
	 * @return string A SQL predicate true for rows this caller may reach.
	 */
	public function notPrivateOrGrantedSql(
		string $authColumn,
		bool $defaultPrivate,
		bool $isPostgres,
		string $uuidColumn,
		array $quotedUuids,
	): string {
		$notPrivate = $this->notPrivateSql(
			columnName: $authColumn,
			defaultPrivate: $defaultPrivate,
			isPostgres: $isPostgres
		);

		if (empty($quotedUuids) === true) {
			return $notPrivate;
		}

		$inList = implode(', ', $quotedUuids);

		return "({$notPrivate} OR {$uuidColumn} IN ({$inList}))";
	}//end notPrivateOrGrantedSql()
}//end class
