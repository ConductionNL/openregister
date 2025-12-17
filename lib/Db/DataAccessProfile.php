<?php
/**
 * OpenRegister DataAccessProfile Entity
 *
 * This file contains the class for the DataAccessProfile entity.
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
 * DataAccessProfile entity class.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getPermissions()
 * @method void setPermissions(?array $permissions)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 */
class DataAccessProfile extends Entity implements JsonSerializable
{

    /**
     * UUID.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Name.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * Description.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * Permissions.
     *
     * @var array|null
     */
    protected ?array $permissions = [];

    /**
     * Created timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Updated timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('permissions', 'json');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');

    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return (array|int|null|string)[]
     *
     * @psalm-return array{id: int, uuid: null|string, name: null|string, description: null|string, permissions: array|null, created: null|string, updated: null|string}
     */
    public function jsonSerialize(): array
    {
        $created = null;
        if ($this->created !== null) {
            $created = $this->created->format('c');
        }

        $updated = null;
        if ($this->updated !== null) {
            $updated = $this->updated->format('c');
        }

        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'name'        => $this->name,
            'description' => $this->description,
            'permissions' => $this->permissions,
            'created'     => $created,
            'updated'     => $updated,
        ];

    }//end jsonSerialize()

    /**
     * String representation of the data access profile
     *
     * This magic method is required for proper entity handling in Nextcloud
     * when the framework needs to convert the object to a string.
     *
     * @return string String representation of the data access profile
     */
    public function __toString(): string
    {
        // Return the name if available, otherwise return a descriptive string.
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        // Fallback to UUID if available.
        if ($this->uuid !== null && $this->uuid !== '') {
            return $this->uuid;
        }

        // Fallback to ID if available.
        if ($this->id !== null) {
            return 'DataAccessProfile #'.$this->id;
        }

        // Final fallback.
        return 'Data Access Profile';

    }//end __toString()
}//end class
