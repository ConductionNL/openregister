<?php

/**
 * MapLink entity for linking NC Maps favorites (POIs) to OpenRegister
 * objects.
 *
 * Tier-2 schema: carries register_id, schema_id, name, category, lat,
 * lng and comment so the link row alone can hydrate the sidebar tab +
 * picker UX without a per-POI roundtrip to NC Maps. Replaces the
 * wave-1 `MapsProvider`'s `[or:{uuid}]` favorite-name marker convention
 * with a proper persistence layer that survives POI renames.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * Class MapLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getFavoriteId()
 * @method void setFavoriteId(int $favoriteId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method float getLat()
 * @method void setLat(float $lat)
 * @method float getLng()
 * @method void setLng(float $lng)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method string|null getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class MapLink extends Entity implements JsonSerializable
{

    /**
     * The object uuid.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The register id.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * The schema id.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * The NC Maps favorite id (primary key in `maps_favorites`).
     *
     * @var integer|null
     */
    protected ?int $favoriteId = null;

    /**
     * The POI display name (cached at link time).
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * The POI category (cached at link time).
     *
     * @var string|null
     */
    protected ?string $category = null;

    /**
     * The cached latitude.
     *
     * @var float|null
     */
    protected ?float $lat = null;

    /**
     * The cached longitude.
     *
     * @var float|null
     */
    protected ?float $lng = null;

    /**
     * The cached POI comment.
     *
     * @var string|null
     */
    protected ?string $comment = null;

    /**
     * The linked by uid.
     *
     * @var string|null
     */
    protected ?string $linkedBy = null;

    /**
     * The linked at timestamp.
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
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'favoriteId', type: 'integer');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'category', type: 'string');
        $this->addType(fieldName: 'lat', type: 'float');
        $this->addType(fieldName: 'lng', type: 'float');
        $this->addType(fieldName: 'comment', type: 'string');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'objectUuid' => $this->objectUuid,
            'registerId' => $this->registerId,
            'schemaId'   => $this->schemaId,
            'favoriteId' => $this->favoriteId,
            'name'       => $this->name,
            'category'   => $this->category,
            'lat'        => $this->lat,
            'lng'        => $this->lng,
            'comment'    => $this->comment,
            'linkedBy'   => $this->linkedBy,
            'linkedAt'   => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
