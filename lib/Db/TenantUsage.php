<?php

/**
 * OpenRegister TenantUsage Entity
 *
 * Tracks per-organisation resource usage (requests, bandwidth, storage)
 * for quota enforcement and dashboard display.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * TenantUsage Entity
 *
 * Records resource usage per organisation per hourly period.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method string getOrganisationUuid()
 * @method void setOrganisationUuid(string $organisationUuid)
 * @method DateTime getPeriod()
 * @method void setPeriod(DateTime $period)
 * @method int getRequestCount()
 * @method void setRequestCount(int $requestCount)
 * @method int getBandwidthBytes()
 * @method void setBandwidthBytes(int $bandwidthBytes)
 * @method int getStorageBytes()
 * @method void setStorageBytes(int $storageBytes)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class TenantUsage extends Entity implements JsonSerializable
{

    /**
     * Organisation UUID.
     *
     * @var string
     */
    protected string $organisationUuid = '';

    /**
     * Usage period (hourly bucket).
     *
     * @var DateTime
     */
    protected ?DateTime $period = null;

    /**
     * Number of API requests.
     *
     * @var integer
     */
    protected int $requestCount = 0;

    /**
     * Bandwidth in bytes.
     *
     * @var integer
     */
    protected int $bandwidthBytes = 0;

    /**
     * Storage in bytes.
     *
     * @var integer
     */
    protected int $storageBytes = 0;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Last update timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->addType(fieldName: 'organisation_uuid', type: 'string');
        $this->addType(fieldName: 'period', type: 'datetime');
        $this->addType(fieldName: 'request_count', type: 'integer');
        $this->addType(fieldName: 'bandwidth_bytes', type: 'integer');
        $this->addType(fieldName: 'storage_bytes', type: 'integer');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization
     *
     * @return array Serialized usage data
     */
    public function jsonSerialize(): array
    {
        return [
            'id'               => $this->id,
            'organisationUuid' => $this->organisationUuid,
            'period'           => $this->period instanceof DateTime ? $this->period->format('c') : null,
            'requestCount'     => $this->requestCount,
            'bandwidthBytes'   => $this->bandwidthBytes,
            'storageBytes'     => $this->storageBytes,
            'created'          => $this->created instanceof DateTime ? $this->created->format('c') : null,
            'updated'          => $this->updated instanceof DateTime ? $this->updated->format('c') : null,
        ];
    }//end jsonSerialize()
}//end class
