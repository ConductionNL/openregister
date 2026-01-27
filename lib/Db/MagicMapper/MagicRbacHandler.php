<?php

/**
 * MagicMapper RBAC Handler
 *
 * This handler provides role-based access control (RBAC) filtering for dynamic
 * schema-based tables. It implements the same RBAC logic as ObjectEntityMapper
 * but optimized for schema-specific table structures.
 *
 * KEY RESPONSIBILITIES:
 * - Apply RBAC permission filters to dynamic table queries
 * - Handle user authentication and authorization checks
 * - Support publication-based public access controls
 * - Integrate with Nextcloud's user and group management
 * - Provide consistent security across all dynamic tables
 *
 * RBAC FEATURES:
 * - Schema-level authorization configuration
 * - User ownership validation
 * - Group-based access control
 * - Publication-based public access
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
use OCA\OpenRegister\Db\Schema;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     * @param IUserSession       $userSession  User session for current user context
     * @param IGroupManager      $groupManager Group manager for user group operations
     * @param IUserManager       $userManager  User manager for user operations
     * @param IAppConfig         $appConfig    App configuration for RBAC settings
     * @param ContainerInterface $container    Container for service injection
     * @param LoggerInterface    $logger       Logger for debugging
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IAppConfig $appConfig,
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
    public function applyRbacFilters(IQueryBuilder $qb, Schema $schema, string $action='read'): void
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
            return;
        }

        // Get schema authorization configuration.
        $authorization = $schema->getAuthorization();

        // If no authorization is configured, schema is open to all.
        if (empty($authorization) === true) {
            $this->logger->debug('MagicRbacHandler: No authorization configured, schema is open');
            return;
        }

        // Get authorization rules for this action.
        $rules = $authorization[$action] ?? [];

        // If action is not configured in authorization, it's open to all.
        if (empty($rules) === true) {
            $this->logger->debug('MagicRbacHandler: Action not configured, open access', ['action' => $action]);
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
                    'MagicRbacHandler: No access conditions met, denying all',
                    [
                        'userId' => $userId,
                        'action' => $action,
                    ]
                    );
            // Add impossible condition to return no results.
            $qb->andWhere($qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0)));
            return;
        }

        // Apply OR of all conditions (access granted if ANY condition matches).
        $this->logger->debug(
            'MagicRbacHandler: Applying RBAC conditions',
            [
                'conditionCount' => count($conditions),
                'conditions' => array_map(fn($c) => (string) $c, $conditions),
                'schemaId' => $schema->getId(),
                'userId' => $userId,
            ]
        );
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
        $this->logger->warning('MagicRbacHandler: Invalid authorization rule format', ['rule' => $rule]);
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
            $this->logger->debug('MagicRbacHandler: No valid match conditions built');
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
            $activeOrg = $organisationService->getActiveOrganisation();

            if ($activeOrg !== null) {
                $this->cachedActiveOrg = $activeOrg->getUuid();
                return $this->cachedActiveOrg;
            }
        } catch (\Exception $e) {
            $this->logger->debug('MagicRbacHandler: Could not get active organisation', ['error' => $e->getMessage()]);
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
        $columnName = $this->propertyToColumnName($property);

        // Resolve dynamic variables in the value.
        $resolvedValue = $this->resolveDynamicValue($value);

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
            switch ($operator) {
                case '$eq':
                    return $qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($operand));

                case '$ne':
                    return $qb->expr()->neq("t.{$columnName}", $qb->createNamedParameter($operand));

                case '$in':
                    if (is_array($operand) === true && empty($operand) === false) {
                        return $qb->expr()->in(
                            "t.{$columnName}",
                            $qb->createNamedParameter($operand, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                        );
                    }
                    break;

                case '$nin':
                    if (is_array($operand) === true && empty($operand) === false) {
                        return $qb->expr()->notIn(
                            "t.{$columnName}",
                            $qb->createNamedParameter($operand, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                        );
                    }
                    break;

                case '$exists':
                    if ($operand === true) {
                        return $qb->expr()->isNotNull("t.{$columnName}");
                    }
                    return $qb->expr()->isNull("t.{$columnName}");

                case '$gt':
                    return $qb->expr()->gt("t.{$columnName}", $qb->createNamedParameter($operand));

                case '$gte':
                    return $qb->expr()->gte("t.{$columnName}", $qb->createNamedParameter($operand));

                case '$lt':
                    return $qb->expr()->lt("t.{$columnName}", $qb->createNamedParameter($operand));

                case '$lte':
                    return $qb->expr()->lte("t.{$columnName}", $qb->createNamedParameter($operand));

                default:
                    $this->logger->warning('MagicRbacHandler: Unknown operator', ['operator' => $operator]);
            }//end switch
        }//end foreach

        return null;
    }//end buildOperatorCondition()

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
     * qualifies for the group (actual object matching happens at query time).
     *
     * @param Schema      $schema      Schema to check
     * @param string      $action      CRUD action to check
     * @param string|null $objectOwner Optional object owner for ownership check
     * @param array|null  $objectData  Optional object data for conditional checks
     *
     * @return bool True if user has permission
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
        foreach ($rules as $rule) {
            if ($this->checkPermissionRule(
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId,
                objectData: $objectData
            ) === true
            ) {
                return true;
            }
        }

        return false;
    }//end hasPermission()

    /**
     * Check if a user matches a single permission rule
     *
     * @param mixed       $rule       Authorization rule
     * @param array       $userGroups User's group IDs
     * @param string|null $userId     Current user ID
     * @param array|null  $objectData Optional object data for conditional checks
     *
     * @return bool True if rule grants access
     */
    private function checkPermissionRule(
        mixed $rule,
        array $userGroups,
        ?string $userId,
        ?array $objectData
    ): bool {
        // Simple rule: just a group name string.
        if (is_string($rule) === true) {
            // 'public' grants access to anyone, including unauthenticated users.
            if ($rule === 'public') {
                return true;
            }

            return in_array($rule, $userGroups, true);
        }

        // Conditional rule: object with 'group' and optional 'match'.
        if (is_array($rule) === true && isset($rule['group']) === true) {
            $group = $rule['group'];
            $match = $rule['match'] ?? null;

            // Check if user qualifies for the group.
            // 'public' grants access to anyone, including unauthenticated users.
            $userQualifies = false;
            if ($group === 'public') {
                $userQualifies = true;
            } else if (in_array($group, $userGroups, true) === true) {
                $userQualifies = true;
            }

            if ($userQualifies === false) {
                return false;
            }

            // If no match conditions or no object data, grant access.
            if ($match === null || empty($match) === true || $objectData === null) {
                return true;
            }

            // Check if object matches conditions.
            return $this->objectMatchesConditions(objectData: $objectData, match: $match);
        }//end if

        return false;
    }//end checkPermissionRule()

    /**
     * Check if object data matches the given conditions
     *
     * @param array $objectData Object data to check
     * @param array $match      Match conditions
     *
     * @return bool True if object matches all conditions
     */
    private function objectMatchesConditions(array $objectData, array $match): bool
    {
        foreach ($match as $property => $value) {
            $objectValue = $objectData[$property] ?? null;

            // Resolve dynamic variables in the match value.
            $resolvedValue = $this->resolveDynamicValue($value);

            // If dynamic variable resolved to null, condition cannot be met.
            if ($value !== $resolvedValue && $resolvedValue === null) {
                return false;
            }

            // Simple value: equals comparison.
            if (is_string($resolvedValue) === true || is_numeric($resolvedValue) === true || is_bool($resolvedValue) === true) {
                if ($objectValue !== $resolvedValue) {
                    return false;
                }

                continue;
            }

            // Operator object.
            if (is_array($resolvedValue) === true) {
                if ($this->valueMatchesOperator(value: $objectValue, operators: $resolvedValue) === false) {
                    return false;
                }

                continue;
            }

            // Null value: check if object value is null.
            if ($resolvedValue === null && $objectValue !== null) {
                return false;
            }
        }//end foreach

        return true;
    }//end objectMatchesConditions()

    /**
     * Check if a value matches operator conditions
     *
     * @param mixed $value     Object value
     * @param array $operators Operator conditions
     *
     * @return bool True if value matches
     */
    private function valueMatchesOperator(mixed $value, array $operators): bool
    {
        foreach ($operators as $operator => $operand) {
            switch ($operator) {
                case '$eq':
                    if ($value !== $operand) {
                        return false;
                    }
                    break;

                case '$ne':
                    if ($value === $operand) {
                        return false;
                    }
                    break;

                case '$in':
                    if (is_array($operand) === false || in_array($value, $operand, true) === false) {
                        return false;
                    }
                    break;

                case '$nin':
                    if (is_array($operand) === true && in_array($value, $operand, true) === true) {
                        return false;
                    }
                    break;

                case '$exists':
                    if ($operand === true && $value === null) {
                        return false;
                    }

                    if ($operand === false && $value !== null) {
                        return false;
                    }
                    break;

                case '$gt':
                    if ($value <= $operand) {
                        return false;
                    }
                    break;

                case '$gte':
                    if ($value < $operand) {
                        return false;
                    }
                    break;

                case '$lt':
                    if ($value >= $operand) {
                        return false;
                    }
                    break;

                case '$lte':
                    if ($value > $operand) {
                        return false;
                    }
                    break;
            }//end switch
        }//end foreach

        return true;
    }//end valueMatchesOperator()

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
    public function buildRbacConditionsSql(Schema $schema, string $action = 'read'): array
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

        // Get schema authorization configuration.
        $authorization = $schema->getAuthorization();

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
            $quotedUserId = $this->quoteValue($userId);
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
        return $this->buildMatchConditionsSql($match);
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

        return '(' . implode(' AND ', $conditions) . ')';
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
        $columnName = $this->propertyToColumnName($property);

        // Resolve dynamic variables in the value.
        $resolvedValue = $this->resolveDynamicValue($value);

        // If dynamic variable resolved to null, this condition cannot be met.
        if ($value !== $resolvedValue && $resolvedValue === null) {
            return null;
        }

        // Simple value: equals comparison.
        if (is_string($resolvedValue) === true || is_numeric($resolvedValue) === true) {
            $quotedValue = $this->quoteValue($resolvedValue);
            return "{$columnName} = {$quotedValue}";
        }

        // Boolean value.
        if (is_bool($resolvedValue) === true) {
            $boolValue = $resolvedValue ? 'TRUE' : 'FALSE';
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
            switch ($operator) {
                case '$eq':
                    $quotedValue = $this->quoteValue($operand);
                    return "{$columnName} = {$quotedValue}";

                case '$ne':
                    $quotedValue = $this->quoteValue($operand);
                    return "{$columnName} != {$quotedValue}";

                case '$in':
                    if (is_array($operand) === true && empty($operand) === false) {
                        $quotedValues = array_map(fn($v) => $this->quoteValue($v), $operand);
                        return "{$columnName} IN (" . implode(', ', $quotedValues) . ')';
                    }
                    break;

                case '$nin':
                    if (is_array($operand) === true && empty($operand) === false) {
                        $quotedValues = array_map(fn($v) => $this->quoteValue($v), $operand);
                        return "{$columnName} NOT IN (" . implode(', ', $quotedValues) . ')';
                    }
                    break;

                case '$exists':
                    if ($operand === true) {
                        return "{$columnName} IS NOT NULL";
                    }
                    return "{$columnName} IS NULL";

                case '$gt':
                    $quotedValue = $this->quoteValue($operand);
                    return "{$columnName} > {$quotedValue}";

                case '$gte':
                    $quotedValue = $this->quoteValue($operand);
                    return "{$columnName} >= {$quotedValue}";

                case '$lt':
                    $quotedValue = $this->quoteValue($operand);
                    return "{$columnName} < {$quotedValue}";

                case '$lte':
                    $quotedValue = $this->quoteValue($operand);
                    return "{$columnName} <= {$quotedValue}";
            }
        }

        return null;
    }//end buildOperatorConditionSql()

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
            return $value ? 'TRUE' : 'FALSE';
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
}//end class
