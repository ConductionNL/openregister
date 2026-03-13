<?php

/**
 * OpenRegister WorkflowEngine Entity
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity class representing a workflow engine configuration.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getEngineType()
 * @method void setEngineType(?string $engineType)
 * @method string|null getBaseUrl()
 * @method void setBaseUrl(?string $baseUrl)
 * @method string|null getAuthType()
 * @method void setAuthType(?string $authType)
 * @method string|null getAuthConfig()
 * @method void setAuthConfig(?string $authConfig)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method int getDefaultTimeout()
 * @method void setDefaultTimeout(int $defaultTimeout)
 * @method bool|null getHealthStatus()
 * @method void setHealthStatus(?bool $healthStatus)
 * @method DateTime|null getLastHealthCheck()
 * @method void setLastHealthCheck(?DateTime $lastHealthCheck)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class WorkflowEngine extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the workflow engine.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Name of the workflow engine.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * Type of workflow engine (e.g., n8n, windmill).
     *
     * @var string|null
     */
    protected ?string $engineType = null;

    /**
     * Base URL of the workflow engine API.
     *
     * @var string|null
     */
    protected ?string $baseUrl = null;

    /**
     * Authentication type for the engine connection.
     *
     * @var string|null
     */
    protected ?string $authType = 'none';

    /**
     * Authentication configuration (JSON).
     *
     * @var string|null
     */
    protected ?string $authConfig = null;

    /**
     * Whether the workflow engine is enabled.
     *
     * @var boolean
     */
    protected bool $enabled = true;

    /**
     * Default timeout in seconds for workflow execution.
     *
     * @var integer
     */
    protected int $defaultTimeout = 30;

    /**
     * Current health status of the engine.
     *
     * @var boolean|null
     */
    protected ?bool $healthStatus = null;

    /**
     * Timestamp of the last health check.
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastHealthCheck = null;

    /**
     * Timestamp when the entity was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Timestamp when the entity was last updated.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Constructor for WorkflowEngine entity.
     *
     * Registers column types for the database mapper.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'engineType', type: 'string');
        $this->addType(fieldName: 'baseUrl', type: 'string');
        $this->addType(fieldName: 'authType', type: 'string');
        $this->addType(fieldName: 'authConfig', type: 'string');
        $this->addType(fieldName: 'enabled', type: 'boolean');
        $this->addType(fieldName: 'defaultTimeout', type: 'integer');
        $this->addType(fieldName: 'healthStatus', type: 'boolean');
        $this->addType(fieldName: 'lastHealthCheck', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
    }//end __construct()

    /**
     * Hydrate entity from array.
     *
     * @param array<string, mixed> $object Data to hydrate from
     *
     * @return self
     */
    public function hydrate(array $object): self
    {
        $fields = [
            'uuid',
            'name',
            'engineType',
            'baseUrl',
            'authType',
            'authConfig',
            'enabled',
            'defaultTimeout',
            'healthStatus',
            'lastHealthCheck',
            'created',
            'updated',
        ];

        foreach ($object as $key => $value) {
            if (in_array($key, $fields, true) === true) {
                $setter = 'set'.ucfirst($key);
                $this->$setter($value);
            }
        }

        return $this;
    }//end hydrate()

    /**
     * Serialize to JSON. Credentials are always excluded.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'name'            => $this->name,
            'engineType'      => $this->engineType,
            'baseUrl'         => $this->baseUrl,
            'authType'        => $this->authType,
            'enabled'         => $this->enabled,
            'defaultTimeout'  => $this->defaultTimeout,
            'healthStatus'    => $this->healthStatus,
            'lastHealthCheck' => $this->lastHealthCheck?->format('c'),
            'created'         => $this->created?->format('c'),
            'updated'         => $this->updated?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
