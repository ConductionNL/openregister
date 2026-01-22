<?php

/**
 * Property-Level RBAC Handler
 *
 * This handler provides role-based access control (RBAC) filtering at the
 * property level. While schema-level RBAC controls access to entire objects,
 * property-level RBAC controls access to specific fields within objects.
 *
 * KEY RESPONSIBILITIES:
 * - Check if user can read specific properties on an object
 * - Check if user can update specific properties on an object
 * - Filter readable properties from outgoing data (RenderHandler)
 * - Validate writable properties on incoming data (ValidationHandler)
 *
 * AUTHORIZATION STRUCTURE:
 * Properties can define authorization rules in their schema definition:
 * {
 *   "properties": {
 *     "interneAantekening": {
 *       "type": "string",
 *       "authorization": {
 *         "read": [{ "group": "public", "match": { "_organisation": "$organisation" } }],
 *         "update": [{ "group": "public", "match": { "_organisation": "$organisation" } }]
 *       }
 *     }
 *   }
 * }
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for property-level RBAC
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCP\IUserSession;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Property-level RBAC handler for fine-grained access control
 *
 * This class provides property-level RBAC filtering, ensuring that specific
 * fields within objects can have different access rules than the object itself.
 */
class PropertyRbacHandler
{
    /**
     * Cached active organisation UUID
     *
     * @var string|null
     */
    private ?string $cachedActiveOrg = null;

    /**
     * Constructor for PropertyRbacHandler
     *
     * @param IUserSession       $userSession  User session for current user context
     * @param IGroupManager      $groupManager Group manager for user group operations
     * @param ContainerInterface $container    Container for service injection
     * @param LoggerInterface    $logger       Logger for debugging
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Check if the current user can read a specific property
     *
     * @param Schema $schema   Schema containing property definition
     * @param string $property Property name to check
     * @param array  $object   Object data (for conditional matching)
     *
     * @return bool True if user can read the property
     */
    public function canReadProperty(Schema $schema, string $property, array $object): bool
    {
        return $this->checkPropertyAccess(
            schema: $schema,
            property: $property,
            object: $object,
            action: 'read'
        );
    }//end canReadProperty()

    /**
     * Check if the current user can update a specific property
     *
     * @param Schema $schema   Schema containing property definition
     * @param string $property Property name to check
     * @param array  $object   Object data (for conditional matching)
     * @param bool   $isCreate Whether this is a create operation
     *
     * @return bool True if user can update the property
     */
    public function canUpdateProperty(
        Schema $schema,
        string $property,
        array $object,
        bool $isCreate = false
    ): bool {
        return $this->checkPropertyAccess(
            schema: $schema,
            property: $property,
            object: $object,
            action: 'update',
            isCreate: $isCreate
        );
    }//end canUpdateProperty()

    /**
     * Filter an object to only include readable properties
     *
     * @param Schema $schema Schema containing property definitions
     * @param array  $object Object data to filter
     *
     * @return array Filtered object with only readable properties
     */
    public function filterReadableProperties(Schema $schema, array $object): array
    {
        // If user is admin, return object as-is.
        if ($this->isAdmin() === true) {
            return $object;
        }

        // If schema has no property-level authorization, return as-is.
        if ($schema->hasPropertyAuthorization() === false) {
            return $object;
        }

        // Get properties with authorization.
        // Returns associative array: propertyName => authorizationConfig
        $propertiesWithAuth = $schema->getPropertiesWithAuthorization();

        // Filter out properties user cannot read.
        foreach ($propertiesWithAuth as $propertyName => $authConfig) {
            // Only filter if the property exists in the object.
            if (array_key_exists($propertyName, $object) === false) {
                continue;
            }

            // Check if user can read this property.
            if ($this->canReadProperty(schema: $schema, property: $propertyName, object: $object) === false) {
                unset($object[$propertyName]);
                $this->logger->debug(
                    'PropertyRbacHandler: Filtered unreadable property',
                    ['property' => $propertyName]
                );
            }
        }

        return $object;
    }//end filterReadableProperties()

