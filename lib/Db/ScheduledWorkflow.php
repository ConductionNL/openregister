<?php

/**
 * OpenRegister ScheduledWorkflow Entity
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
 * Entity class representing a scheduled workflow configuration.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getEngine()
 * @method void setEngine(?string $engine)
 * @method string|null getWorkflowId()
 * @method void setWorkflowId(?string $workflowId)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getIntervalSec()
 * @method void setIntervalSec(int $intervalSec)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string|null getPayload()
 * @method void setPayload(?string $payload)
 * @method DateTime|null getLastRun()
 * @method void setLastRun(?DateTime $lastRun)
 * @method string|null getLastStatus()
 * @method void setLastStatus(?string $lastStatus)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class ScheduledWorkflow extends Entity implements JsonSerializable
{

    /** @var string|null */
    protected ?string $uuid = null;

    /** @var string|null */
    protected ?string $name = null;

    /** @var string|null */
    protected ?string $engine = null;

    /** @var string|null */
    protected ?string $workflowId = null;

    /** @var int|null */
    protected ?int $registerId = null;

    /** @var int|null */
    protected ?int $schemaId = null;

    /** @var int */
    protected int $intervalSec = 86400;

    /** @var bool */
    protected bool $enabled = true;

    /** @var string|null */
    protected ?string $payload = null;

    /** @var DateTime|null */
    protected ?DateTime $lastRun = null;

    /** @var string|null */
    protected ?string $lastStatus = null;

    /** @var DateTime|null */
    protected ?DateTime $created = null;

    /** @var DateTime|null */
    protected ?DateTime $updated = null;

    /**
     * Constructor for ScheduledWorkflow entity.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'engine', type: 'string');
        $this->addType(fieldName: 'workflowId', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'intervalSec', type: 'integer');
        $this->addType(fieldName: 'enabled', type: 'boolean');
        $this->addType(fieldName: 'payload', type: 'string');
        $this->addType(fieldName: 'lastRun', type: 'datetime');
        $this->addType(fieldName: 'lastStatus', type: 'string');
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
            'uuid', 'name', 'engine', 'workflowId', 'registerId',
            'schemaId', 'intervalSec', 'enabled', 'payload',
            'lastRun', 'lastStatus', 'created', 'updated',
        ];

        foreach ($object as $key => $value) {
            if (in_array($key, $fields, true) === true) {
                $setter = 'set' . ucfirst($key);
                $this->$setter($value);
            }
        }

        return $this;
    }//end hydrate()

    /**
     * Serialize to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'name'        => $this->name,
            'engine'      => $this->engine,
            'workflowId'  => $this->workflowId,
            'registerId'  => $this->registerId,
            'schemaId'    => $this->schemaId,
            'intervalSec' => $this->intervalSec,
            'enabled'     => $this->enabled,
            'payload'     => $this->payload !== null ? json_decode($this->payload, true) : null,
            'lastRun'     => $this->lastRun?->format('c'),
            'lastStatus'  => $this->lastStatus,
            'created'     => $this->created?->format('c'),
            'updated'     => $this->updated?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
