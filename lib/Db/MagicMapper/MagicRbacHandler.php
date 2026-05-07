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

use DateTime;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
     * Cached active organisation UUID
     *
     * @var string|null
     */
    private ?string $cachedActiveOrg = null;

    /**
     * Constructor for MagicRbacHandler
     *
     * @param IUserSession       $userSession      User session for current user context
     * @param IGroupManager      $groupManager     Group manager for user group operations
     * @param IUserManager       $userManager      User manager for user operations
     * @param IAppConfig         $appConfig        App configuration for RBAC settings
     * @param ConditionMatcher   $conditionMatcher Shared PHP-side match evaluator (ADR-011; SQL emitter stays in this class).
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

        // Get effective authorization (schema-level, or register cascade).
        $authorization = $this->resolveSchemaAuthorization(schema: $schema);

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

        // If action is not configured in authorization, it's open to all.
        if (empty($rules) === true) {
            $this->logger->debug(
                message: '[MagicRbacHandler] Action not configured, open access',
                context: ['file' => __FILE__, 'line' => __LINE__, 'action' => $action]
            );
            return;
        }

        // Build the RBAC filter conditions.
        $conditions = [];

        // Condition: User is the owner of the object (owners always have access).
        if ($userId !== null) {
            $conditions[] = $qb->expr()->eq('t._owner', $qb->createNamedParameter($userId));
        }

        // Process each authorization rule.
        foreach ($rules as $rule) {
            $ruleCondition = $this->processAuthorizationRule(
                qb: $qb,
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId
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
     * Process a single authorization rule
     *
     * @param IQueryBuilder $qb         Query builder
     * @param mixed         $rule       Authorization rule (string or array)
     * @param array         $userGroups User's group IDs
     * @param string|null   $userId     Current user ID
     *
     * @return mixed True if unconditional access, SQL expression for conditional, null/false if no access
     */
    private function processAuthorizationRule(
        IQueryBuilder $qb,
        mixed $rule,
        array $userGroups,
        ?string $userId
    ): mixed {
        // Simple rule: just a group name string.
        if (is_string($rule) === true) {
            return $this->processSimpleRule(rule: $rule, userGroups: $userGroups, userId: $userId);
        }

        // Conditional rule: object with 'group' and optional 'match'.
        if (is_array($rule) === true && isset($rule['group']) === true) {
            return $this->processConditionalRule(qb: $qb, rule: $rule, userGroups: $userGroups, userId: $userId);
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
     * @param string      $rule       Group name
     * @param array       $userGroups User's group IDs
     * @param string|null $userId     Current user ID
     *
     * @return bool True if user has access, false otherwise
     */
    private function processSimpleRule(string $rule, array $userGroups, ?string $userId): bool
    {
        // 'public' grants access to anyone, including unauthenticated users.
        if ($rule === 'public') {
            return true;
        }

        // 'authenticated' grants access to any logged-in user.
        if ($rule === 'authenticated') {
            return $userId !== null;
        }

        // Check if user is in the specified group.
        if (in_array($rule, $userGroups, true) === true) {
            return true;
        }

        return false;
    }//end processSimpleRule()

    /**
     * Process a conditional authorization rule
     *
     * @param IQueryBuilder $qb         Query builder
     * @param array         $rule       Rule with 'group' and optional 'match'
     * @param array         $userGroups User's group IDs
     * @param string|null   $userId     Current user ID
     *
     * @return mixed True if unconditional access, SQL expression for conditional, false if no access
     */
    private function processConditionalRule(
        IQueryBuilder $qb,
        array $rule,
        array $userGroups,
        ?string $userId
    ): mixed {
        $group = $rule['group'];
        $match = $rule['match'] ?? null;

        // Check if user qualifies for this group.
        $userQualifies = false;
        if ($group === 'public') {
            // Public group means anyone can access, including unauthenticated users.
            $userQualifies = true;
        } else if ($group === 'authenticated' && $userId !== null) {
            $userQualifies = true;
        } else if (in_array($group, $userGroups, true) === true) {
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
     * Resolve dynamic variable values in match conditions
     *
     * Supports special variables:
     * - $organisation: Current user's active organisation UUID
     * - $userId: Current user's ID
     * - $now: Current datetime in SQL format (Y-m-d H:i:s)
     *
     * @param mixed $value The value to resolve
     *
     * @return mixed The resolved value, or null if variable cannot be resolved
     */
    private function resolveDynamicValue(mixed $value): mixed
    {
        if (is_string($value) === false) {
            return $value;
        }

        // Check for $organisation variable.
        if ($value === '$organisation' || $value === '$activeOrganisation') {
            return $this->getActiveOrganisationUuid();
        }

        // Check for $userId variable.
        if ($value === '$userId' || $value === '$user') {
            return $this->userSession->getUser()?->getUID();
        }

        // Check for $now variable.
        if ($value === '$now') {
            return (new DateTime())->format('Y-m-d H:i:s');
        }

        return $value;
    }//end resolveDynamicValue()

    /**
     * Get the current user's active organisation UUID
     *
     * @return string|null The active organisation UUID or null
     */
    private function getActiveOrganisationUuid(): ?string
    {
        // Return cached value if available.
        if ($this->cachedActiveOrg !== null) {
            return $this->cachedActiveOrg;
        }

        try {
            $organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
            $activeOrg           = $organisationService->getActiveOrganisation();

            if ($activeOrg !== null) {
                $this->cachedActiveOrg = $activeOrg->getUuid();
                return $this->cachedActiveOrg;
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                message: '[MagicRbacHandler] Could not get active organisation',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }

        return null;
    }//end getActiveOrganisationUuid()

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

        // If dynamic variable resolved to null, this condition cannot be met.
        if ($value !== $resolvedValue && $resolvedValue === null) {
            return null;
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
     * @SuppressWarnings(PHPMD.NPathComplexity) Rule-type dispatch is inlined to keep the delegation to ConditionMatcher single-site.
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

        // If action not configured, everyone has access.
        if (empty($rules) === true) {
            return true;
        }

        // Process each rule.
        //
        // Deduplication note (ADR-011):
        // Conditional match evaluation is delegated to the shared
        // {@see \OCA\OpenRegister\Service\ConditionMatcher}. The SQL emitter
        // (applyRbacFilters → buildMatchConditions → buildPropertyCondition)
        // handles the row-level path; ConditionMatcher handles the PHP-side
        // path for this method and for PermissionHandler/PropertyRbacHandler.
        foreach ($rules as $rule) {
            // Simple string rule: direct group match.
            if (is_string($rule) === true) {
                if ($rule === 'public' || in_array($rule, $userGroups, true) === true) {
                    return true;
                }

                continue;
            }

            // Conditional rule: array with 'group' and optional 'match'.
            if (is_array($rule) === true && isset($rule['group']) === true) {
                $group         = $rule['group'];
                $userQualifies = ($group === 'public' || in_array($group, $userGroups, true) === true);
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
        $authorization = $this->resolveSchemaAuthorization(schema: $schema);

        // If no authorization is configured, schema is open to all.
        if (empty($authorization) === true) {
            return ['bypass' => true, 'conditions' => []];
        }

        // Get authorization rules for this action.
        $rules = $authorization[$action] ?? [];

        // If action is not configured in authorization, it's open to all.
        if (empty($rules) === true) {
            return ['bypass' => true, 'conditions' => []];
        }

        // Build the RBAC filter conditions.
        $conditions = [];

        // Condition: User is the owner of the object (owners always have access).
        if ($userId !== null) {
            $quotedUserId = $this->quoteValue(value: $userId);
            $conditions[] = "_owner = {$quotedUserId}";
        }

        // Process each authorization rule.
        foreach ($rules as $rule) {
            $ruleResult = $this->processAuthorizationRuleSql(
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId
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
     * @param mixed       $rule       Authorization rule (string or array).
     * @param array       $userGroups User's group IDs.
     * @param string|null $userId     Current user ID.
     *
     * @return mixed True if unconditional access, SQL string for conditional, false if no access.
     */
    private function processAuthorizationRuleSql(mixed $rule, array $userGroups, ?string $userId): mixed
    {
        // Simple rule: just a group name string.
        if (is_string($rule) === true) {
            return $this->processSimpleRule(rule: $rule, userGroups: $userGroups, userId: $userId);
        }

        // Conditional rule: object with 'group' and optional 'match'.
        if (is_array($rule) === true && isset($rule['group']) === true) {
            return $this->processConditionalRuleSql(rule: $rule, userGroups: $userGroups, userId: $userId);
        }

        return false;
    }//end processAuthorizationRuleSql()

    /**
     * Process a conditional authorization rule for raw SQL output.
     *
     * @param array       $rule       Rule with 'group' and optional 'match'.
     * @param array       $userGroups User's group IDs.
     * @param string|null $userId     Current user ID.
     *
     * @return mixed True if unconditional access, SQL string for conditional, false if no access.
     *
     * @psalm-suppress UnusedParam $userId reserved for user-specific match conditions in future RBAC rules
     */
    private function processConditionalRuleSql(array $rule, array $userGroups, ?string $userId): mixed
    {
        $group = $rule['group'];
        $match = $rule['match'] ?? null;

        // Check if user qualifies for this group.
        $userQualifies = false;
        if ($group === 'public') {
            $userQualifies = true;
        } else if (in_array($group, $userGroups, true) === true) {
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

        // If dynamic variable resolved to null, this condition cannot be met.
        if ($value !== $resolvedValue && $resolvedValue === null) {
            return null;
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

        // If dynamic variable resolved to null, this condition cannot be met.
        if ($operand !== $resolvedOperand && $resolvedOperand === null) {
            return null;
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

        // Check if user qualifies for any rule that should bypass multitenancy.
        // This includes:
        // 1. Simple rules (group name strings) - user in group can see ALL records.
        // 2. Conditional rules with non-_organisation match fields - RBAC handles filtering.
        foreach ($rules as $rule) {
            if ($this->ruleBypassesMultitenancy(rule: $rule, userGroups: $userGroups) === true) {
                return true;
            }
        }//end foreach

        return false;
    }//end hasConditionalRulesBypassingMultitenancy()

    /**
     * Check if a single rule should bypass multitenancy for the current user
     *
     * @param mixed $rule       Authorization rule (string or array)
     * @param array $userGroups User's group IDs
     *
     * @return bool True if this rule bypasses multitenancy
     */
    private function ruleBypassesMultitenancy(mixed $rule, array $userGroups): bool
    {
        // Check simple rules (just group names).
        // If user qualifies for a simple rule, they can see ALL records,
        // so multitenancy should be bypassed.
        if (is_string($rule) === true) {
            return $this->simpleRuleBypassesMultitenancy(rule: $rule, userGroups: $userGroups);
        }

        // Check conditional rules.
        if (is_array($rule) === true && isset($rule['group']) === true && isset($rule['match']) === true) {
            return $this->conditionalRuleBypassesMultitenancy(rule: $rule, userGroups: $userGroups);
        }

        return false;
    }//end ruleBypassesMultitenancy()

    /**
     * Check if a simple (group name) rule bypasses multitenancy
     *
     * @param string $rule       Group name
     * @param array  $userGroups User's group IDs
     *
     * @return bool True if this simple rule bypasses multitenancy
     */
    private function simpleRuleBypassesMultitenancy(string $rule, array $userGroups): bool
    {
        if ($rule === 'public') {
            return true;
        }

        return in_array($rule, $userGroups, true);
    }//end simpleRuleBypassesMultitenancy()

    /**
     * Check if a conditional rule bypasses multitenancy
     *
     * A conditional rule bypasses multitenancy when the user qualifies for the
     * group and the match conditions include fields other than _organisation.
     *
     * @param array $rule       Rule with 'group' and 'match'
     * @param array $userGroups User's group IDs
     *
     * @return bool True if this conditional rule bypasses multitenancy
     */
    private function conditionalRuleBypassesMultitenancy(array $rule, array $userGroups): bool
    {
        $group = $rule['group'];
        $match = $rule['match'];

        // Check if user qualifies for this group.
        $userQualifies = ($group === 'public' || in_array($group, $userGroups, true) === true);

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
     */
    private function resolveSchemaAuthorization(Schema $schema): ?array
    {
        try {
            $permissionHandler = $this->container->get(PermissionHandler::class);
            return $permissionHandler->resolveAuthorization($schema);
        } catch (\Throwable $e) {
            // Fallback to direct schema authorization if PermissionHandler unavailable.
            $this->logger->debug(
                message: '[MagicRbacHandler] PermissionHandler unavailable, using schema auth directly',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return $schema->getAuthorization();
        }
    }//end resolveSchemaAuthorization()
}//end class