    /**
     * Validate writable properties on incoming data
     *
     * Returns an array of property names that the user is not allowed to update.
     * The caller should handle these violations (typically throw a validation error).
     *
     * @param Schema $schema       Schema containing property definitions
     * @param array  $object       Existing object data (empty array for creates)
     * @param array  $incomingData Incoming data from client
     * @param bool   $isCreate     Whether this is a create operation
     *
     * @return array Array of property names user cannot update
     */
    public function getUnauthorizedProperties(
        Schema $schema,
        array $object,
        array $incomingData,
        bool $isCreate = false
    ): array {
        // If user is admin, no restrictions.
        if ($this->isAdmin() === true) {
            return [];
        }

        // If schema has no property-level authorization, no restrictions.
        if ($schema->hasPropertyAuthorization() === false) {
            return [];
        }

        $unauthorizedProperties = [];

        // Get properties with authorization.
        // Returns associative array: propertyName => authorizationConfig
        $propertiesWithAuth = $schema->getPropertiesWithAuthorization();

        // Check each incoming property that has authorization rules.
        foreach ($propertiesWithAuth as $propertyName => $authConfig) {
            // Only check properties that are being submitted.
            if (array_key_exists($propertyName, $incomingData) === false) {
                continue;
            }

            // Check if user can update this property.
            if ($this->canUpdateProperty(
                schema: $schema,
                property: $propertyName,
                object: $object,
                isCreate: $isCreate
            ) === false
            ) {
                $unauthorizedProperties[] = $propertyName;
            }
        }

        return $unauthorizedProperties;
    }//end getUnauthorizedProperties()

    /**
     * Check if user has access to a property for a specific action
     *
     * @param Schema $schema   Schema containing property definition
     * @param string $property Property name
     * @param array  $object   Object data for conditional matching
     * @param string $action   Action to check ('read' or 'update')
     * @param bool   $isCreate Whether this is a create operation
     *
     * @return bool True if user has access
     */
    private function checkPropertyAccess(
        Schema $schema,
        string $property,
        array $object,
        string $action,
        bool $isCreate = false
    ): bool {
        // Get property authorization.
        $authorization = $schema->getPropertyAuthorization($property);

        // If no authorization is defined for this property, it follows object-level rules.
        if ($authorization === null || empty($authorization) === true) {
            return true;
        }

        // Get rules for this action.
        $rules = $authorization[$action] ?? [];

        // If action is not configured, property is accessible.
        if (empty($rules) === true) {
            return true;
        }

        // Get current user info.
        $user   = $this->userSession->getUser();
        $userId = $user?->getUID();

        // Get user groups.
        $userGroups = [];
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
        }

        // Admin users bypass all checks.
        if (in_array('admin', $userGroups, true) === true) {
            return true;
        }

        // Process each rule.
        foreach ($rules as $rule) {
            if ($this->checkRule(
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId,
                object: $object,
                isCreate: $isCreate
            ) === true
            ) {
                return true;
            }
        }

