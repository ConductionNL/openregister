<?php
/**
 * OpenRegister Configuration Entity
 *
 * This file contains the Configuration entity class for the OpenRegister application.
 *
 * @category Entity
 * @package  OCA\OpenRegister\Db
 *
 * @author    Ruben Linde <ruben@nextcloud.com>
 * @copyright Copyright (c) 2024, Ruben Linde (https://github.com/rubenlinde)
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/cloud-py-api/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Configuration entity class
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getType()
 * @method void setType(string $type)
 * @method array|null getData()
 * @method void setData(?array $data)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method DateTime getCreated()
 * @method void setCreated(DateTime $created)
 * @method DateTime getUpdated()
 * @method void setUpdated(DateTime $updated)
 *
 * @package OCA\OpenRegister\Db
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class Configuration extends Entity implements JsonSerializable {
    /** @var string Title of the configuration */
    protected $title;

    /** @var string|null Description of the configuration */
    protected $description;

    /** @var string Type of the configuration */
    protected $type;

    /** @var array|null Configuration data */
    protected $data;

    /** @var string Owner of the configuration */
    protected $owner;

    /** @var DateTime Creation timestamp */
    protected $created;

    /** @var DateTime Last update timestamp */
    protected $updated;

    /**
     * Constructor to set up the entity with required types
     */
    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('type', 'string');
        $this->addType('data', 'array');
        $this->addType('owner', 'string');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
    }

    /**
     * Serialize the entity to JSON
     *
     * @return array<string, mixed> The serialized entity
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'data' => $this->data,
            'owner' => $this->owner,
            'created' => $this->created ? $this->created->format('c') : null,
            'updated' => $this->updated ? $this->updated->format('c') : null,
        ];
    }
} 