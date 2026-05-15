<?php

/**
 * OpenRegister WorkflowExecution Entity
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Entity class representing a workflow execution history record.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getHookId()
 * @method void setHookId(?string $hookId)
 * @method string|null getEventType()
 * @method void setEventType(?string $eventType)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method string|null getEngine()
 * @method void setEngine(?string $engine)
 * @method string|null getWorkflowId()
 * @method void setWorkflowId(?string $workflowId)
 * @method string|null getMode()
 * @method void setMode(?string $mode)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method int getDurationMs()
 * @method void setDurationMs(int $durationMs)
 * @method string|null getErrors()
 * @method void setErrors(?string $errors)
 * @method string|null getMetadata()
 * @method void setMetadata(?string $metadata)
 * @method string|null getPayload()
 * @method void setPayload(?string $payload)
 * @method DateTime|null getExecutedAt()
 * @method void setExecutedAt(?DateTime $executedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class WorkflowExecution extends Entity implements JsonSerializable
{

    /**
     * The uuid.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * The hook id.
     *
     * @var string|null
     */
    protected ?string $hookId = null;

    /**
     * The event type.
     *
     * @var string|null
     */
    protected ?string $eventType = null;

    /**
     * The object uuid.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The schema id.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * The register id.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * The engine.
     *
     * @var string|null
     */
    protected ?string $engine = null;

    /**
     * The workflow id.
     *
     * @var string|null
     */
    protected ?string $workflowId = null;

    /**
     * The mode.
     *
     * @var string|null
     */
    protected ?string $mode = 'sync';

    /**
     * The status.
     *
     * @var string|null
     */
    protected ?string $status = null;

    /**
     * The duration ms.
     *
     * @var integer
     */
    protected int $durationMs = 0;

    /**
     * The errors.
     *
     * @var string|null
     */
    protected ?string $errors = null;

    /**
     * The metadata.
     *
     * @var string|null
     */
    protected ?string $metadata = null;

    /**
     * The payload.
     *
     * @var string|null
     */
    protected ?string $payload = null;

    /**
     * The executed at.
     *
     * @var DateTime|null
     */
    protected ?DateTime $executedAt = null;

    /**
     * Constructor for WorkflowExecution entity.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'hookId', type: 'string');
        $this->addType(fieldName: 'eventType', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'engine', type: 'string');
        $this->addType(fieldName: 'workflowId', type: 'string');
        $this->addType(fieldName: 'mode', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'durationMs', type: 'integer');
        $this->addType(fieldName: 'errors', type: 'string');
        $this->addType(fieldName: 'metadata', type: 'string');
        $this->addType(fieldName: 'payload', type: 'string');
        $this->addType(fieldName: 'executedAt', type: 'datetime');
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
            'hookId',
            'eventType',
            'objectUuid',
            'schemaId',
            'registerId',
            'engine',
            'workflowId',
            'mode',
            'status',
            'durationMs',
            'errors',
            'metadata',
            'payload',
            'executedAt',
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
     * Serialize to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'uuid'       => $this->uuid,
            'hookId'     => $this->hookId,
            'eventType'  => $this->eventType,
            'objectUuid' => $this->objectUuid,
            'schemaId'   => $this->schemaId,
            'registerId' => $this->registerId,
            'engine'     => $this->engine,
            'workflowId' => $this->workflowId,
            'mode'       => $this->mode,
            'status'     => $this->status,
            'durationMs' => $this->durationMs,
            'errors'     => $this->errors !== null ? json_decode($this->errors, true) : null,
            'metadata'   => $this->metadata !== null ? json_decode($this->metadata, true) : null,
            'payload'    => $this->payload !== null ? json_decode($this->payload, true) : null,
            'executedAt' => $this->executedAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
