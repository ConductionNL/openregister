<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity class representing an Audit Trail entry
 *
 * This class handles the storage and tracking of all changes and actions performed
 * on objects, registers, and schemas in the OpenRegister system.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 * @author   Nextcloud GmbH and Nextcloud contributors
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  0.1.48
 */
class AuditTrail extends Entity implements JsonSerializable
{

    protected ?string $uuid = null;

    protected ?int $schema = null;

    protected ?int $register = null;

    protected ?int $object = null;

    protected ?string $objectUuid = null;

    protected ?string $registerUuid = null;

    protected ?string $schemaUuid = null;

    protected ?string $action = null;

    protected ?array $changed = null;

    protected ?string $user = null;

    protected ?string $userName = null;

    protected ?string $session = null;

    protected ?string $request = null;

    protected ?string $ipAddress = null;

    protected ?string $version = null;

    protected ?DateTime $created = null;

    // Properties from Dutch standards for personal data processing

    /**
     * @var string|null The unique identifier of the organization processing personal data.
     * This can be an OIN (Organisatie Identificatie Nummer), RSIN (Rechtspersonen en Samenwerkingsverbanden Informatienummer),
     * KVK (Kamer van Koophandel) number, or any other official organization identifier.
     */
    protected ?string $organisationId = null;

    /**
     * @var string|null The type of organization identifier used.
     * Common values include:
     * - 'OIN': Organisatie Identificatie Nummer
     * - 'RSIN': Rechtspersonen en Samenwerkingsverbanden Informatienummer
     * - 'KVK': Kamer van Koophandel
     * - 'OTHER': Other type of organization identifier
     */
    protected ?string $organisationIdType = null;

    /**
     * @var string|null The Processing Activity ID that identifies the specific processing operation
     */
    protected ?string $processingActivityId = null;

    /**
     * @var string|null The URL where the processing activity is registered
     */
    protected ?string $processingActivityUrl = null;

    /**
     * @var string|null The unique identifier for this specific processing operation
     */
    protected ?string $processingId = null;

    /**
     * @var string|null The confidentiality level of the processed data (e.g., 'public', 'internal', 'confidential')
     */
    protected ?string $confidentiality = null;

    /**
     * @var string|null The retention period for the processed data in ISO 8601 duration format
     */
    protected ?string $retentionPeriod = null;


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
            }
        }

        return $this;

    }//end hydrate()


    public function jsonSerialize(): array
    {
        return [
            'id'                    => $this->id,
            'uuid'                  => $this->uuid,
            'schema'                => $this->schema,
            'register'              => $this->register,
            'object'                => $this->object,
            'objectUuid'            => $this->objectUuid,
            'registerUuid'          => $this->registerUuid,
            'schemaUuid'            => $this->schemaUuid,
            'action'                => $this->action,
            'changed'               => $this->changed,
            'user'                  => $this->user,
            'userName'              => $this->userName,
            'session'               => $this->session,
            'request'               => $this->request,
            'ipAddress'             => $this->ipAddress,
            'version'               => $this->version,
            'created'               => isset($this->created) ? $this->created->format('c') : null,
            'organisationId'        => $this->organisationId,
            'organisationIdType'    => $this->organisationIdType,
            'processingActivityId'  => $this->processingActivityId,
            'processingActivityUrl' => $this->processingActivityUrl,
            'processingId'          => $this->processingId,
            'confidentiality'       => $this->confidentiality,
            'retentionPeriod'       => $this->retentionPeriod,
        ];

    }//end jsonSerialize()


}//end class
