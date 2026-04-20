<?php

/**
 * OpenRegister Selection List Entity
 *
 * Represents a selectielijst entry that maps classification categories
 * to retention periods and archival actions per Dutch archival standards.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Entity class representing a selection list entry for archival retention rules
 *
 * Maps classification categories (e.g. B1, A1) to retention periods and
 * archival actions (vernietigen/bewaren) following the VNG selectielijst.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method int|null getRetentionYears()
 * @method void setRetentionYears(?int $retentionYears)
 * @method string|null getAction()
 * @method void setAction(?string $action)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getSchemaOverrides()
 * @method void setSchemaOverrides(?array $schemaOverrides)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PossiblyUnusedMethod
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class SelectionList extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the selection list entry.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Classification category code (e.g. B1, A1).
     *
     * @var string|null
     */
    protected ?string $category = null;

    /**
     * Number of years to retain objects in this category.
     *
     * @var integer|null
     */
    protected ?int $retentionYears = null;

    /**
     * Archival action: 'vernietigen' or 'bewaren'.
     *
     * @var string|null
     */
    protected ?string $action = null;

    /**
     * Human-readable description of this selection list entry.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * Schema-level overrides for retention years.
     * JSON map of schema UUID to override retention years.
     *
     * @var array|null
     */
    protected ?array $schemaOverrides = [];

    /**
     * Organisation that owns this selection list entry.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

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
     * Valid archival actions.
     */
    public const VALID_ACTIONS = ['vernietigen', 'bewaren'];

    /**
     * Initialize the entity and define field types.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'category', type: 'string');
        $this->addType(fieldName: 'retentionYears', type: 'integer');
        $this->addType(fieldName: 'action', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'schemaOverrides', type: 'json');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to JSON format.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->uuid,
            'uuid'            => $this->uuid,
            'category'        => $this->category,
            'retentionYears'  => $this->retentionYears,
            'action'          => $this->action,
            'description'     => $this->description,
            'schemaOverrides' => $this->schemaOverrides ?? [],
            'organisation'    => $this->organisation,
            'created'         => $this->created instanceof DateTime ? $this->created->format('c') : null,
            'updated'         => $this->updated instanceof DateTime ? $this->updated->format('c') : null,
        ];
    }//end jsonSerialize()

    /**
     * Hydrate the entity from an array.
     *
     * @param array<string, mixed> $data The data array
     *
     * @return static
     */
    public function hydrate(array $data): static
    {
        // phpcs:disable -- Entity __call setters cannot use named args.
        if (isset($data['uuid']) === true) {
            $this->setUuid($data['uuid']);
        }

        if (isset($data['category']) === true) {
            $this->setCategory($data['category']);
        }

        if (isset($data['retentionYears']) === true) {
            $this->setRetentionYears((int) $data['retentionYears']);
        }

        if (isset($data['action']) === true) {
            $this->setAction($data['action']);
        }

        if (isset($data['description']) === true) {
            $this->setDescription($data['description']);
        }

        if (isset($data['schemaOverrides']) === true) {
            $this->setSchemaOverrides($data['schemaOverrides']);
        }

        if (isset($data['organisation']) === true) {
            $this->setOrganisation($data['organisation']);
        }

        // phpcs:enable
        return $this;
    }//end hydrate()
}//end class