        return false;
    }//end checkPropertyAccess()

    /**
     * Check if a single rule grants access
     *
     * @param mixed       $rule       Authorization rule
     * @param array       $userGroups User's group IDs
     * @param string|null $userId     Current user ID
     * @param array       $object     Object data for conditional matching
     * @param bool        $isCreate   Whether this is a create operation
     *
     * @return bool True if rule grants access
     */
    private function checkRule(
        mixed $rule,
        array $userGroups,
        ?string $userId,
        array $object,
        bool $isCreate
    ): bool {
        // Simple rule: just a group name string.
        if (is_string($rule) === true) {
            return $this->checkSimpleRule(rule: $rule, userGroups: $userGroups, userId: $userId);
        }

        // Conditional rule: object with 'group' and optional 'match'.
        if (is_array($rule) === true && isset($rule['group']) === true) {
            return $this->checkConditionalRule(
                rule: $rule,
                userGroups: $userGroups,
                userId: $userId,
                object: $object,
                isCreate: $isCreate
            );
        }

        // Invalid rule format.
        $this->logger->warning('PropertyRbacHandler: Invalid rule format', ['rule' => $rule]);
        return false;
    }//end checkRule()

    /**
     * Check a simple (group-only) rule
     *
     * @param string      $rule       Group name
     * @param array       $userGroups User's group IDs
     * @param string|null $userId     Current user ID
     *
     * @return bool True if rule grants access
     */
    private function checkSimpleRule(string $rule, array $userGroups, ?string $userId): bool
    {
        // 'public' grants access to anyone, including unauthenticated users.
        if ($rule === 'public') {
            return true;
        }

        // Check if user is in the specified group.
        return in_array($rule, $userGroups, true);
    }//end checkSimpleRule()

    /**
     * Check a conditional rule with match criteria
     *
     * @param array       $rule       Rule with 'group' and optional 'match'
     * @param array       $userGroups User's group IDs
     * @param string|null $userId     Current user ID
     * @param array       $object     Object data for conditional matching
     * @param bool        $isCreate   Whether this is a create operation
     *
     * @return bool True if rule grants access
     */
    private function checkConditionalRule(
        array $rule,
        array $userGroups,
        ?string $userId,
        array $object,
        bool $isCreate
    ): bool {
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

        // If no match conditions, user has access via this rule.
        if ($match === null || empty($match) === true) {
            return true;
        }

        // For creates, skip organisation matching since there's no existing object.
        // Other match conditions still apply.
        if ($isCreate === true) {
            $match = $this->filterOrganisationMatchForCreate($match);
            if (empty($match) === true) {
                return true;
            }
        }

        // Check if object matches all conditions.
        return $this->objectMatchesConditions(object: $object, match: $match);
    }//end checkConditionalRule()

    /**
     * Filter out organisation matching for create operations
     *
     * On create, there's no existing object to match organisation against,
     * so we skip organisation-based conditions.
     *
     * @param array $match Match conditions
     *
     * @return array Filtered match conditions
     */
    private function filterOrganisationMatchForCreate(array $match): array
    {
        $organisationKeys = ['_organisation', 'organisation'];
        $organisationValues = ['$organisation', '$activeOrganisation'];

        $filtered = [];
        foreach ($match as $property => $value) {
            // Skip if this is an organisation match condition.
            if (in_array($property, $organisationKeys, true) === true) {
                if (is_string($value) === true && in_array($value, $organisationValues, true) === true) {
                    continue;
                }
            }

            $filtered[$property] = $value;
        }

        return $filtered;
    }//end filterOrganisationMatchForCreate()

    /**
     * Check if object data matches all conditions
     *
     * @param array $object Object data to check
     * @param array $match  Match conditions
     *
     * @return bool True if object matches all conditions
     */
    private function objectMatchesConditions(array $object, array $match): bool
    {
        foreach ($match as $property => $value) {
            // Get object value, checking both direct property and @self.
            $objectValue = $this->getObjectValue(object: $object, property: $property);

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
     * Get a value from the object, checking both direct property and @self
     *
     * @param array  $object   Object data
     * @param string $property Property name
     *
     * @return mixed Property value or null
     */
    private function getObjectValue(array $object, string $property): mixed
    {
        // Check direct property first.
        if (isset($object[$property]) === true) {
            return $object[$property];
        }

        // For underscore-prefixed properties, also check @self.
        if (str_starts_with($property, '_') === true) {
            $selfProperty = substr($property, 1);
            if (isset($object['@self'][$selfProperty]) === true) {
                return $object['@self'][$selfProperty];
            }
        }

        return null;
    }//end getObjectValue()

    /**
     * Resolve dynamic variable values
     *
     * Supports special variables:
     * - $organisation / $activeOrganisation: Current user's active organisation UUID
     * - $userId / $user: Current user's ID
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
            $this->logger->debug(
                'PropertyRbacHandler: Could not get active organisation',
                ['error' => $e->getMessage()]
            );
        }

        return null;
    }//end getActiveOrganisationUuid()

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

                default:
                    $this->logger->warning(
                        'PropertyRbacHandler: Unknown operator',
                        ['operator' => $operator]
                    );
            }//end switch
        }//end foreach

        return true;
    }//end valueMatchesOperator()

    /**
     * Check if current user is admin
     *
     * @return bool True if user is in admin group
     */
    public function isAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $userGroups = $this->groupManager->getUserGroupIds($user);
        return in_array('admin', $userGroups, true);
    }//end isAdmin()
}//end class
