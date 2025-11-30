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
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git_id>
 * @link    https://www.OpenRegister.app
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getSubjectType()
 * @method void setSubjectType(?string $subjectType)
 * @method string|null getSubjectId()
 * @method void setSubjectId(?string $subjectId)
 * @method string|null getSchemaUuid()
 * @method void setSchemaUuid(?string $schemaUuid)
 * @method string|null getRegisterUuid()
 * @method void setRegisterUuid(?string $registerUuid)
 * @method string|null getOrganizationUuid()
 * @method void setOrganizationUuid(?string $organizationUuid)
 * @method string|null getAction()
 * @method void setAction(?string $action)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 * @method bool|null getActive()
 * @method void setActive(?bool $active)
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
    public const SUBJECT_TYPE_USER  = 'user';
    public const SUBJECT_TYPE_GROUP = 'group';

    /**
     * Action constants
     */
    public const ACTION_CREATE = 'create';
    public const ACTION_READ   = 'read';
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
     * @var integer|null Priority for exception resolution
     */
    protected ?int $priority = 0;

    /**
     * Whether the exception is active.
     *
     * @var boolean|null Whether the exception is active
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
     * @return string[] List of valid exception types
     *
     * @psalm-return list{'inclusion', 'exclusion'}
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
     * @return string[] List of valid subject types
     *
     * @psalm-return list{'user', 'group'}
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
     * @return string[] List of valid actions
     *
     * @psalm-return list{'create', 'read', 'update', 'delete'}
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
        if ($type !== null && $this->isValidType($type) === false) {
            throw new InvalidArgumentException(
                'Invalid exception type: '.$type.'. Valid types are: '.implode(', ', self::getValidTypes())
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
        if ($subjectType !== null && $this->isValidSubjectType($subjectType) === false) {
            throw new InvalidArgumentException(
                'Invalid subject type: '.$subjectType.'. Valid types are: '.implode(', ', self::getValidSubjectTypes())
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
        if ($action !== null && $this->isValidAction($action) === false) {
            throw new InvalidArgumentException(
                'Invalid action: '.$action.'. Valid actions are: '.implode(', ', self::getValidActions())
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
     * Serialize the entity to JSON format
     *
     * @return (bool|int|null|string)[] Serialized authorization exception data
     *
     * @psalm-return array{
     *     id: int,
     *     uuid: null|string,
     *     type: null|string,
     *     subjectType: null|string,
     *     subjectId: null|string,
     *     schemaUuid: null|string,
     *     registerUuid: null|string,
     *     organizationUuid: null|string,
     *     action: null|string,
     *     priority: int|null,
     *     active: bool|null,
     *     description: null|string,
     *     createdBy: null|string,
     *     createdAt: null|string,
     *     updatedAt: null|string
     * }
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
            return 'AuthorizationException #'.$this->id;
        }

        return 'AuthorizationException Entity';

    }//end __toString()


}//end class
