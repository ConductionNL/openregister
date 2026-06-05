<?php

/**
 * MapLink entity for linking geolocations (NC Maps) to OpenRegister objects.
 *
 * Stores lat/lon/address cached from geocoding so rendering never calls the Maps API.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-maps/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class MapLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method string|null getAddress()
 * @method void setAddress(?string $address)
 * @method float|null getLat()
 * @method void setLat(?float $lat)
 * @method float|null getLon()
 * @method void setLon(?float $lon)
 * @method string|null getAddressSource()
 * @method void setAddressSource(?string $addressSource)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class MapLink extends Entity implements JsonSerializable
{

    /**
     * UUID of the linked OpenRegister object.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * Register ID the object belongs to.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * Human-readable geocoded address.
     *
     * @var string|null
     */
    protected ?string $address = null;

    /**
     * Latitude cached from geocoding.
     *
     * @var float|null
     */
    protected ?float $lat = null;

    /**
     * Longitude cached from geocoding.
     *
     * @var float|null
     */
    protected ?float $lon = null;

    /**
     * How the coordinates were obtained: 'address-geocoded' or 'click-placed'.
     *
     * @var string|null
     */
    protected ?string $addressSource = null;

    /**
     * UID of the user who created the link.
     *
     * @var string|null
     */
    protected ?string $linkedBy = null;

    /**
     * Timestamp when the link was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $linkedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'address', type: 'string');
        $this->addType(fieldName: 'lat', type: 'float');
        $this->addType(fieldName: 'lon', type: 'float');
        $this->addType(fieldName: 'addressSource', type: 'string');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'objectUuid'    => $this->objectUuid,
            'registerId'    => $this->registerId,
            'address'       => $this->address,
            'lat'           => $this->lat,
            'lon'           => $this->lon,
            'addressSource' => $this->addressSource,
            'linkedBy'      => $this->linkedBy,
            'linkedAt'      => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
