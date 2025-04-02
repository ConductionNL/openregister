<?php
/**
 * OpenRegister Audit Trail
 *
 * This file contains the class for handling audit trail related operations
 * in the OpenRegister application.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git-id>
 *
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity class representing an Audit Trail entry
 *
 * Manages audit trail data and operations
 *
 * @package OCA\OpenRegister\Db
 */
class AuditTrail extends Entity implements JsonSerializable
{
    /**
     * Unique identifier for the audit trail entry
     *
     * @var string|null Unique identifier for the audit trail entry
     */
    protected ?string $uuid = null;

    /**
     * Schema ID associated with the audit trail entry
     *
     * @var integer|null Schema ID associated with the audit trail entry
     */
    protected ?int $schema = null;

    /**
     * Register ID associated with the audit trail entry
     *
     * @var integer|null Register ID associated with the audit trail entry
     */
    protected ?int $register = null;

    /**
     * Object ID associated with the audit trail entry
     *
     * @var integer|null Object ID associated with the audit trail entry
     */
    protected ?int $object = null;

    /**
     * UUID of the object associated with the audit trail entry
     *
     * @var string|null UUID of the object associated with the audit trail entry
     */
    protected ?string $objectUuid = null;

    /**
     * UUID of the register associated with the audit trail entry
     *
     * @var string|null UUID of the register associated with the audit trail entry
     */
    protected ?string $registerUuid = null;

    /**
     * UUID of the schema associated with the audit trail entry
     *
     * @var string|null UUID of the schema associated with the audit trail entry
     */
    protected ?string $schemaUuid = null;

    /**
     * Action performed in the audit trail entry
     *
     * @var string|null Action performed in the audit trail entry
     */
    protected ?string $action = null;

    /**
     * Changed data in the audit trail entry
     *
     * @var array|null Changed data in the audit trail entry
     */
    protected ?array $changed = null;

    /**
     * User ID associated with the audit trail entry
     *
     * @var string|null User ID associated with the audit trail entry
     */
    protected ?string $user = null;

    /**
     * Username associated with the audit trail entry
     *
     * @var string|null Username associated with the audit trail entry
     */
    protected ?string $userName = null;

    /**
     * Session ID associated with the audit trail entry
     *
     * @var string|null Session ID associated with the audit trail entry
     */
    protected ?string $session = null;

    /**
     * Request data associated with the audit trail entry
     *
     * @var string|null Request data associated with the audit trail entry
     */
    protected ?string $request = null;

    /**
     * IP address associated with the audit trail entry
     *
     * @var string|null IP address associated with the audit trail entry
     */
    protected ?string $ipAddress = null;

    /**
     * Version of the audit trail entry
     *
     * @var string|null Version of the audit trail entry
     */
    protected ?string $version = null;

    /**
     * Creation timestamp of the audit trail entry
     *
     * @var DateTime|null Creation timestamp of the audit trail entry
     */
    protected ?DateTime $created = null;

    /**
     * The unique identifier of the organization processing personal data
     *
     * This can be an OIN (Organisatie Identificatie Nummer), RSIN (Rechtspersonen en Samenwerkingsverbanden
     * Informatienummer), KVK (Kamer van Koophandel) number, or any other official organization identifier.
     *
     * @var string|null The unique identifier of the organization processing personal data
     */
    protected ?string $organisationId = null;

    /**
     * The type of organization identifier used
     *
     * Common values include:
     * - 'OIN': Organisatie Identificatie Nummer
     * - 'RSIN': Rechtspersonen en Samenwerkingsverbanden Informatienummer
     * - 'KVK': Kamer van Koophandel
     * - 'OTHER': Other type of organization identifier
     *
     * @var string|null The type of organization identifier used
     */
    protected ?string $organisationIdType = null;

    /**
     * The Processing Activity ID that identifies the specific processing operation
     *
     * @var string|null The Processing Activity ID that identifies the specific processing operation
     */
    protected ?string $processingActivityId = null;

