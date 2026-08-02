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
class ObjectScopeResolver
{

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
    public function declaredScope(?array $authorization): ?string
    {
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
    public function effectiveScope(?array $objectAuthorization, ?array $schemaAuthorization): string
    {
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
    public function isPrivate(?array $objectAuthorization, ?array $schemaAuthorization): bool
    {
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
    public function schemaDefaultIsPrivate(?array $schemaAuthorization): bool
    {
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
     * @param string|null $userId      The caller, or null when anonymous.
     * @param array       $userGroups  The caller's group IDs.
     * @param string|null $objectOwner The object's owner UID.
     *
     * @return bool True when the caller is the owner or an administrator.
     */
    public function admitsUnconditionally(?string $userId, array $userGroups, ?string $objectOwner): bool
    {
        if (in_array(needle: self::ADMIN_GROUP, haystack: $userGroups, strict: true) === true) {
            return true;
        }

        if ($userId === null || $objectOwner === null) {
            return false;
        }

        return $objectOwner === $userId;
    }//end admitsUnconditionally()


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
     * @param string $columnName             The `_authorization` column, qualified by the caller.
     * @param bool   $schemaDefaultIsPrivate Whether the schema makes its objects private by default.
     * @param bool   $isPostgres             Whether the connected platform is PostgreSQL.
     *
     * @return string A SQL predicate that is true for rows that are not private.
     */
    public function notPrivateSql(string $columnName, bool $schemaDefaultIsPrivate, bool $isPostgres): string
    {
        if ($isPostgres === true) {
            $scope = "({$columnName})::jsonb ->> '".self::SCOPE_KEY."'";
        } else {
            $scope = "JSON_UNQUOTE(JSON_EXTRACT({$columnName}, '$.".self::SCOPE_KEY."'))";
        }

        $organisation = "'".self::SCOPE_ORGANISATION."'";

        if ($schemaDefaultIsPrivate === true) {
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


}//end class
