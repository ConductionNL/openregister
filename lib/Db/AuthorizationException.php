<?php

/**
 * OpenRegister Authorization Exception Entity
 *
 * This file contains the entity class for handling authorization exception
 * related operations in the OpenRegister application.
 *
 * Authorization exceptions allow for fine-grained control over the RBAC system
 * by providing inclusions (granting extra permissions) and exclusions (denying
 * permissions that would normally be granted).
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use InvalidArgumentException;

/**
 * Entity class representing an authorization exception in the OpenRegister system
 *
 * Authorization exceptions provide a way to override the standard RBAC system
 * by either granting additional permissions (inclusions) or denying permissions
 * that would normally be granted (exclusions).
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class AuthorizationException extends Entity implements JsonSerializable
{

    /**
     * Exception type constants
     */
    public const TYPE_INCLUSION = 'inclusion';
    public const TYPE_EXCLUSION = 'exclusion';

    /**
     * Subject type constants
     */
    public const SUBJECT_TYPE_USER = 'user';
    public const SUBJECT_TYPE_GROUP = 'group';

    /**
     * Action constants
     */
    public const ACTION_CREATE = 'create';
    public const ACTION_READ = 'read';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    /**
     * Unique identifier for the authorization exception.
     *
     * @var string|null Unique identifier for the authorization exception
     */
    protected ?string $uuid = null;

    /**
     * Type of exception (inclusion or exclusion).
     *
     * @var string|null Type of exception: inclusion or exclusion
     */
    protected ?string $type = null;

    /**
     * Subject type (user or group).
     *
     * @var string|null Subject type: user or group
     */
    protected ?string $subjectType = null;

    /**
     * Subject ID (the actual user ID or group ID).
     *
     * @var string|null The user ID or group ID this exception applies to
     */
    protected ?string $subjectId = null;

    /**
     * Schema UUID this exception applies to (nullable for global exceptions).
     *
     * @var string|null Schema UUID this exception applies to
     */
    protected ?string $schemaUuid = null;

    /**
     * Register UUID this exception applies to (nullable).
     *
     * @var string|null Register UUID this exception applies to
     */
    protected ?string $registerUuid = null;

    /**
     * Organization UUID this exception applies to (nullable).
     *
     * @var string|null Organization UUID this exception applies to
     */
    protected ?string $organizationUuid = null;

    /**
     * CRUD action this exception applies to.
     *
     * @var string|null CRUD action: create, read, update, or delete
     */
    protected ?string $action = null;

    /**
     * Priority for exception resolution (higher = more important).
     *
     * @var int|null Priority for exception resolution
     */
    protected ?int $priority = 0;

    /**
     * Whether the exception is active.
     *
     * @var bool|null Whether the exception is active
     */
    protected ?bool $active = true;

    /**
     * Human readable description of the exception.
     *
     * @var string|null Human readable description of the exception
     */
    protected ?string $description = null;

    /**
     * User who created the exception.
     *
     * @var string|null User who created the exception
     */
    protected ?string $createdBy = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $createdAt = null;

    /**
     * Last update timestamp.
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updatedAt = null;


    /**
     * Initialize the entity and define field types
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'type', type: 'string');
        $this->addType(fieldName: 'subjectType', type: 'string');
        $this->addType(fieldName: 'subjectId', type: 'string');
        $this->addType(fieldName: 'schemaUuid', type: 'string');
        $this->addType(fieldName: 'registerUuid', type: 'string');
        $this->addType(fieldName: 'organizationUuid', type: 'string');
        $this->addType(fieldName: 'action', type: 'string');
        $this->addType(fieldName: 'priority', type: 'integer');
        $this->addType(fieldName: 'active', type: 'boolean');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'createdBy', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'updatedAt', type: 'datetime');

    }//end __construct()


    /**
     * Get valid exception types
     *
     * @return array<string> List of valid exception types
     */
    public static function getValidTypes(): array
    {
        return [
            self::TYPE_INCLUSION,
            self::TYPE_EXCLUSION,
        ];

    }//end getValidTypes()


    /**
     * Get valid subject types
     *
     * @return array<string> List of valid subject types
     */
    public static function getValidSubjectTypes(): array
    {
        return [
            self::SUBJECT_TYPE_USER,
            self::SUBJECT_TYPE_GROUP,
        ];

    }//end getValidSubjectTypes()


    /**
     * Get valid actions
     *
     * @return array<string> List of valid actions
     */
    public static function getValidActions(): array
    {
        return [
            self::ACTION_CREATE,
            self::ACTION_READ,
            self::ACTION_UPDATE,
            self::ACTION_DELETE,
        ];

    }//end getValidActions()


    /**
     * Validate the exception type
     *
     * @param string|null $type The type to validate
     *
     * @return bool True if valid, false otherwise
     */
    public function isValidType(?string $type): bool
    {
        return in_array($type, self::getValidTypes(), true);

    }//end isValidType()


    /**
     * Validate the subject type
     *
     * @param string|null $subjectType The subject type to validate
     *
     * @return bool True if valid, false otherwise
     */
    public function isValidSubjectType(?string $subjectType): bool
    {
        return in_array($subjectType, self::getValidSubjectTypes(), true);

    }//end isValidSubjectType()


    /**
     * Validate the action
     *
     * @param string|null $action The action to validate
     *
     * @return bool True if valid, false otherwise
     */
    public function isValidAction(?string $action): bool
    {
        return in_array($action, self::getValidActions(), true);

    }//end isValidAction()


    /**
     * Set the exception type with validation
     *
     * @param string|null $type The exception type
     *
     * @throws InvalidArgumentException If the type is invalid
     *
     * @return void
     */
    public function setType(?string $type): void
    {
        if ($type !== null && !$this->isValidType($type)) {
            throw new InvalidArgumentException(
                'Invalid exception type: ' . $type . '. Valid types are: ' . implode(', ', self::getValidTypes())
            );
        }
        
        $this->type = $type;

    }//end setType()


    /**
     * Set the subject type with validation
     *
     * @param string|null $subjectType The subject type
     *
     * @throws InvalidArgumentException If the subject type is invalid
     *
     * @return void
     */
    public function setSubjectType(?string $subjectType): void
    {
        if ($subjectType !== null && !$this->isValidSubjectType($subjectType)) {
            throw new InvalidArgumentException(
                'Invalid subject type: ' . $subjectType . '. Valid types are: ' . implode(', ', self::getValidSubjectTypes())
            );
        }
        
        $this->subjectType = $subjectType;

    }//end setSubjectType()


    /**
     * Set the action with validation
     *
     * @param string|null $action The action
     *
     * @throws InvalidArgumentException If the action is invalid
     *
     * @return void
     */
    public function setAction(?string $action): void
    {
        if ($action !== null && !$this->isValidAction($action)) {
            throw new InvalidArgumentException(
                'Invalid action: ' . $action . '. Valid actions are: ' . implode(', ', self::getValidActions())
            );
        }
        
        $this->action = $action;

    }//end setAction()


    /**
     * Check if this exception is an inclusion
     *
     * @return bool True if this is an inclusion exception
     */
    public function isInclusion(): bool
    {
        return $this->type === self::TYPE_INCLUSION;

    }//end isInclusion()


    /**
     * Check if this exception is an exclusion
     *
     * @return bool True if this is an exclusion exception
     */
    public function isExclusion(): bool
    {
        return $this->type === self::TYPE_EXCLUSION;

    }//end isExclusion()


    /**
     * Check if this exception applies to a user
     *
     * @return bool True if this exception applies to a user
     */
    public function isUserException(): bool
    {
        return $this->subjectType === self::SUBJECT_TYPE_USER;

    }//end isUserException()


    /**
     * Check if this exception applies to a group
     *
     * @return bool True if this exception applies to a group
     */
    public function isGroupException(): bool
    {
        return $this->subjectType === self::SUBJECT_TYPE_GROUP;

    }//end isGroupException()


    /**
     * Check if this exception matches the given criteria
     *
     * @param string      $subjectType       The subject type to match
     * @param string      $subjectId         The subject ID to match
     * @param string      $action            The action to match
     * @param string|null $schemaUuid        Optional schema UUID to match
     * @param string|null $registerUuid      Optional register UUID to match
     * @param string|null $organizationUuid  Optional organization UUID to match
     *
     * @return bool True if this exception matches the criteria
     */
    public function matches(
        string $subjectType,
        string $subjectId,
        string $action,
        ?string $schemaUuid = null,
        ?string $registerUuid = null,
        ?string $organizationUuid = null
    ): bool {
        // Must be active
        if (!$this->active) {
            return false;
        }

        // Check basic criteria
        if ($this->subjectType !== $subjectType ||
            $this->subjectId !== $subjectId ||
            $this->action !== $action
        ) {
            return false;
        }

        // Check schema UUID (null means applies to all schemas)
        if ($this->schemaUuid !== null && $this->schemaUuid !== $schemaUuid) {
            return false;
        }

        // Check register UUID (null means applies to all registers)
        if ($this->registerUuid !== null && $this->registerUuid !== $registerUuid) {
            return false;
        }

        // Check organization UUID (null means applies to all organizations)
        if ($this->organizationUuid !== null && $this->organizationUuid !== $organizationUuid) {
            return false;
        }

        return true;

    }//end matches()


    /**
     * Serialize the entity to JSON format
     *
     * @return array<string, mixed> Serialized authorization exception data
     */
    public function jsonSerialize(): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'type'             => $this->type,
            'subjectType'      => $this->subjectType,
            'subjectId'        => $this->subjectId,
            'schemaUuid'       => $this->schemaUuid,
            'registerUuid'     => $this->registerUuid,
            'organizationUuid' => $this->organizationUuid,
            'action'           => $this->action,
            'priority'         => $this->priority,
            'active'           => $this->active,
            'description'      => $this->description,
            'createdBy'        => $this->createdBy,
            'createdAt'        => $this->createdAt?->format('c'),
            'updatedAt'        => $this->updatedAt?->format('c'),
        ];

    }//end jsonSerialize()


    /**
     * String representation of the authorization exception entity
     *
     * @return string String representation of the authorization exception entity
     */
    public function __toString(): string
    {
        if ($this->uuid !== null && $this->uuid !== '') {
            return $this->uuid;
        }

        if ($this->id !== null) {
            return 'AuthorizationException #' . $this->id;
        }

        return 'AuthorizationException Entity';

    }//end __toString()


}//end class