    /**
     * The URL where the processing activity is registered
     *
     * @var string|null The URL where the processing activity is registered
     */
    protected ?string $processingActivityUrl = null;

    /**
     * The unique identifier for this specific processing operation
     *
     * @var string|null The unique identifier for this specific processing operation
     */
    protected ?string $processingId = null;

    /**
     * The confidentiality level of the processed data
     *
     * @var string|null The confidentiality level of the processed data (e.g., 'public', 'internal', 'confidential')
     */
    protected ?string $confidentiality = null;

    /**
     * The retention period for the processed data in ISO 8601 duration format
     *
     * @var string|null The retention period for the processed data in ISO 8601 duration format
     */
    protected ?string $retentionPeriod = null;

    /**
     * Constructor for the AuditTrail class
     *
     * Sets up field types for all properties
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'schema', type: 'integer');
        $this->addType(fieldName: 'register', type: 'integer');
        $this->addType(fieldName: 'object', type: 'integer');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerUuid', type: 'string');
        $this->addType(fieldName: 'schemaUuid', type: 'string');
        $this->addType(fieldName: 'action', type: 'string');
        $this->addType(fieldName: 'changed', type: 'json');
        $this->addType(fieldName: 'user', type: 'string');
        $this->addType(fieldName: 'userName', type: 'string');
        $this->addType(fieldName: 'session', type: 'string');
        $this->addType(fieldName: 'request', type: 'string');
        $this->addType(fieldName: 'ipAddress', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'organisationId', type: 'string');
        $this->addType(fieldName: 'organisationIdType', type: 'string');
        $this->addType(fieldName: 'processingActivityId', type: 'string');
        $this->addType(fieldName: 'processingActivityUrl', type: 'string');
        $this->addType(fieldName: 'processingId', type: 'string');
        $this->addType(fieldName: 'confidentiality', type: 'string');
        $this->addType(fieldName: 'retentionPeriod', type: 'string');

    }//end __construct()

    /**
     * Get the changed data
     *
     * @return array The changed data or empty array if null
     */
    public function getChanged(): array
    {
        return ($this->changed ?? []);

    }//end getChanged()

    /**
     * Get JSON fields from the entity
     *
     * Returns all fields that are of type 'json'
     *
     * @return array<string> List of JSON field names
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                $this->getFieldTypes(),
                function ($field) {
                    return $field === 'json';
                }
            )
        );

    }//end getJsonFields()

    /**
     * Hydrate the entity with data from an array
     *
     * Sets entity properties based on input array values
     *
     * @param array $object The data array to hydrate from
     *
     * @return self Returns $this for method chaining
     */
    public function hydrate(array $object): self
    {
        $jsonFields = $this->getJsonFields();

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = null;
            }

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
     * Convert entity to JSON serializable array
     *
     * Prepares the entity data for JSON serialization
     *
     * @return array<string, mixed> Array of serializable entity data
     */
    public function jsonSerialize(): array
    {
        $created = null;
        if (isset($this->created) === true) {
            $created = $this->created->format('c');
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'schema' => $this->schema,
            'register' => $this->register,
            'object' => $this->object,
            'objectUuid' => $this->objectUuid,
            'registerUuid' => $this->registerUuid,
            'schemaUuid' => $this->schemaUuid,
            'action' => $this->action,
            'changed' => $this->changed,
            'user' => $this->user,
            'userName' => $this->userName,
            'session' => $this->session,
            'request' => $this->request,
            'ipAddress' => $this->ipAddress,
            'version' => $this->version,
            'created' => $created,
            'organisationId' => $this->organisationId,
            'organisationIdType' => $this->organisationIdType,
            'processingActivityId' => $this->processingActivityId,
            'processingActivityUrl' => $this->processingActivityUrl,
            'processingId' => $this->processingId,
            'confidentiality' => $this->confidentiality,
            'retentionPeriod' => $this->retentionPeriod,
        ];

    }//end jsonSerialize()

}//end class
