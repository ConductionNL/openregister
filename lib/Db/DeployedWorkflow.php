<?php

/**
 * OpenRegister DeployedWorkflow Entity
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
 * Entity class representing a deployed workflow tracked through the import system.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getEngine()
 * @method void setEngine(?string $engine)
 * @method string|null getEngineWorkflowId()
 * @method void setEngineWorkflowId(?string $engineWorkflowId)
 * @method string|null getSourceHash()
 * @method void setSourceHash(?string $sourceHash)
 * @method string|null getAttachedSchema()
 * @method void setAttachedSchema(?string $attachedSchema)
 * @method string|null getAttachedEvent()
 * @method void setAttachedEvent(?string $attachedEvent)
 * @method string|null getImportSource()
 * @method void setImportSource(?string $importSource)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class DeployedWorkflow extends Entity implements JsonSerializable
{

    /**
     * UUID for external reference.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Human-readable name from import.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * Engine identifier (e.g., "n8n", "windmill").
     *
     * @var string|null
     */
    protected ?string $engine = null;

    /**
     * ID returned by the engine after deploy.
     *
     * @var string|null
     */
    protected ?string $engineWorkflowId = null;

    /**
     * SHA-256 hash of the workflow definition.
     *
     * @var string|null
     */
    protected ?string $sourceHash = null;

    /**
     * Schema slug that this workflow is attached to (null if no attachTo).
     *
     * @var string|null
     */
    protected ?string $attachedSchema = null;

    /**
     * Hook event type (e.g., "creating", "created").
     *
     * @var string|null
     */
    protected ?string $attachedEvent = null;

    /**
     * Filename or identifier of the import source.
     *
     * @var string|null
     */
    protected ?string $importSource = null;

    /**
     * Version number, incremented on each update (starts at 1).
     *
     * @var integer
     */
    protected int $version = 1;

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
     * Constructor for DeployedWorkflow entity.
     *
     * Registers column types for the database mapper.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'engine', type: 'string');
        $this->addType(fieldName: 'engineWorkflowId', type: 'string');
        $this->addType(fieldName: 'sourceHash', type: 'string');
        $this->addType(fieldName: 'attachedSchema', type: 'string');
        $this->addType(fieldName: 'attachedEvent', type: 'string');
        $this->addType(fieldName: 'importSource', type: 'string');
        $this->addType(fieldName: 'version', type: 'integer');
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
        foreach ($object as $key => $value) {
            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Silently ignore invalid properties.
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
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'name'             => $this->name,
            'engine'           => $this->engine,
            'engineWorkflowId' => $this->engineWorkflowId,
            'sourceHash'       => $this->sourceHash,
            'attachedSchema'   => $this->attachedSchema,
            'attachedEvent'    => $this->attachedEvent,
            'importSource'     => $this->importSource,
            'version'          => $this->version,
            'created'          => $this->created?->format('c'),
            'updated'          => $this->updated?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
