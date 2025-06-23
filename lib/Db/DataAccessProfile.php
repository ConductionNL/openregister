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

class DataAccessProfile extends Entity implements JsonSerializable
{
    /**
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * @var array|null
     */
    protected ?array $permissions = [];
    
    /**
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('permissions', 'json');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => $this->permissions,
            'created' => $this->created ? $this->created->format('c') : null,
            'updated' => $this->updated ? $this->updated->format('c') : null,
        ];
    }
} 