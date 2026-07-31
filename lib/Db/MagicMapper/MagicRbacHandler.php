<?php

/**
 * MagicMapper RBAC Handler
 *
 * This handler provides role-based access control (RBAC) filtering for dynamic
 * schema-based tables, providing RBAC logic optimized for schema-specific
 * table structures.
 *
 * KEY RESPONSIBILITIES:
 * - Apply RBAC permission filters to dynamic table queries
 * - Handle user authentication and authorization checks
 * - Support dynamic variables ($organisation, $userId, $now) in conditions
 * - Integrate with Nextcloud's user and group management
 * - Provide consistent security across all dynamic tables
 *
 * RBAC FEATURES:
 * - Schema-level authorization configuration
 * - User ownership validation
 * - Group-based access control
 * - Dynamic variable resolution ($now, $organisation, $userId)
 * - Admin override capabilities
 * - Unauthenticated user handling
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper RBAC capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * RBAC (Role-Based Access Control) handler for MagicMapper dynamic tables
 *
 * This class provides comprehensive RBAC filtering for dynamically created
 * schema-based tables, ensuring that users can only access objects they have
 * permission to view based on schema authorization configurations.
 *
 * Two enforcement paths live here:
 *   1. SQL emission — {@see applyRbacFilters()} and {@see buildRbacConditionsSql()}
 *      translate conditional rules into `WHERE` fragments for the list endpoint.
 *      This is the canonical row-level path and remains specialised to this class.
 *   2. PHP-side verdict — {@see hasPermission()} dispatches simple string rules
 *      locally (group-in-groups membership only) and delegates the `match:` branch
 *      of conditional rules to {@see \OCA\OpenRegister\Service\ConditionMatcher},
 *      the shared matcher used across the RBAC stack (ADR-011). New conditional
 *      operators or dynamic variables MUST be added to ConditionMatcher /
 *      OperatorEvaluator, not re-implemented here. The string-rule dispatch is
 *      intentionally kept in-class because it needs no operator vocabulary.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class MagicRbacHandler
{

    /**
     * Raw-SQL impossible predicate used to fail closed on the SQL-string RBAC path.
     *
     * Emitted when a `match` rule's dynamic variable resolves to null so the
     * predicate is NOT silently dropped from the AND (#1953). Mirrors the
     * QueryBuilder path's {@see impossibleCondition()} and ConditionMatcher's
     * fail-closed semantics.
     *
     * @var string
     */
    private const IMPOSSIBLE_SQL_CONDITION = '1 = 0';

    /**
     * Memoised database-platform verdict for `$contains` SQL emission.
     *
     * Null until first resolved; the platform cannot change within a request.
     *
     * @var boolean|null
     */
    private ?bool $isPostgresCache = null;

    /**
     * Constructor for MagicRbacHandler
     *
     * @param IUserSession       $userSession      User session for current user context
     * @param IGroupManager      $groupManager     Group manager for user group operations
     * @param IUserManager       $userManager      User manager for user operations
     * @param IAppConfig         $appConfig        App configuration for RBAC settings
     * @param ConditionMatcher   $conditionMatcher Shared PHP-side match evaluator (ADR-011; SQL emitter stays here).
     * @param ContainerInterface $container        Container for service injection
     * @param LoggerInterface    $logger           Logger for debugging
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IAppConfig $appConfig,
        private readonly ConditionMatcher $conditionMatcher,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Apply RBAC filters to a query builder based on schema authorization
     *
     * This method implements the RBAC filtering logic with support for conditional rules:
     * 1. If user is admin, no filtering is applied
     * 2. If schema has no authorization, no filtering is applied (open access)
     * 3. Rules can be simple (group name string) or conditional (object with group and match)
     * 4. Simple rules grant access if user is in that group
     * 5. Conditional rules grant access if user qualifies for group AND object matches conditions
     * 6. Object owner always has access to their own objects
     *
     * @param IQueryBuilder $qb     Query builder to modify
     * @param Schema        $schema Schema with authorization configuration
     * @param string        $action CRUD action to check (default: 'read')
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function applyRbacFilters(
        IQueryBuilder $qb,
        Schema $schema,
        string $action='read'
    ): void {
        $user   = $this->userSession->getUser();
        $userId = $user?->getUID();

        // Get user groups.
        $userGroups = [];
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
        }

        // Admin users bypass all RBAC checks.
        if (in_array('admin', $userGroups, true) === true) {
            return;
        }

        // CLI / no-session system context (occ commands, repair steps, cron
        // jobs, background calculations) has no user session. These are trusted
        // system operations and bypass RBAC filtering, mirroring the established
        // CLI bypass in MultiTenancyTrait::hasRbacPermission(). Without this a
        // schema with explicit authorization rules would clamp every CLI query
        // to `1 = 0` and hide all rows from background calcs / list views.
        if ($user === null && PHP_SAPI === 'cli') {
            $this->logger->debug(
                message: '[MagicRbacHandler] CLI/system context — bypassing RBAC filter',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        // Get effective authorization (schema-level, or register cascade).
        // Fail-closed: an unresolvable authorization clamps the query to the
        // IMPOSSIBLE predicate rather than falling through to the open default.
        try {
            $authorization = $this->resolveSchemaAuthorization(schema: $schema);
        } catch (AuthorizationUnresolvableException $e) {
            $this->logger->error(
                message: '[MagicRbacHandler] Authorization unresolvable; clamping query to deny-all (fail-closed)',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $schema->getId(),
                    'action'   => $action,
                    'error'    => $e->getMessage(),
                ]
            );
            $qb->andWhere($qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0)));
            return;
        }

        // If no authorization is configured, schema is open to all.
        if (empty($authorization) === true) {
            $this->logger->debug(
                message: '[MagicRbacHandler] No authorization configured, schema is open',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        // Get authorization rules for this action.
        $rules = $authorization[$action] ?? [];

        // Fail-closed: a non-empty authorization block that does not list this
        // action no longer opens the table. Flow falls through to the owner
        // condition below and, when nothing matches, the existing `1 = 0`
        // deny-all — so an omitted action yields owner-only rows (or none),
        // consistent with PermissionHandler/hasPermission. Admins already
        // returned earlier; the empty-block open-default above is unchanged.
        if (empty($rules) === true) {
            $this->logger->debug(
                message: '[MagicRbacHandler] Action not configured on a non-empty authorization block — failing closed',
                context: ['file' => __FILE__, 'line' => __LINE__, 'action' => $action]
            );
        }

        // Build the RBAC filter conditions.
        $conditions = [];

        // Condition: User is the owner of the object (owners always have access).
        if ($userId !== null) {
            $conditions[] = $qb->expr()->eq('t._owner', $qb->createNamedParameter($userId));
        }

        // Condition: User is in a configured system-reader group - grant
        // visibility on rows owned by the system identifier (e.g. cron-written
        // rows). See openregister#1617. Admins already returned at line 147 so
        // this branch only fires for non-admin reader-group members.
        if ($this->shouldGrantSystemRowVisibility(userGroups: $userGroups) === true) {
            $conditions[] = $qb->expr()->eq(
                't._owner',
                $qb->createNamedParameter($this->getSystemUserId())
            );
        }

        // Resolve whether authenticated users inherit `public` rights once.
        $inheritFromPublic = $this->authenticatedInheritsPublic(schema: $schema);

        // Process each authorization rule.
        foreach ($rules as $rule) {
            $ruleCondition = $this->processAuthorizationRule(
                qb: $qb,
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId,
                inheritFromPublic: $inheritFromPublic
            );

            if ($ruleCondition === true) {
                // User has unconditional access via this rule - no filtering needed.
                return;
            }

            if ($ruleCondition !== null && $ruleCondition !== false) {
                // Add the SQL condition for this rule.
                $conditions[] = $ruleCondition;
            }
        }//end foreach

        // If no conditions were added, deny all access.
        if (empty($conditions) === true) {
            $this->logger->debug(
                message: '[MagicRbacHandler] No access conditions met, denying all',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'userId' => $userId,
                    'action' => $action,
                ]
            );
            // Add impossible condition to return no results.
            $qb->andWhere($qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0)));
            return;
        }

        // Apply OR of all conditions (access granted if ANY condition matches).
        $qb->andWhere($qb->expr()->orX(...$conditions));
    }//end applyRbacFilters()

    /**
     * Resolve whether authenticated users inherit `public` group rights for a schema.
     *
     * Delegates to PermissionHandler::resolveInheritFromPublic() (cascade:
     * schema → register → IAppConfig → true) via the container so the SQL
     * emitters and the PHP-side check honour the flag identically. Fails safe
     * to `true` (the pre-flag behaviour) if resolution errors.
     *
     * @param Schema $schema The schema being filtered.
     *
     * @return bool True when authenticated users inherit `public` rights.
     *
     * @spec openspec/changes/rbac-disable-public-inheritance/tasks.md
     */
    private function authenticatedInheritsPublic(Schema $schema): bool
    {
        try {
            return $this->container->get(PermissionHandler::class)->resolveInheritFromPublic(schema: $schema);
        } catch (\Throwable $e) {
            // Fail to the configured tenant posture rather than a hard-coded
            // grant, so a cluster-wide lock-down (rbac.inherit_from_public_default
            // = false) is not silently softened on this code path. Symmetric with
            // PermissionHandler::resolveInheritFromPublic()'s own fail-safe.
            $fallback = true;
            try {
                $fallback = $this->container->get(PermissionHandler::class)->inheritFromPublicTenantDefault();
            } catch (\Throwable $inner) {
                $fallback = $this->appConfig->getValueBool(
                    app: 'openregister',
                    key: 'rbac.inherit_from_public_default',
                    default: true
                );
            }

            $this->logger->error(
                message: '[MagicRbacHandler] inheritFromPublic resolution failed; falling back to tenant default',
                context: ['file' => __FILE__, 'line' => __LINE__, 'default' => $fallback, 'error' => $e->getMessage()]
            );
            return $fallback;
        }//end try
    }//end authenticatedInheritsPublic()

    /**
     * Decide whether the current principal qualifies for a `public` rule.
     *
     * Anonymous users (no userId) always qualify for `public`. Authenticated
     * users qualify only when public inheritance is enabled for the schema.
     *
     * @param string|null $userId            Current user ID (null = anonymous).
     * @param bool        $inheritFromPublic Resolved inheritFromPublic value.
     *
     * @return bool True when the principal qualifies for the `public` group.
     */
    private function qualifiesForPublic(?string $userId, bool $inheritFromPublic): bool
    {
        return $userId === null || $inheritFromPublic === true;
    }//end qualifiesForPublic()

    /**
     * Process a single authorization rule
     *
     * @param IQueryBuilder $qb                Query builder
     * @param mixed         $rule              Authorization rule (string or array)
     * @param array         $userGroups        User's group IDs
     * @param string|null   $userId            Current user ID
     * @param bool          $inheritFromPublic Whether auth users inherit public rights
     *
     * @return mixed True if unconditional access, SQL expression for conditional, null/false if no access
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    private function processAuthorizationRule(
        IQueryBuilder $qb,
        mixed $rule,
        array $userGroups,
        ?string $userId,
        bool $inheritFromPublic
    ): mixed {
        // Simple rule: just a group name string.
        if (is_string($rule) === true) {
            return $this->processSimpleRule(rule: $rule, userGroups: $userGroups, userId: $userId, inheritFromPublic: $inheritFromPublic);
        }

        // Conditional rule: object with 'group' (or a 'user' override) and
        // optional 'match'.
        if (is_array($rule) === true && (isset($rule['group']) === true || isset($rule['user']) === true)) {
            return $this->processConditionalRule(
                qb: $qb,
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId,
                inheritFromPublic: $inheritFromPublic
            );
        }

        // Invalid rule format.
        $this->logger->warning(
            message: '[MagicRbacHandler] Invalid authorization rule format',
            context: ['file' => __FILE__, 'line' => __LINE__, 'rule' => $rule]
        );
        return null;
    }//end processAuthorizationRule()

    /**
     * Process a simple (unconditional) authorization rule
     *
     * @param string      $rule              Group name
     * @param array       $userGroups        User's group IDs
     * @param string|null $userId            Current user ID
     * @param bool        $inheritFromPublic Whether auth users inherit public rights
     *
     * @return bool True if user has access, false otherwise
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    private function processSimpleRule(string $rule, array $userGroups, ?string $userId, bool $inheritFromPublic): bool
    {
        // 'public' grants access to anonymous users always; authenticated users
        // only when public inheritance is enabled (inheritFromPublic).
        if ($rule === 'public') {
            return $this->qualifiesForPublic(userId: $userId, inheritFromPublic: $inheritFromPublic);
        }

        // 'authenticated' grants access to any logged-in user.
        if ($rule === 'authenticated') {
            return $userId !== null;
        }

        // User-level override (delegation): a bare `user:<uid>` rule grants the
        // action to that one user on the LIST path, mirroring PermissionHandler's
        // single-object verdict (rbac-zaaktype). Fail closed: anonymous users and
        // non-matching uids never qualify. Additive — never widens group access.
        if ($this->matchesUserOverride(rule: $rule, userId: $userId) === true) {
            return true;
        }

        // Check if user is in the specified group.
        if (in_array($rule, $userGroups, true) === true) {
            return true;
        }

        return false;
    }//end processSimpleRule()

    /**
     * Determine whether a bare authorization rule is a `user:<uid>` override
     * that grants access to the current user.
     *
     * Shared by the QueryBuilder, raw-SQL, and multitenancy-bypass paths so the
     * three LIST emitters agree with PermissionHandler's single-object verdict.
     * SECURITY: exact-uid match only; anonymous principals never match; the
     * `user:` prefix with a blank uid never matches.
     *
     * @param string      $rule   Authorization rule string.
     * @param string|null $userId Current user ID (null = anonymous).
     *
     * @return bool True when the rule is a user override for the current user.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    private function matchesUserOverride(string $rule, ?string $userId): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }

        if (str_starts_with($rule, 'user:') === false) {
            return false;
        }

        $targetUid = substr($rule, strlen('user:'));
        return $targetUid !== '' && $targetUid === $userId;
    }//end matchesUserOverride()

    /**
     * Process a conditional authorization rule
     *
     * @param IQueryBuilder $qb                Query builder
     * @param array         $rule              Rule with 'group' and optional 'match'
     * @param array         $userGroups        User's group IDs
     * @param string|null   $userId            Current user ID
     * @param bool          $inheritFromPublic Whether auth users inherit public rights
     *
     * @return mixed True if unconditional access, SQL expression for conditional, false if no access
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    private function processConditionalRule(
        IQueryBuilder $qb,
        array $rule,
        array $userGroups,
        ?string $userId,
        bool $inheritFromPublic
    ): mixed {
        $group = ($rule['group'] ?? null);
        $match = $rule['match'] ?? null;

        // Check if user qualifies for this group OR is the named user override.
        $userQualifies = false;
        if (isset($rule['user']) === true && is_string($rule['user']) === true) {
            // User-level override (delegation): { "user": "<uid>", "match": {...} }.
            $userQualifies = $this->matchesUserOverride(rule: 'user:'.$rule['user'], userId: $userId);
        } else if ($group === 'public') {
            // Anonymous users always qualify for public; authenticated users
            // only when public inheritance is enabled (inheritFromPublic).
            $userQualifies = $this->qualifiesForPublic(userId: $userId, inheritFromPublic: $inheritFromPublic);
        } else if ($group === 'authenticated' && $userId !== null) {
            $userQualifies = true;
        } else if ($group !== null && in_array($group, $userGroups, true) === true) {
            $userQualifies = true;
        }

        // If user doesn't qualify for the group, this rule doesn't apply.
        if ($userQualifies === false) {
            return false;
        }

        // If no match conditions, user has unconditional access via this rule.
        if ($match === null || empty($match) === true) {
            return true;
        }

        // Build SQL conditions for the match criteria.
        return $this->buildMatchConditions(qb: $qb, match: $match);
    }//end processConditionalRule()

    /**
     * Build SQL conditions for match criteria
     *
     * @param IQueryBuilder $qb    Query builder
     * @param array         $match Match conditions
     *
     * @return mixed SQL expression or null if invalid
     */
    private function buildMatchConditions(IQueryBuilder $qb, array $match): mixed
    {
        $conditions = [];

        foreach ($match as $property => $value) {
            $condition = $this->buildPropertyCondition(qb: $qb, property: $property, value: $value);
            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        // If no valid conditions, return null.
        if (empty($conditions) === true) {
            $this->logger->debug(
                message: '[MagicRbacHandler] No valid match conditions built',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        // All conditions must match (AND logic).
        if (count($conditions) === 1) {
            return $conditions[0];
        }

        return $qb->expr()->andX(...$conditions);
    }//end buildMatchConditions()

    /**
     * Build an IMPOSSIBLE SQL predicate (`1 = 0`) for the QueryBuilder path.
     *
     * Used to fail closed when a `match` rule's dynamic variable resolves to null
     * (#1953): the predicate is included in the AND but can never be satisfied, so
     * the rule grants no rows — matching the PHP/find path verdict rather than
     * silently dropping the condition. Mirrors the existing impossible predicate in
     * applyRbacFilters().
     *
     * @param IQueryBuilder $qb Query builder.
     *
     * @return mixed An always-false SQL expression.
     */
    private function impossibleCondition(IQueryBuilder $qb): mixed
    {
        return $qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0));
    }//end impossibleCondition()

    /**
     * Resolve dynamic variable values in match conditions.
     *
     * DELEGATES to the shared {@see ConditionMatcher::resolveToken()} — the same
     * resolver the single-object verdict path uses — so the SQL emitter and the
     * PHP verdict cannot disagree about what a token means.
     *
     * This method previously held its OWN copy that recognised only the bare
     * tokens (`$organisation`, `$activeOrganisation`, `$userId`, `$user`, `$now`)
     * and passed every DOTTED form through as a literal string. So a rule using
     * `$user.groups` resolved to the user's groups on `find` and compared against
     * the literal `'$user.groups'` on `list` — granting on one path and denying on
     * the other. That is the divergence class ADR-011 exists to prevent and which
     * already forced two fixes in this file (`$now`'s format,
     * `unwrapResolvedRelation()`).
     *
     * Supports, via the shared resolver: `$organisation`, `$activeOrganisation`,
     * `$organisation.<prop>`, `$userId`, `$user`, `$user.<prop>` (including
     * `$user.groups`, which resolves to an ARRAY — see the array guard in the
     * comparison emitter), and `$now`.
     *
     * @param mixed $value The value to resolve.
     *
     * @return mixed The resolved value, or null if the variable cannot be resolved.
     */
    private function resolveDynamicValue(mixed $value): mixed
    {
        return $this->conditionMatcher->resolveDynamicValue(value: $value);
    }//end resolveDynamicValue()

    /**
     * Build SQL condition for a single property match
     *
     * @param IQueryBuilder $qb       Query builder
     * @param string        $property Property name
     * @param mixed         $value    Value or operator object
     *
     * @return mixed SQL expression or null
     */
    private function buildPropertyCondition(IQueryBuilder $qb, string $property, mixed $value): mixed
    {
        // Convert camelCase property to snake_case column name.
        $columnName = $this->propertyToColumnName(property: $property);

        // Resolve dynamic variables in the value.
        $resolvedValue = $this->resolveDynamicValue(value: $value);

        // Fail closed when a dynamic variable resolves to null (#1953): emit an
        // IMPOSSIBLE predicate (1 = 0) for this property rather than returning null.
        // Returning null would let buildMatchConditions() silently DROP the predicate
        // from the AND, degrading a multi-condition match rule to its surviving
        // static predicates and leaking objects on the LIST path that the PHP/find
        // path (ConditionMatcher) denies. The impossible predicate makes the LIST and
        // FIND verdicts identical (both deny) for unresolved variables.
        if ($value !== $resolvedValue && $resolvedValue === null) {
            return $this->impossibleCondition(qb: $qb);
        }

        // Simple value: equals comparison.
        if (is_string($resolvedValue) === true || is_numeric($resolvedValue) === true || is_bool($resolvedValue) === true) {
            return $qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($resolvedValue));
        }

        // Operator object.
        if (is_array($resolvedValue) === true) {
            return $this->buildOperatorCondition(qb: $qb, columnName: $columnName, operators: $resolvedValue);
        }

        // Null value: is null check.
        if ($resolvedValue === null) {
            return $qb->expr()->isNull("t.{$columnName}");
        }

        return null;
    }//end buildPropertyCondition()

    /**
     * Build SQL condition for operator-based match
     *
     * @param IQueryBuilder $qb         Query builder
     * @param string        $columnName Column name
     * @param array         $operators  Operator conditions
     *
     * @return mixed SQL expression or null
     */
    private function buildOperatorCondition(IQueryBuilder $qb, string $columnName, array $operators): mixed
    {
        foreach ($operators as $operator => $operand) {
            $result = $this->buildSingleOperatorCondition(
                qb: $qb,
                columnName: $columnName,
                operator: $operator,
                operand: $operand
            );

            if ($result !== null) {
                return $result;
            }
        }//end foreach

        return null;
    }//end buildOperatorCondition()

    /**
     * Build a single operator condition for QueryBuilder
     *
     * @param IQueryBuilder $qb         Query builder
     * @param string        $columnName Column name
     * @param string        $operator   Operator (e.g. '$eq', '$gt')
     * @param mixed         $operand    Operand value
     *
     * @return mixed SQL expression or null if operator not handled
     */
    private function buildSingleOperatorCondition(
        IQueryBuilder $qb,
        string $columnName,
        string $operator,
        mixed $operand
    ): mixed {
        // Comparison operators.
        $comparisonResult = $this->buildComparisonOperatorCondition(
            qb: $qb,
            columnName: $columnName,
            operator: $operator,
            operand: $operand
        );
        if ($comparisonResult !== null) {
            return $comparisonResult;
        }

        // Array operators ($in, $nin).
        $arrayResult = $this->buildArrayOperatorCondition(
            qb: $qb,
            columnName: $columnName,
            operator: $operator,
            operand: $operand
        );
        if ($arrayResult !== null) {
            return $arrayResult;
        }

        // Array-membership operator ($contains). The QueryBuilder cannot express
        // JSON-array containment on either platform, so this is emitted as a raw
        // fragment through createFunction() — built by the SAME method the
        // raw-SQL emitter uses, so the two list paths cannot drift apart.
        if ($operator === '$contains') {
            $fragment = $this->buildContainsOperatorConditionSql(
                columnName: "t.{$columnName}",
                operator: $operator,
                operand: $operand
            );
            if ($fragment === null) {
                return null;
            }

            return $qb->createFunction($fragment);
        }

        // Existence operator ($exists).
        if ($operator === '$exists') {
            if ($operand === true) {
                return $qb->expr()->isNotNull("t.{$columnName}");
            }

            return $qb->expr()->isNull("t.{$columnName}");
        }

        $this->logger->warning(
            message: '[MagicRbacHandler] Unknown operator',
            context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
        );

        return null;
    }//end buildSingleOperatorCondition()

    /**
     * Build comparison operator condition ($eq, $ne, $gt, $gte, $lt, $lte) for QueryBuilder
     *
     * @param IQueryBuilder $qb         Query builder
     * @param string        $columnName Column name
     * @param string        $operator   Operator string
     * @param mixed         $operand    Operand value
     *
     * @return mixed SQL expression or null if not a comparison operator
     */
    private function buildComparisonOperatorCondition(
        IQueryBuilder $qb,
        string $columnName,
        string $operator,
        mixed $operand
    ): mixed {
        $comparisonMap = [
            '$eq'  => 'eq',
            '$ne'  => 'neq',
            '$gt'  => 'gt',
            '$gte' => 'gte',
            '$lt'  => 'lt',
            '$lte' => 'lte',
        ];

        if (isset($comparisonMap[$operator]) === false) {
            return null;
        }

        // Resolve dynamic variables in the operand (e.g. "$now" → current datetime).
        $resolvedOperand = $this->resolveDynamicValue(value: $operand);

        // Fail closed on an ARRAY operand. Now that resolution is delegated to the
        // shared matcher, a token like `$user.groups` resolves to an array — and a
        // scalar comparison cannot express that. Emitting it anyway would stringify
        // the array to "Array" and compare against that literal, which is a
        // silent, wrong verdict rather than a refusal.
        if (is_array($resolvedOperand) === true) {
            $this->logger->warning(
                message: '[MagicRbacHandler] Array operand for a scalar comparison operator — emitting an impossible predicate',
                context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
            );
            return $this->impossibleCondition(qb: $qb);
        }

        $method = $comparisonMap[$operator];
        return $qb->expr()->{$method}("t.{$columnName}", $qb->createNamedParameter($resolvedOperand));
    }//end buildComparisonOperatorCondition()

    /**
     * Build array operator condition ($in, $nin) for QueryBuilder
     *
     * @param IQueryBuilder $qb         Query builder
     * @param string        $columnName Column name
     * @param string        $operator   Operator string
     * @param mixed         $operand    Operand value (expected array)
     *
     * @return mixed SQL expression or null if not an array operator or invalid operand
     */
    private function buildArrayOperatorCondition(
        IQueryBuilder $qb,
        string $columnName,
        string $operator,
        mixed $operand
    ): mixed {
        $arrayMap = [
            '$in'  => 'in',
            '$nin' => 'notIn',
        ];

        if (isset($arrayMap[$operator]) === false) {
            return null;
        }

        if (is_array($operand) === true && empty($operand) === false) {
            $method = $arrayMap[$operator];
            return $qb->expr()->{$method}(
                "t.{$columnName}",
                $qb->createNamedParameter($operand, IQueryBuilder::PARAM_STR_ARRAY)
            );
        }

        return null;
    }//end buildArrayOperatorCondition()

    /**
     * Convert camelCase property name to snake_case column name
     *
     * @param string $property Property name in camelCase
     *
     * @return string Column name in snake_case
     */
    private function propertyToColumnName(string $property): string
    {
        // Convert camelCase to snake_case.
        $columnName = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $property);
        return strtolower($columnName);
    }//end propertyToColumnName()

    /**
     * Check if a user has permission to perform an action on a schema
     *
     * This is a non-query version of the RBAC check for use in validation.
     * Note: This method checks if user has ANY possible access to the schema.
     * For conditional rules with match criteria, this returns true if the user
     * qualifies for the group AND (when object data is supplied) ConditionMatcher
     * confirms the object satisfies the match clause.
     *
     * @param Schema      $schema      Schema to check
     * @param string      $action      CRUD action to check
     * @param string|null $objectOwner Optional object owner for ownership check
     * @param array|null  $objectData  Optional object data for conditional checks
     *
     * @return bool True if user has permission
     *
     * @SuppressWarnings(PHPMD.NPathComplexity) Inlined dispatch keeps the ConditionMatcher delegation in one place.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function hasPermission(
        Schema $schema,
        string $action,
        ?string $objectOwner=null,
        ?array $objectData=null
    ): bool {
        $user   = $this->userSession->getUser();
        $userId = $user?->getUID();

        // Get user groups.
        $userGroups = [];
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
        }

        // Admin users have all permissions.
        if (in_array('admin', $userGroups, true) === true) {
            return true;
        }

        // Object owner has all permissions.
        if ($userId !== null && $objectOwner !== null && $objectOwner === $userId) {
            return true;
        }

        // Get schema authorization.
        $authorization = $schema->getAuthorization();

        // If no authorization configured, everyone has access.
        if (empty($authorization) === true) {
            return true;
        }

        // Get authorization rules for this action.
        $rules = $authorization[$action] ?? [];

        // Fail-closed: a non-empty authorization block that does not list this
        // action denies it (consistent with PermissionHandler). The empty-block
        // open-default above and the admin/owner bypasses still apply.
        if (empty($rules) === true) {
            return false;
        }

        // Process each rule.
        //
        // Deduplication note (ADR-011):
        // Conditional match evaluation is delegated to the shared
        // {@see \OCA\OpenRegister\Service\ConditionMatcher}. The SQL emitter
        // (applyRbacFilters → buildMatchConditions → buildPropertyCondition)
        // handles the row-level path; ConditionMatcher handles the PHP-side
        // path for this method and for PermissionHandler/PropertyRbacHandler.
        // Resolve whether authenticated users inherit `public` rights once;
        // anonymous users always qualify for `public`.
        $inheritFromPublic = $this->authenticatedInheritsPublic(schema: $schema);

        foreach ($rules as $rule) {
            // Simple string rule: direct group match or `user:<uid>` override.
            if (is_string($rule) === true) {
                if (($rule === 'public' && $this->qualifiesForPublic(userId: $userId, inheritFromPublic: $inheritFromPublic) === true)
                    || $this->matchesUserOverride(rule: $rule, userId: $userId) === true
                    || in_array($rule, $userGroups, true) === true
                ) {
                    return true;
                }

                continue;
            }

            // Conditional rule: array with 'group' (or 'user' override) and optional 'match'.
            if (is_array($rule) === true && (isset($rule['group']) === true || isset($rule['user']) === true)) {
                if (isset($rule['user']) === true && is_string($rule['user']) === true) {
                    $userQualifies = $this->matchesUserOverride(rule: 'user:'.$rule['user'], userId: $userId);
                } else {
                    $group           = ($rule['group'] ?? null);
                    $qualifiesPublic = ($group === 'public'
                        && $this->qualifiesForPublic(userId: $userId, inheritFromPublic: $inheritFromPublic) === true);
                    $userQualifies   = ($qualifiesPublic === true
                        || ($group !== null && in_array($group, $userGroups, true) === true));
                }

                if ($userQualifies === false) {
                    continue;
                }

                $match = ($rule['match'] ?? null);
                // No match conditions or no object data: group match alone is sufficient.
                if ($match === null || empty($match) === true || $objectData === null) {
                    return true;
                }

                // Delegate conditional evaluation to the shared ConditionMatcher.
                if ($this->conditionMatcher->objectMatchesConditions(
                        object: $objectData,
                        match: $match
                    ) === true
                ) {
                    return true;
                }
            }//end if
        }//end foreach

        return false;
    }//end hasPermission()

    /**
     * Build RBAC conditions as raw SQL for use in UNION queries.
     *
     * This is the raw SQL equivalent of applyRbacFilters() for use in UNION-based
     * queries where QueryBuilder cannot be used directly.
     *
     * @param Schema $schema Schema with authorization configuration.
     * @param string $action CRUD action to check (default: 'read').
     *
     * @return array{bypass: bool, conditions: string[]} Result with:
     *               - 'bypass' => true means no filtering needed (user has full access)
     *               - 'conditions' => SQL conditions to OR together, empty array means deny all
     *
     * @SuppressWarnings(PHPMD.NPathComplexity) Mirrors applyRbacFilters dispatch; carries the system-owner carve-out (openregister#1617).
     */
    public function buildRbacConditionsSql(Schema $schema, string $action='read'): array
    {
        $user   = $this->userSession->getUser();
        $userId = $user?->getUID();

        // Get user groups.
        $userGroups = [];
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
        }

        // Admin users bypass all RBAC checks.
        if (in_array('admin', $userGroups, true) === true) {
            return ['bypass' => true, 'conditions' => []];
        }

        // Get effective authorization (schema-level, or register cascade).
        // Fail-closed: an unresolvable authorization yields the deny-all result
        // (`bypass => false` with no conditions) rather than the open bypass.
        try {
            $authorization = $this->resolveSchemaAuthorization(schema: $schema);
        } catch (AuthorizationUnresolvableException $e) {
            $this->logger->error(
                message: '[MagicRbacHandler] Authorization unresolvable; returning deny-all conditions (fail-closed)',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $schema->getId(),
                    'action'   => $action,
                    'error'    => $e->getMessage(),
                ]
            );
            return ['bypass' => false, 'conditions' => []];
        }

        // If no authorization is configured, schema is open to all.
        if (empty($authorization) === true) {
            return ['bypass' => true, 'conditions' => []];
        }

        // Get authorization rules for this action.
        $rules = $authorization[$action] ?? [];

        // Fail-closed: a non-empty authorization block that does not list this
        // action no longer bypasses filtering (no early `bypass => true`). Flow
        // falls through to the owner condition below; unmatched callers (e.g.
        // anonymous) get the deny-all empty-conditions result — consistent with
        // applyRbacFilters and PermissionHandler. Admins already returned; the
        // empty-block open-default above is unchanged.
        // Build the RBAC filter conditions.
        $conditions = [];

        // Condition: User is the owner of the object (owners always have access).
        if ($userId !== null) {
            $quotedUserId = $this->quoteValue(value: $userId);
            $conditions[] = "_owner = {$quotedUserId}";
        }

        // Condition: User is in a configured system-reader group - grant
        // visibility on rows owned by the system identifier. See
        // openregister#1617. Admins already returned at line 781.
        if ($this->shouldGrantSystemRowVisibility(userGroups: $userGroups) === true) {
            $quotedSystemId = $this->quoteValue(value: $this->getSystemUserId());
            $conditions[]   = "_owner = {$quotedSystemId}";
        }

        // Resolve whether authenticated users inherit `public` rights once.
        $inheritFromPublic = $this->authenticatedInheritsPublic(schema: $schema);

        // Process each authorization rule.
        foreach ($rules as $rule) {
            $ruleResult = $this->processAuthorizationRuleSql(
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId,
                inheritFromPublic: $inheritFromPublic
            );

            if ($ruleResult === true) {
                // User has unconditional access via this rule - no filtering needed.
                return ['bypass' => true, 'conditions' => []];
            }

            if (is_string($ruleResult) === true) {
                // Add the SQL condition for this rule.
                $conditions[] = $ruleResult;
            }
        }

        // Return conditions (empty array means deny all).
        return ['bypass' => false, 'conditions' => $conditions];
    }//end buildRbacConditionsSql()

    /**
     * Process a single authorization rule for raw SQL output.
     *
     * @param mixed       $rule              Authorization rule (string or array).
     * @param array       $userGroups        User's group IDs.
     * @param string|null $userId            Current user ID.
     * @param bool        $inheritFromPublic Whether auth users inherit public rights.
     *
     * @return mixed True if unconditional access, SQL string for conditional, false if no access.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    private function processAuthorizationRuleSql(mixed $rule, array $userGroups, ?string $userId, bool $inheritFromPublic): mixed
    {
        // Simple rule: just a group name string.
        if (is_string($rule) === true) {
            return $this->processSimpleRule(rule: $rule, userGroups: $userGroups, userId: $userId, inheritFromPublic: $inheritFromPublic);
        }

        // Conditional rule: object with 'group' (or a 'user' override) and
        // optional 'match'.
        if (is_array($rule) === true && (isset($rule['group']) === true || isset($rule['user']) === true)) {
            return $this->processConditionalRuleSql(rule: $rule, userGroups: $userGroups, userId: $userId, inheritFromPublic: $inheritFromPublic);
        }

        return false;
    }//end processAuthorizationRuleSql()

    /**
     * Process a conditional authorization rule for raw SQL output.
     *
     * @param array       $rule              Rule with 'group' and optional 'match'.
     * @param array       $userGroups        User's group IDs.
     * @param string|null $userId            Current user ID.
     * @param bool        $inheritFromPublic Whether auth users inherit public rights.
     *
     * @return mixed True if unconditional access, SQL string for conditional, false if no access.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    private function processConditionalRuleSql(array $rule, array $userGroups, ?string $userId, bool $inheritFromPublic): mixed
    {
        $group = ($rule['group'] ?? null);
        $match = $rule['match'] ?? null;

        // Check if user qualifies for this group OR is the named user override.
        $userQualifies = false;
        if (isset($rule['user']) === true && is_string($rule['user']) === true) {
            // User-level override (delegation): { "user": "<uid>", "match": {...} }.
            $userQualifies = $this->matchesUserOverride(rule: 'user:'.$rule['user'], userId: $userId);
        } else if ($group === 'public') {
            // Anonymous users always qualify; authenticated users only when
            // public inheritance is enabled (inheritFromPublic).
            $userQualifies = $this->qualifiesForPublic(userId: $userId, inheritFromPublic: $inheritFromPublic);
        } else if ($group !== null && in_array($group, $userGroups, true) === true) {
            $userQualifies = true;
        }

        // If user doesn't qualify for the group, this rule doesn't apply.
        if ($userQualifies === false) {
            return false;
        }

        // If no match conditions, user has unconditional access via this rule.
        if ($match === null || empty($match) === true) {
            return true;
        }

        // Build SQL conditions for the match criteria.
        return $this->buildMatchConditionsSql(match: $match);
    }//end processConditionalRuleSql()

    /**
     * Build SQL conditions for match criteria.
     *
     * @param array $match Match conditions.
     *
     * @return string|null SQL expression or null if invalid.
     */
    private function buildMatchConditionsSql(array $match): ?string
    {
        $conditions = [];

        foreach ($match as $property => $value) {
            $condition = $this->buildPropertyConditionSql(property: $property, value: $value);
            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        // If no valid conditions, return null.
        if (empty($conditions) === true) {
            return null;
        }

        // All conditions must match (AND logic).
        if (count($conditions) === 1) {
            return $conditions[0];
        }

        return '('.implode(' AND ', $conditions).')';
    }//end buildMatchConditionsSql()

    /**
     * Build SQL condition for a single property match.
     *
     * @param string $property Property name.
     * @param mixed  $value    Value or operator object.
     *
     * @return string|null SQL expression or null.
     */
    private function buildPropertyConditionSql(string $property, mixed $value): ?string
    {
        // Convert camelCase property to snake_case column name.
        $columnName = $this->propertyToColumnName(property: $property);

        // Resolve dynamic variables in the value.
        $resolvedValue = $this->resolveDynamicValue(value: $value);

        // Fail closed when a dynamic variable resolves to null (#1953): emit an
        // IMPOSSIBLE predicate (1 = 0) rather than returning null, which would let
        // buildMatchConditionsSql() drop the predicate from the AND and leak objects
        // on this LIST path. Mirrors the QueryBuilder path and ConditionMatcher.
        if ($value !== $resolvedValue && $resolvedValue === null) {
            return self::IMPOSSIBLE_SQL_CONDITION;
        }

        // Simple value: equals comparison.
        if (is_string($resolvedValue) === true || is_numeric($resolvedValue) === true) {
            $quotedValue = $this->quoteValue(value: $resolvedValue);
            return "{$columnName} = {$quotedValue}";
        }

        // Boolean value.
        if (is_bool($resolvedValue) === true) {
            $boolValue = 'FALSE';
            if ($resolvedValue === true) {
                $boolValue = 'TRUE';
            }

            return "{$columnName} = {$boolValue}";
        }

        // Operator object.
        if (is_array($resolvedValue) === true) {
            return $this->buildOperatorConditionSql(columnName: $columnName, operators: $resolvedValue);
        }

        // Null value: is null check.
        if ($resolvedValue === null) {
            return "{$columnName} IS NULL";
        }

        return null;
    }//end buildPropertyConditionSql()

    /**
     * Build SQL condition for operator-based match.
     *
     * @param string $columnName Column name.
     * @param array  $operators  Operator conditions.
     *
     * @return string|null SQL expression or null.
     */
    private function buildOperatorConditionSql(string $columnName, array $operators): ?string
    {
        foreach ($operators as $operator => $operand) {
            $result = $this->buildSingleOperatorConditionSql(
                columnName: $columnName,
                operator: $operator,
                operand: $operand
            );

            if ($result !== null) {
                return $result;
            }
        }//end foreach

        return null;
    }//end buildOperatorConditionSql()

    /**
     * Build a single operator condition as raw SQL
     *
     * @param string $columnName Column name
     * @param string $operator   Operator (e.g. '$eq', '$gt')
     * @param mixed  $operand    Operand value
     *
     * @return string|null SQL expression or null if operator not handled
     */
    private function buildSingleOperatorConditionSql(string $columnName, string $operator, mixed $operand): ?string
    {
        // Comparison operators.
        $comparisonResult = $this->buildComparisonOperatorConditionSql(
            columnName: $columnName,
            operator: $operator,
            operand: $operand
        );
        if ($comparisonResult !== null) {
            return $comparisonResult;
        }

        // Array operators ($in, $nin).
        $arrayResult = $this->buildArrayOperatorConditionSql(
            columnName: $columnName,
            operator: $operator,
            operand: $operand
        );
        if ($arrayResult !== null) {
            return $arrayResult;
        }

        // Array-membership operator ($contains) — the object's array must contain
        // the resolved value. Mirrors OperatorEvaluator::operatorContains().
        $containsResult = $this->buildContainsOperatorConditionSql(
            columnName: $columnName,
            operator: $operator,
            operand: $operand
        );
        if ($containsResult !== null) {
            return $containsResult;
        }

        // Existence operator ($exists).
        if ($operator === '$exists') {
            if ($operand === true) {
                return "{$columnName} IS NOT NULL";
            }

            return "{$columnName} IS NULL";
        }

        return null;
    }//end buildSingleOperatorConditionSql()

    /**
     * Build comparison operator condition ($eq, $ne, $gt, $gte, $lt, $lte) as raw SQL
     *
     * @param string $columnName Column name
     * @param string $operator   Operator string
     * @param mixed  $operand    Operand value
     *
     * @return string|null SQL expression or null if not a comparison operator
     */
    private function buildComparisonOperatorConditionSql(
        string $columnName,
        string $operator,
        mixed $operand
    ): ?string {
        $comparisonMap = [
            '$eq'  => '=',
            '$ne'  => '!=',
            '$gt'  => '>',
            '$gte' => '>=',
            '$lt'  => '<',
            '$lte' => '<=',
        ];

        if (isset($comparisonMap[$operator]) === false) {
            return null;
        }

        $sqlOperator     = $comparisonMap[$operator];
        $resolvedOperand = $this->resolveDynamicValue(value: $operand);

        // Fail closed when a dynamic variable resolves to null (#1953): emit an
        // IMPOSSIBLE predicate (1 = 0) rather than dropping the condition.
        if ($operand !== $resolvedOperand && $resolvedOperand === null) {
            return self::IMPOSSIBLE_SQL_CONDITION;
        }

        // Fail closed on an ARRAY operand — see the QueryBuilder emitter's note:
        // a resolved `$user.groups` cannot be expressed as a scalar comparison,
        // and quoting it would compare against the literal string "Array".
        if (is_array($resolvedOperand) === true) {
            $this->logger->warning(
                message: '[MagicRbacHandler] Array operand for a scalar comparison operator — emitting an impossible predicate',
                context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
            );
            return self::IMPOSSIBLE_SQL_CONDITION;
        }

        $quotedValue = $this->quoteValue(value: $resolvedOperand);
        return "{$columnName} {$sqlOperator} {$quotedValue}";
    }//end buildComparisonOperatorConditionSql()

    /**
     * Build array operator condition ($in, $nin) as raw SQL
     *
     * @param string $columnName Column name
     * @param string $operator   Operator string
     * @param mixed  $operand    Operand value (expected array)
     *
     * @return string|null SQL expression or null if not an array operator or invalid operand
     */
    private function buildArrayOperatorConditionSql(string $columnName, string $operator, mixed $operand): ?string
    {
        $arrayMap = [
            '$in'  => 'IN',
            '$nin' => 'NOT IN',
        ];

        if (isset($arrayMap[$operator]) === false) {
            return null;
        }

        if (is_array($operand) === true && empty($operand) === false) {
            $sqlKeyword   = $arrayMap[$operator];
            $quotedValues = array_map(fn($val) => $this->quoteValue(value: $val), $operand);
            return "{$columnName} {$sqlKeyword} (".implode(', ', $quotedValues).')';
        }

        return null;
    }//end buildArrayOperatorConditionSql()

    /**
     * Build a `$contains` condition as raw SQL: the column's JSON array must contain the operand.
     *
     * The row-level counterpart of {@see \OCA\OpenRegister\Service\OperatorEvaluator}'s
     * `$contains`. Both MUST agree — a share honoured on `find` but dropped on
     * `list` reads as an empty page, and the reverse leaks an object.
     *
     * Platform-branched, following ReferentialIntegrityService's precedent
     * (`::jsonb @> to_jsonb(?::text)` on PostgreSQL, `JSON_CONTAINS(col,
     * JSON_QUOTE(?))` on MariaDB) — the QueryBuilder cannot express either.
     * `COALESCE(col, '[]')` makes a NULL column contain nothing, matching the PHP
     * side's "a null or non-array property contains nothing".
     *
     * An array-valued operand becomes an OR of containments (ANY-intersection),
     * so `$user.groups` matches when the column lists any one of them. An empty
     * or null operand emits the IMPOSSIBLE predicate rather than being dropped:
     * a dropped condition would leave the rule satisfied by everything else and
     * fail OPEN.
     *
     * @param string $columnName Column name (already qualified/quoted by the caller).
     * @param string $operator   Operator string.
     * @param mixed  $operand    Value(s) that must appear in the column's array.
     *
     * @return string|null SQL expression, or null when this is not a `$contains`.
     *
     * @spec openspec/changes/shared-credentials-and-flows/specs/flow-sharing/spec.md#requirement-the-single-object-and-list-access-decisions-agree
     */
    private function buildContainsOperatorConditionSql(string $columnName, string $operator, mixed $operand): ?string
    {
        if ($operator !== '$contains') {
            return null;
        }

        $resolved = $this->resolveDynamicValue(value: $operand);

        // Fail closed when a dynamic variable resolves to null, exactly as the
        // comparison emitter does — never drop the condition.
        if ($resolved === null) {
            return self::IMPOSSIBLE_SQL_CONDITION;
        }

        $candidates = [$resolved];
        if (is_array($resolved) === true) {
            $candidates = array_values(array_filter($resolved, static fn($val) => $val !== null));
        }

        if (empty($candidates) === true) {
            return self::IMPOSSIBLE_SQL_CONDITION;
        }

        $orParts = [];
        foreach ($candidates as $candidate) {
            $orParts[] = $this->containsPredicateSql(columnName: $columnName, candidate: $candidate);
        }

        if (count($orParts) === 1) {
            return $orParts[0];
        }

        return '('.implode(' OR ', $orParts).')';
    }//end buildContainsOperatorConditionSql()

    /**
     * One platform-appropriate JSON-array containment predicate.
     *
     * @param string $columnName Column reference.
     * @param mixed  $candidate  Single value that must appear in the array.
     *
     * @return string SQL predicate.
     */
    private function containsPredicateSql(string $columnName, mixed $candidate): string
    {
        $quoted = $this->quoteValue(value: (string) $candidate);

        if ($this->isPostgres() === true) {
            return "COALESCE({$columnName}, '[]')::jsonb @> to_jsonb({$quoted}::text)";
        }

        return "JSON_CONTAINS(COALESCE({$columnName}, '[]'), JSON_QUOTE({$quoted}))";
    }//end containsPredicateSql()

    /**
     * Whether the connected database is PostgreSQL.
     *
     * Resolved lazily through the container: this class is constructed on paths
     * that do not need a connection, and adding one to the constructor would
     * change its DI signature for every caller. Mirrors
     * ReferentialIntegrityService's null-safe `get_debug_type()` detection, which
     * also tolerates a mocked connection returning null in unit tests.
     *
     * Defaults to MariaDB syntax when the platform cannot be determined. That is
     * the conservative direction for a security filter: an unparseable predicate
     * errors the query rather than silently matching every row.
     *
     * @return bool True when the platform is PostgreSQL.
     */
    private function isPostgres(): bool
    {
        if ($this->isPostgresCache !== null) {
            return $this->isPostgresCache;
        }

        try {
            $connection = $this->container->get(IDBConnection::class);
            $platform   = $connection->getDatabasePlatform();

            $this->isPostgresCache = stripos(get_debug_type($platform), 'PostgreSQL') !== false;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[MagicRbacHandler] Could not determine the database platform for $contains — defaulting to MariaDB syntax',
                context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
            );
            $this->isPostgresCache = false;
        }

        return $this->isPostgresCache;
    }//end isPostgres()

    /**
     * Quote a value for safe use in raw SQL.
     *
     * @param mixed $value Value to quote.
     *
     * @return string Quoted value safe for SQL.
     */
    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value) === true) {
            if ($value === true) {
                return 'TRUE';
            }

            return 'FALSE';
        }

        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }

        // String value - escape single quotes by doubling them.
        $escaped = str_replace("'", "''", (string) $value);
        return "'{$escaped}'";
    }//end quoteValue()

    /**
     * Get the current user ID
     *
     * @return string|null The current user ID or null if not authenticated
     */
    public function getCurrentUserId(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end getCurrentUserId()

    /**
     * Get the current user's groups
     *
     * @return string[] Array of group IDs
     */
    public function getCurrentUserGroups(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        return $this->groupManager->getUserGroupIds($user);
    }//end getCurrentUserGroups()

    /**
     * Check if current user is admin
     *
     * @return bool True if user is in admin group
     */
    public function isAdmin(): bool
    {
        return in_array('admin', $this->getCurrentUserGroups(), true);
    }//end isAdmin()

    /**
     * Check if schema has conditional RBAC rules that match on non-_organisation fields
     *
     * When RBAC rules include conditional matching on fields other than _organisation,
     * the multitenancy filter should be skipped because RBAC already handles the
     * organization-based access control. This allows users to access records based
     * on field matches (e.g., aanbieder) even if the _organisation differs.
     *
     * @param Schema $schema The schema to check
     * @param string $action The action to check (default: 'read')
     *
     * @return bool True if RBAC has conditional rules that should bypass multitenancy
     */
    public function hasConditionalRulesBypassingMultitenancy(Schema $schema, string $action='read'): bool
    {
        $user = $this->userSession->getUser();

        // Get user groups.
        $userGroups = [];
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
        }

        // Admin users bypass all RBAC checks anyway.
        if (in_array('admin', $userGroups, true) === true) {
            return true;
        }

        // Get schema authorization configuration.
        $authorization = $schema->getAuthorization();
        if (empty($authorization) === true) {
            return false;
        }

        // Get authorization rules for this action.
        $rules = $authorization[$action] ?? [];
        if (empty($rules) === true) {
            return false;
        }

        // Resolve the public-inheritance gate for this schema. A `public` rule
        // only grants an authenticated user a cross-tenant bypass when public
        // inheritance is enabled; anonymous users always qualify. Threading this
        // through keeps the bypass decision consistent with applyRbacFilters /
        // buildRbacConditionsSql / hasPermission, which already gate `public`.
        $userId            = $user?->getUID();
        $inheritFromPublic = $this->authenticatedInheritsPublic(schema: $schema);

        // Check if user qualifies for any rule that should bypass multitenancy.
        // This includes:
        // 1. Simple rules (group name strings) - user in group can see ALL records.
        // 2. Conditional rules with non-_organisation match fields - RBAC handles filtering.
        foreach ($rules as $rule) {
            if ($this->ruleBypassesMultitenancy(
                    rule: $rule,
                    userGroups: $userGroups,
                    userId: $userId,
                    inheritFromPublic: $inheritFromPublic
                ) === true
            ) {
                return true;
            }
        }//end foreach

        return false;
    }//end hasConditionalRulesBypassingMultitenancy()

    /**
     * Check if a single rule should bypass multitenancy for the current user
     *
     * @param mixed       $rule              Authorization rule (string or array)
     * @param array       $userGroups        User's group IDs
     * @param string|null $userId            Current user ID (null = anonymous)
     * @param bool        $inheritFromPublic Whether auth users inherit public rights
     *
     * @return bool True if this rule bypasses multitenancy
     */
    private function ruleBypassesMultitenancy(mixed $rule, array $userGroups, ?string $userId, bool $inheritFromPublic): bool
    {
        // Check simple rules (just group names).
        // If user qualifies for a simple rule, they can see ALL records,
        // so multitenancy should be bypassed.
        if (is_string($rule) === true) {
            return $this->simpleRuleBypassesMultitenancy(
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId,
                inheritFromPublic: $inheritFromPublic
            );
        }

        // Check conditional rules.
        if (is_array($rule) === true && isset($rule['group']) === true && isset($rule['match']) === true) {
            return $this->conditionalRuleBypassesMultitenancy(
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId,
                inheritFromPublic: $inheritFromPublic
            );
        }

        return false;
    }//end ruleBypassesMultitenancy()

    /**
     * Check if a simple (group name) rule bypasses multitenancy
     *
     * @param string      $rule              Group name
     * @param array       $userGroups        User's group IDs
     * @param string|null $userId            Current user ID (null = anonymous)
     * @param bool        $inheritFromPublic Whether auth users inherit public rights
     *
     * @return bool True if this simple rule bypasses multitenancy
     */
    private function simpleRuleBypassesMultitenancy(string $rule, array $userGroups, ?string $userId, bool $inheritFromPublic): bool
    {
        // A `public` rule only grants a cross-tenant bypass to principals who
        // actually qualify for `public`: anonymous users always, authenticated
        // users only when public inheritance is enabled. Otherwise the bypass
        // would expose other-tenant rows to an authenticated user whose access
        // was meant to come from a different (e.g. `authenticated`) rule.
        if ($rule === 'public') {
            return $this->qualifiesForPublic(userId: $userId, inheritFromPublic: $inheritFromPublic);
        }

        return in_array($rule, $userGroups, true);
    }//end simpleRuleBypassesMultitenancy()

    /**
     * Check if a conditional rule bypasses multitenancy
     *
     * A conditional rule bypasses multitenancy when the user qualifies for the
     * group and the match conditions include fields other than _organisation.
     *
     * @param array       $rule              Rule with 'group' and 'match'
     * @param array       $userGroups        User's group IDs
     * @param string|null $userId            Current user ID (null = anonymous)
     * @param bool        $inheritFromPublic Whether auth users inherit public rights
     *
     * @return bool True if this conditional rule bypasses multitenancy
     */
    private function conditionalRuleBypassesMultitenancy(array $rule, array $userGroups, ?string $userId, bool $inheritFromPublic): bool
    {
        $group = $rule['group'];
        $match = $rule['match'];

        // Check if user qualifies for this group. A `public` group only qualifies
        // an authenticated user when public inheritance is enabled (anonymous
        // users always qualify), consistent with the simple-rule path above.
        $userQualifies = (
            ($group === 'public' && $this->qualifiesForPublic(userId: $userId, inheritFromPublic: $inheritFromPublic) === true)
            || in_array($group, $userGroups, true) === true
        );

        // If user qualifies and match contains non-_organisation fields, multitenancy should be bypassed.
        if ($userQualifies === true && is_array($match) === true) {
            return $this->matchHasNonOrganisationFields(match: $match);
        }

        return false;
    }//end conditionalRuleBypassesMultitenancy()

    /**
     * Check if match conditions contain fields other than _organisation
     *
     * @param array $match Match conditions
     *
     * @return bool True if non-_organisation fields exist
     */
    private function matchHasNonOrganisationFields(array $match): bool
    {
        foreach (array_keys($match) as $matchField) {
            if ($matchField !== '_organisation') {
                return true;
            }
        }

        return false;
    }//end matchHasNonOrganisationFields()

    /**
     * Resolve the effective authorization for a schema.
     *
     * Delegates to PermissionHandler::resolveAuthorization() which handles
     * register cascade and role expansion. Falls back to schema-only
     * authorization if PermissionHandler is not available.
     *
     * @param Schema $schema The schema to resolve authorization for.
     *
     * @return array|null The effective authorization array.
     *
     * @throws AuthorizationUnresolvableException When authorization cannot be determined. Callers MUST deny.
     */
    private function resolveSchemaAuthorization(Schema $schema): ?array
    {
        try {
            $permissionHandler = $this->container->get(PermissionHandler::class);
            return $permissionHandler->resolveAuthorization($schema);
        } catch (AuthorizationUnresolvableException $e) {
            // Fail-closed: propagate. Falling back to the schema's own (possibly
            // absent) authorization here would re-open the very hole the resolver
            // now closes — `empty($authorization)` below means "open to all".
            throw $e;
        } catch (\Throwable $e) {
            // Fallback to direct schema authorization if PermissionHandler unavailable.
            $this->logger->debug(
                message: '[MagicRbacHandler] PermissionHandler unavailable, using schema auth directly',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return $schema->getAuthorization();
        }
    }//end resolveSchemaAuthorization()

    /**
     * Decide whether the current user qualifies for the system-row visibility
     * carve-out (openregister#1617).
     *
     * Admins are NOT handled here — they bypass RBAC entirely at the top of
     * the filter methods. This helper exists for non-admin users in groups
     * configured under `openregister.systemReaderGroups`.
     *
     * @param string[] $userGroups The current user's group IDs.
     *
     * @return bool True if the user should see rows owned by the system identifier.
     */
    private function shouldGrantSystemRowVisibility(array $userGroups): bool
    {
        if (empty($userGroups) === true) {
            return false;
        }

        $readerGroups = $this->resolveOrganisationService()?->getSystemReaderGroups() ?? [];
        if (empty($readerGroups) === true) {
            return false;
        }

        return count(array_intersect($userGroups, $readerGroups)) > 0;
    }//end shouldGrantSystemRowVisibility()

    /**
     * Get the configured system identifier used as `_owner` for session-less
     * writes.
     *
     * Falls back to {@see OrganisationService::SYSTEM_USER_ID_DEFAULT} when
     * the OrganisationService is not available in the container (defensive
     * default; matches the helper's own fallback path).
     *
     * @return string The system identifier.
     */
    private function getSystemUserId(): string
    {
        $service = $this->resolveOrganisationService();
        if ($service === null) {
            return \OCA\OpenRegister\Service\OrganisationService::SYSTEM_USER_ID_DEFAULT;
        }

        return $service->getSystemUserId();
    }//end getSystemUserId()

    /**
     * Lazy-load OrganisationService from the container.
     *
     * Matches the lazy-load pattern already in use elsewhere in this class
     * (see e.g. line 415) — avoids constructor-level circular DI dependencies.
     *
     * @return \OCA\OpenRegister\Service\OrganisationService|null The service or
     *         null if the container cannot resolve it (defensive).
     */
    private function resolveOrganisationService(): ?\OCA\OpenRegister\Service\OrganisationService
    {
        try {
            return $this->container->get(\OCA\OpenRegister\Service\OrganisationService::class);
        } catch (\Throwable $e) {
            $this->logger->debug(
                message: '[MagicRbacHandler] OrganisationService unavailable - skipping system-owner carve-out',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return null;
        }
    }//end resolveOrganisationService()
}//end class
