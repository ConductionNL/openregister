<?php

/**
 * OpenRegister ActionLog Entity
 *
 * Entity for logging action execution attempts and results.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * ActionLog entity for tracking action execution history
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getActionId()
 * @method void setActionId(int $actionId)
 * @method string getActionUuid()
 * @method void setActionUuid(string $actionUuid)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method string getEngine()
 * @method void setEngine(string $engine)
 * @method string getWorkflowId()
 * @method void setWorkflowId(string $workflowId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int|null getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method string|null getRequestPayload()
 * @method void setRequestPayload(?string $requestPayload)
 * @method string|null getResponsePayload()
 * @method void setResponsePayload(?string $responsePayload)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method int getAttempt()
 * @method void setAttempt(int $attempt)
 * @method DateTime getCreated()
 * @method void setCreated(DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ActionLog extends Entity implements JsonSerializable
{

    /** @var int */
    protected int $actionId = 0;

    /** @var string */
    protected string $actionUuid = '';

    /** @var string */
    protected string $eventType = '';

    /** @var string|null */
    protected ?string $objectUuid = null;

    /** @var int|null */
    protected ?int $schemaId = null;

    /** @var int|null */
    protected ?int $registerId = null;

    /** @var string */
    protected string $engine = '';

    /** @var string */
    protected string $workflowId = '';

    /** @var string */
    protected string $status = '';

    /** @var int|null */
    protected ?int $durationMs = null;

    /** @var string|null */
    protected ?string $requestPayload = null;

    /** @var string|null */
    protected ?string $responsePayload = null;

    /** @var string|null */
    protected ?string $errorMessage = null;

    /** @var int */
    protected int $attempt = 1;

    /** @var DateTime */
    protected DateTime $created;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'actionId', type: 'integer');
        $this->addType(fieldName: 'actionUuid', type: 'string');
        $this->addType(fieldName: 'eventType', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'engine', type: 'string');
        $this->addType(fieldName: 'workflowId', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'durationMs', type: 'integer');
        $this->addType(fieldName: 'requestPayload', type: 'string');
        $this->addType(fieldName: 'responsePayload', type: 'string');
        $this->addType(fieldName: 'errorMessage', type: 'string');
        $this->addType(fieldName: 'attempt', type: 'integer');
        $this->addType(fieldName: 'created', type: 'datetime');

        $this->created = new DateTime();
    }//end __construct()

    /**
     * Get request payload as array
     *
     * @return array
     */
    public function getRequestPayloadArray(): array
    {
        if ($this->requestPayload === null) {
            return [];
        }

        return json_decode($this->requestPayload, true) ?? [];
    }//end getRequestPayloadArray()

    /**
     * Get response payload as array
     *
     * @return array
     */
    public function getResponsePayloadArray(): array
    {
        if ($this->responsePayload === null) {
            return [];
        }

        return json_decode($this->responsePayload, true) ?? [];
    }//end getResponsePayloadArray()

    /**
     * JSON serialize the entity
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->id,
            'actionId'        => $this->actionId,
            'actionUuid'      => $this->actionUuid,
            'eventType'       => $this->eventType,
            'objectUuid'      => $this->objectUuid,
            'schemaId'        => $this->schemaId,
            'registerId'      => $this->registerId,
            'engine'          => $this->engine,
            'workflowId'      => $this->workflowId,
            'status'          => $this->status,
            'durationMs'      => $this->durationMs,
            'requestPayload'  => $this->getRequestPayloadArray(),
            'responsePayload' => $this->getResponsePayloadArray(),
            'errorMessage'    => $this->errorMessage,
            'attempt'         => $this->attempt,
            'created'         => $this->created->format('c'),
        ];
    }//end jsonSerialize()
}//end class
