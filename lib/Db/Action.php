<?php

/**
 * OpenRegister Action Entity
 *
 * First-class entity for workflow automation actions.
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

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Action entity for workflow automation
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string getEngine()
 * @method void setEngine(string $engine)
 * @method string getWorkflowId()
 * @method void setWorkflowId(string $workflowId)
 * @method string getMode()
 * @method void setMode(string $mode)
 * @method int getExecutionOrder()
 * @method void setExecutionOrder(int $executionOrder)
 * @method int getTimeout()
 * @method void setTimeout(int $timeout)
 * @method string getOnFailure()
 * @method void setOnFailure(string $onFailure)
 * @method string getOnTimeout()
 * @method void setOnTimeout(string $onTimeout)
 * @method string getOnEngineDown()
 * @method void setOnEngineDown(string $onEngineDown)
 * @method string|null getFilterCondition()
 * @method void setFilterCondition(?string $filterCondition)
 * @method string|null getConfiguration()
 * @method void setConfiguration(?string $configuration)
 * @method int|null getMapping()
 * @method void setMapping(?int $mapping)
 * @method string|null getSchemas()
 * @method void setSchemas(?string $schemas)
 * @method string|null getRegisters()
 * @method void setRegisters(?string $registers)
 * @method string|null getSchedule()
 * @method void setSchedule(?string $schedule)
 * @method int getMaxRetries()
 * @method void setMaxRetries(int $maxRetries)
 * @method string getRetryPolicy()
 * @method void setRetryPolicy(string $retryPolicy)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getApplication()
 * @method void setApplication(?string $application)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method DateTime|null getLastExecutedAt()
 * @method void setLastExecutedAt(?DateTime $lastExecutedAt)
 * @method int getExecutionCount()
 * @method void setExecutionCount(int $executionCount)
 * @method int getSuccessCount()
 * @method void setSuccessCount(int $successCount)
 * @method int getFailureCount()
 * @method void setFailureCount(int $failureCount)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getDeleted()
 * @method void setDeleted(?DateTime $deleted)
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Action extends Entity implements JsonSerializable
{

    /**
     * The uuid.
     *
     * @var string
     */
    protected string $uuid = '';

    /**
     * The name.
     *
     * @var string
     */
    protected string $name = '';

    /**
     * The slug.
     *
     * @var string|null
     */
    protected ?string $slug = null;

    /**
     * The description.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * The version.
     *
     * @var string|null
     */
    protected ?string $version = '1.0.0';

    /**
     * The status.
     *
     * @var string
     */
    protected string $status = 'draft';

    /**
     * The event type.
     *
     * @var string
     */
    protected string $eventType = '';

    /**
     * The engine.
     *
     * @var string
     */
    protected string $engine = '';

    /**
     * The workflow id.
     *
     * @var string
     */
    protected string $workflowId = '';

    /**
     * The mode.
     *
     * @var string
     */
    protected string $mode = 'sync';

    /**
     * The execution order.
     *
     * @var integer
     */
    protected int $executionOrder = 0;

    /**
     * The timeout.
     *
     * @var integer
     */
    protected int $timeout = 30;

    /**
     * The on failure.
     *
     * @var string
     */
    protected string $onFailure = 'reject';

    /**
     * The on timeout.
     *
     * @var string
     */
    protected string $onTimeout = 'reject';

    /**
     * The on engine down.
     *
     * @var string
     */
    protected string $onEngineDown = 'allow';

    /**
     * The filter condition.
     *
     * @var string|null
     */
    protected ?string $filterCondition = null;

    /**
     * The configuration.
     *
     * @var string|null
     */
    protected ?string $configuration = null;

    /**
     * The mapping.
     *
     * @var integer|null
     */
    protected ?int $mapping = null;

    /**
     * The schemas.
     *
     * @var string|null
     */
    protected ?string $schemas = null;

    /**
     * The registers.
     *
     * @var string|null
     */
    protected ?string $registers = null;

    /**
     * The schedule.
     *
     * @var string|null
     */
    protected ?string $schedule = null;

    /**
     * The max retries.
     *
     * @var integer
     */
    protected int $maxRetries = 3;

    /**
     * The retry policy.
     *
     * @var string
     */
    protected string $retryPolicy = 'exponential';

    /**
     * The enabled.
     *
     * @var boolean
     */
    protected bool $enabled = true;

    /**
     * The owner.
     *
     * @var string|null
     */
    protected ?string $owner = null;

    /**
     * The application.
     *
     * @var string|null
     */
    protected ?string $application = null;

    /**
     * The organisation.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * The last executed at.
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastExecutedAt = null;

    /**
     * The execution count.
     *
     * @var integer
     */
    protected int $executionCount = 0;

    /**
     * The success count.
     *
     * @var integer
     */
    protected int $successCount = 0;

    /**
     * The failure count.
     *
     * @var integer
     */
    protected int $failureCount = 0;

    /**
     * The created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * The updated.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * The deleted.
     *
     * @var DateTime|null
     */
    protected ?DateTime $deleted = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'slug', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'eventType', type: 'string');
        $this->addType(fieldName: 'engine', type: 'string');
        $this->addType(fieldName: 'workflowId', type: 'string');
        $this->addType(fieldName: 'mode', type: 'string');
        $this->addType(fieldName: 'executionOrder', type: 'integer');
        $this->addType(fieldName: 'timeout', type: 'integer');
        $this->addType(fieldName: 'onFailure', type: 'string');
        $this->addType(fieldName: 'onTimeout', type: 'string');
        $this->addType(fieldName: 'onEngineDown', type: 'string');
        $this->addType(fieldName: 'filterCondition', type: 'string');
        $this->addType(fieldName: 'configuration', type: 'string');
        $this->addType(fieldName: 'mapping', type: 'integer');
        $this->addType(fieldName: 'schemas', type: 'string');
        $this->addType(fieldName: 'registers', type: 'string');
        $this->addType(fieldName: 'schedule', type: 'string');
        $this->addType(fieldName: 'maxRetries', type: 'integer');
        $this->addType(fieldName: 'retryPolicy', type: 'string');
        $this->addType(fieldName: 'enabled', type: 'boolean');
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'application', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'lastExecutedAt', type: 'datetime');
        $this->addType(fieldName: 'executionCount', type: 'integer');
        $this->addType(fieldName: 'successCount', type: 'integer');
        $this->addType(fieldName: 'failureCount', type: 'integer');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'deleted', type: 'datetime');
    }//end __construct()

    /**
     * Get event type as array (handles JSON array or single string)
     *
     * @return array
     */
    public function getEventTypeArray(): array
    {
        $decoded = json_decode($this->eventType, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [$this->eventType];
    }//end getEventTypeArray()

    /**
     * Get schemas as array
     *
     * @return array
     */
    public function getSchemasArray(): array
    {
        if ($this->schemas === null) {
            return [];
        }

        return json_decode($this->schemas, true) ?? [];
    }//end getSchemasArray()

    /**
     * Set schemas from array
     *
     * @param array|null $schemas Schemas array
     *
     * @return void
     */
    public function setSchemasArray(?array $schemas): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
        if ($schemas === null) {
            $this->setSchemas(null);
            return;
        }

        $this->setSchemas(json_encode(value: $schemas));
        // phpcs:enable CustomSniffs.Functions.NamedParameters
    }//end setSchemasArray()

    /**
     * Get registers as array
     *
     * @return array
     */
    public function getRegistersArray(): array
    {
        if ($this->registers === null) {
            return [];
        }

        return json_decode($this->registers, true) ?? [];
    }//end getRegistersArray()

    /**
     * Set registers from array
     *
     * @param array|null $registers Registers array
     *
     * @return void
     */
    public function setRegistersArray(?array $registers): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
        if ($registers === null) {
            $this->setRegisters(null);
            return;
        }

        $this->setRegisters(json_encode(value: $registers));
        // phpcs:enable CustomSniffs.Functions.NamedParameters
    }//end setRegistersArray()

    /**
     * Get filter condition as array
     *
     * @return array
     */
    public function getFilterConditionArray(): array
    {
        if ($this->filterCondition === null) {
            return [];
        }

        return json_decode($this->filterCondition, true) ?? [];
    }//end getFilterConditionArray()

    /**
     * Set filter condition from array
     *
     * @param array|null $filterCondition Filter condition array
     *
     * @return void
     */
    public function setFilterConditionArray(?array $filterCondition): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
        if ($filterCondition === null) {
            $this->setFilterCondition(null);
            return;
        }

        $this->setFilterCondition(json_encode(value: $filterCondition));
        // phpcs:enable CustomSniffs.Functions.NamedParameters
    }//end setFilterConditionArray()

    /**
     * Get configuration as array
     *
     * @return array
     */
    public function getConfigurationArray(): array
    {
        if ($this->configuration === null) {
            return [];
        }

        return json_decode($this->configuration, true) ?? [];
    }//end getConfigurationArray()

    /**
     * Set configuration from array
     *
     * @param array|null $configuration Configuration array
     *
     * @return void
     */
    public function setConfigurationArray(?array $configuration): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
        if ($configuration === null) {
            $this->setConfiguration(null);
            return;
        }

        $this->setConfiguration(json_encode(value: $configuration));
        // phpcs:enable CustomSniffs.Functions.NamedParameters
    }//end setConfigurationArray()

    /**
     * Check if event matches this action
     *
     * @param string $eventClass Event class name
     *
     * @return bool
     */
    public function matchesEvent(string $eventClass): bool
    {
        $eventTypes = $this->getEventTypeArray();

        if (empty($eventTypes) === true) {
            return true;
        }

        if (in_array($eventClass, $eventTypes) === true) {
            return true;
        }

        foreach ($eventTypes as $pattern) {
            if (fnmatch($pattern, $eventClass) === true) {
                return true;
            }
        }

        return false;
    }//end matchesEvent()

    /**
     * Check if action matches a schema UUID
     *
     * @param string|null $schemaUuid Schema UUID to check
     *
     * @return bool
     */
    public function matchesSchema(?string $schemaUuid): bool
    {
        $schemas = $this->getSchemasArray();

        if (empty($schemas) === true) {
            return true;
        }

        if ($schemaUuid === null) {
            return false;
        }

        return in_array($schemaUuid, $schemas);
    }//end matchesSchema()

    /**
     * Check if action matches a register UUID
     *
     * @param string|null $registerUuid Register UUID to check
     *
     * @return bool
     */
    public function matchesRegister(?string $registerUuid): bool
    {
        $registers = $this->getRegistersArray();

        if (empty($registers) === true) {
            return true;
        }

        if ($registerUuid === null) {
            return false;
        }

        return in_array($registerUuid, $registers);
    }//end matchesRegister()

    /**
     * JSON serialize the entity
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'description'     => $this->description,
            'version'         => $this->version,
            'status'          => $this->status,
            'eventType'       => $this->getEventTypeArray(),
            'engine'          => $this->engine,
            'workflowId'      => $this->workflowId,
            'mode'            => $this->mode,
            'executionOrder'  => $this->executionOrder,
            'timeout'         => $this->timeout,
            'onFailure'       => $this->onFailure,
            'onTimeout'       => $this->onTimeout,
            'onEngineDown'    => $this->onEngineDown,
            'filterCondition' => $this->getFilterConditionArray(),
            'configuration'   => $this->getConfigurationArray(),
            'mapping'         => $this->mapping,
            'schemas'         => $this->getSchemasArray(),
            'registers'       => $this->getRegistersArray(),
            'schedule'        => $this->schedule,
            'maxRetries'      => $this->maxRetries,
            'retryPolicy'     => $this->retryPolicy,
            'enabled'         => $this->enabled,
            'owner'           => $this->owner,
            'application'     => $this->application,
            'organisation'    => $this->organisation,
            'lastExecutedAt'  => $this->lastExecutedAt?->format('c'),
            'executionCount'  => $this->executionCount,
            'successCount'    => $this->successCount,
            'failureCount'    => $this->failureCount,
            'created'         => $this->created?->format('c'),
            'updated'         => $this->updated?->format('c'),
            'deleted'         => $this->deleted?->format('c'),
        ];
    }//end jsonSerialize()

    /**
     * Hydrate entity from array
     *
     * @param array $object Object data
     *
     * @return static The hydrated entity
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function hydrate(array $object): static
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
        if (($object['id'] ?? null) !== null) {
            $this->setId($object['id']);
        }

        if (($object['uuid'] ?? null) !== null) {
            $this->setUuid($object['uuid']);
        }

        if (($object['name'] ?? null) !== null) {
            $this->setName($object['name']);
        }

        if (($object['slug'] ?? null) !== null) {
            $this->setSlug($object['slug']);
        }

        if (($object['description'] ?? null) !== null) {
            $this->setDescription($object['description']);
        }

        if (($object['version'] ?? null) !== null) {
            $this->setVersion($object['version']);
        }

        if (($object['status'] ?? null) !== null) {
            $this->setStatus($object['status']);
        }

        if (($object['eventType'] ?? null) !== null) {
            $eventTypeValue = $object['eventType'];
            if (is_array($eventTypeValue) === true) {
                $eventTypeValue = json_encode(value: $eventTypeValue);
            }

            $this->setEventType($eventTypeValue);
        }

        if (($object['engine'] ?? null) !== null) {
            $this->setEngine($object['engine']);
        }

        if (($object['workflowId'] ?? null) !== null) {
            $this->setWorkflowId($object['workflowId']);
        }

        if (($object['mode'] ?? null) !== null) {
            $this->setMode($object['mode']);
        }

        if (($object['executionOrder'] ?? null) !== null) {
            $this->setExecutionOrder((int) $object['executionOrder']);
        }

        if (($object['timeout'] ?? null) !== null) {
            $this->setTimeout((int) $object['timeout']);
        }

        if (($object['onFailure'] ?? null) !== null) {
            $this->setOnFailure($object['onFailure']);
        }

        if (($object['onTimeout'] ?? null) !== null) {
            $this->setOnTimeout($object['onTimeout']);
        }

        if (($object['onEngineDown'] ?? null) !== null) {
            $this->setOnEngineDown($object['onEngineDown']);
        }

        if (($object['filterCondition'] ?? null) !== null && is_array($object['filterCondition']) === true) {
            $this->setFilterConditionArray($object['filterCondition']);
        } else if (($object['filterCondition'] ?? null) !== null) {
            $this->setFilterCondition($object['filterCondition']);
        }

        if (($object['configuration'] ?? null) !== null && is_array($object['configuration']) === true) {
            $this->setConfigurationArray($object['configuration']);
        } else if (($object['configuration'] ?? null) !== null) {
            $this->setConfiguration($object['configuration']);
        }

        if (($object['mapping'] ?? null) !== null) {
            $this->setMapping((int) $object['mapping']);
        }

        if (($object['schemas'] ?? null) !== null && is_array($object['schemas']) === true) {
            $this->setSchemasArray($object['schemas']);
        } else if (($object['schemas'] ?? null) !== null) {
            $this->setSchemas($object['schemas']);
        }

        if (($object['registers'] ?? null) !== null && is_array($object['registers']) === true) {
            $this->setRegistersArray($object['registers']);
        } else if (($object['registers'] ?? null) !== null) {
            $this->setRegisters($object['registers']);
        }

        if (($object['schedule'] ?? null) !== null) {
            $this->setSchedule($object['schedule']);
        }

        if (($object['maxRetries'] ?? null) !== null) {
            $this->setMaxRetries((int) $object['maxRetries']);
        }

        if (($object['retryPolicy'] ?? null) !== null) {
            $this->setRetryPolicy($object['retryPolicy']);
        }

        if (($object['enabled'] ?? null) !== null) {
            $this->setEnabled((bool) $object['enabled']);
        }

        if (($object['owner'] ?? null) !== null) {
            $this->setOwner($object['owner']);
        }

        if (($object['application'] ?? null) !== null) {
            $this->setApplication($object['application']);
        }

        if (($object['organisation'] ?? null) !== null) {
            $this->setOrganisation($object['organisation']);
        }

        if (($object['schedule'] ?? null) !== null) {
            $this->setSchedule($object['schedule']);
        }

        return $this;
        // phpcs:enable
    }//end hydrate()
}//end class
