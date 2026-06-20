<?php

/**
 * OpenRegister Sync Record
 *
 * Per-record tracking entity for the harvest pipeline. One row is created
 * for every source record identified during the Gather stage; the row then
 * tracks the record through Fetch and Import with a status and any error.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
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
 * SyncRecord entity class.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method int|null getSourceId()
 * @method void setSourceId(?int $sourceId)
 * @method string|null getExecutionId()
 * @method void setExecutionId(?string $executionId)
 * @method string|null getExternalId()
 * @method void setExternalId(?string $externalId)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getContentHash()
 * @method void setContentHash(?string $contentHash)
 * @method array|null getRawData()
 * @method void setRawData(?array $rawData)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method int|null getAttempts()
 * @method void setAttempts(?int $attempts)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class SyncRecord extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the sync record.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Owning source id.
     *
     * @var integer|null
     */
    protected ?int $sourceId = null;

    /**
     * Sync execution id this record belongs to (groups one run).
     *
     * @var string|null
     */
    protected ?string $executionId = null;

    /**
     * External identifier of the source record.
     *
     * @var string|null
     */
    protected ?string $externalId = null;

    /**
     * Pipeline status (see SyncRecordStatus).
     *
     * @var string|null
     */
    protected ?string $status = null;

    /**
     * UUID of the local object created/updated from this record.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * Content hash used to detect changes between syncs.
     *
     * @var string|null
     */
    protected ?string $contentHash = null;

    /**
     * Raw fetched data for this record.
     *
     * @var array|null
     */
    protected ?array $rawData = null;

    /**
     * Error message when the record failed.
     *
     * @var string|null
     */
    protected ?string $errorMessage = null;

    /**
     * Number of processing attempts (for retry/backoff).
     *
     * @var integer|null
     */
    protected ?int $attempts = null;

    /**
     * Organisation UUID this record belongs to.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * Last update timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Constructor: register field types.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'sourceId', type: 'integer');
        $this->addType(fieldName: 'executionId', type: 'string');
        $this->addType(fieldName: 'externalId', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'contentHash', type: 'string');
        $this->addType(fieldName: 'rawData', type: 'json');
        $this->addType(fieldName: 'errorMessage', type: 'string');
        $this->addType(fieldName: 'attempts', type: 'integer');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
    }//end __construct()

    /**
     * Get JSON field names.
     *
     * @return string[] List of JSON field names
     *
     * @psalm-return list<string>
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                $this->getFieldTypes(),
                static function ($field) {
                    return $field === 'json';
                }
            )
        );
    }//end getJsonFields()

    /**
     * Hydrate the entity from an array.
     *
     * @param array $object The data array
     *
     * @return static
     */
    public function hydrate(array $object): static
    {
        $jsonFields = $this->getJsonFields();

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields, true) === true && $value === []) {
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
     * Serialize to a JSON-friendly array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $updated = null;
        if ($this->updated !== null) {
            $updated = $this->updated->format('c');
        }

        $created = null;
        if ($this->created !== null) {
            $created = $this->created->format('c');
        }

        return [
            'id'           => $this->id,
            'uuid'         => $this->uuid,
            'sourceId'     => $this->sourceId,
            'executionId'  => $this->executionId,
            'externalId'   => $this->externalId,
            'status'       => $this->status,
            'objectUuid'   => $this->objectUuid,
            'contentHash'  => $this->contentHash,
            'rawData'      => $this->rawData,
            'errorMessage' => $this->errorMessage,
            'attempts'     => $this->attempts,
            'organisation' => $this->organisation,
            'updated'      => $updated,
            'created'      => $created,
        ];
    }//end jsonSerialize()
}//end class
