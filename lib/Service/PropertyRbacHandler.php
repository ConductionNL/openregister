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
use Psr\Log\LoggerInterface;

/**
 * Property-level RBAC handler for fine-grained access control
 *
 * This class provides property-level RBAC filtering, ensuring that specific
 * fields within objects can have different access rules than the object itself.
 * Condition matching and operator evaluation are delegated to ConditionMatcher.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class PropertyRbacHandler
{
    /**
     * Constructor for PropertyRbacHandler
     *
     * @param IUserSession     $userSession      User session for current user context
     * @param IGroupManager    $groupManager     Group manager for user group operations
     * @param ConditionMatcher $conditionMatcher Condition matcher for match expressions
     * @param LoggerInterface  $logger           Logger for debugging
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ConditionMatcher $conditionMatcher,
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
        bool $isCreate=false
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
        // Returns associative array: propertyName => authorizationConfig.
        $propertiesWithAuth = $schema->getPropertiesWithAuthorization();

        // Filter out properties user cannot read.
        foreach (array_keys($propertiesWithAuth) as $propertyName) {
            // Only filter if the property exists in the object.
            if (array_key_exists($propertyName, $object) === false) {
                continue;
            }

            // Check if user can read this property.
            if ($this->canReadProperty(schema: $schema, property: $propertyName, object: $object) === false) {
                unset($object[$propertyName]);
                $this->logger->debug(
                    message: '[PropertyRbacHandler] Filtered unreadable property',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $propertyName]
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
        bool $isCreate=false
    ): array {
        // If user is admin, no restrictions.
        if ($this->isAdmin() === true) {
            return [];
        }

        // If schema has no property-level authorization, no restrictions.
        if ($schema->hasPropertyAuthorization() === false) {
            return [];
        }

        $unauthorizedProps = [];

        // Get properties with authorization.
        // Returns associative array: propertyName => authorizationConfig.
        $propertiesWithAuth = $schema->getPropertiesWithAuthorization();

        // Check each incoming property that has authorization rules.
        foreach (array_keys($propertiesWithAuth) as $propertyName) {
            // Only check properties that are being submitted.
            if (array_key_exists($propertyName, $incomingData) === false) {
                continue;
            }

            // Skip authorization check if the value hasn't actually changed.
            // This allows PATCH operations to include unchanged protected fields
            // without triggering authorization errors.
            if ($isCreate === false
                && array_key_exists($propertyName, $object) === true
                && $incomingData[$propertyName] === $object[$propertyName]
            ) {
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
                $unauthorizedProps[] = $propertyName;
            }
        }//end foreach

        return $unauthorizedProps;
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
        bool $isCreate=false
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
            return $this->userQualifiesForGroup(group: $rule, userGroups: $userGroups, userId: $userId);
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
        $this->logger->warning(
            message: '[PropertyRbacHandler] Invalid rule format',
            context: ['file' => __FILE__, 'line' => __LINE__, 'rule' => $rule]
        );
        return false;
    }//end checkRule()

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

        // If user doesn't qualify for the group, this rule doesn't apply.
        if ($this->userQualifiesForGroup(group: $group, userGroups: $userGroups, userId: $userId) === false) {
            return false;
        }

        // If no match conditions, user has access via this rule.
        if ($match === null || empty($match) === true) {
            return true;
        }

        // For creates, skip organisation matching since there's no existing object.
        // Other match conditions still apply.
        if ($isCreate === true) {
            $match = $this->conditionMatcher->filterOrganisationMatchForCreate(match: $match);
            if (empty($match) === true) {
                return true;
            }
        }

        // Check if object matches all conditions.
        return $this->conditionMatcher->objectMatchesConditions(object: $object, match: $match);
    }//end checkConditionalRule()

    /**
     * Check if a user qualifies for a specific group
     *
     * @param string      $group      Group name from the rule
     * @param array       $userGroups User's group IDs
     * @param string|null $userId     Current user ID
     *
     * @return bool True if user qualifies for the group
     */
    private function userQualifiesForGroup(string $group, array $userGroups, ?string $userId): bool
    {
        if ($group === 'public') {
            return true;
        }

        if ($group === 'authenticated' && $userId !== null) {
            return true;
        }

        return in_array($group, $userGroups, true);
    }//end userQualifiesForGroup()

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
