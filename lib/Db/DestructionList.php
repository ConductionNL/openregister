<?php

/**
 * OpenRegister Destruction List Entity
 *
 * Represents a destruction list containing objects that are due for
 * permanent deletion as part of the archival destruction workflow.
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
 * Entity class representing a destruction list for archival workflow
 *
 * A destruction list groups objects due for destruction, tracks approval
 * workflow status, and maintains an audit record of the destruction process.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method array|null getObjects()
 * @method void setObjects(?array $objects)
 * @method string|null getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method DateTime|null getApprovedAt()
 * @method void setApprovedAt(?DateTime $approvedAt)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
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
class DestructionList extends Entity implements JsonSerializable
{

    /**
     * Valid status values for destruction lists.
     */
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED       = 'approved';
    public const STATUS_COMPLETED      = 'completed';
    public const STATUS_CANCELLED      = 'cancelled';

    /**
     * All valid statuses.
     */
    public const VALID_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Unique identifier.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Human-readable name of the destruction list.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * Current workflow status.
     *
     * @var string|null
     */
    protected ?string $status = null;

    /**
     * Array of object UUIDs included in this destruction list.
     *
     * @var array|null
     */
    protected ?array $objects = [];

    /**
     * User ID of the approver.
     *
     * @var string|null
     */
    protected ?string $approvedBy = null;

    /**
     * Timestamp of approval.
     *
     * @var DateTime|null
     */
    protected ?DateTime $approvedAt = null;

    /**
     * Notes or comments on the destruction list.
     *
     * @var string|null
     */
    protected ?string $notes = null;

    /**
     * Organisation that owns this destruction list.
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
     * Initialize the entity and define field types.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'objects', type: 'json');
        $this->addType(fieldName: 'approvedBy', type: 'string');
        $this->addType(fieldName: 'approvedAt', type: 'datetime');
        $this->addType(fieldName: 'notes', type: 'string');
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
            'id'           => $this->uuid,
            'uuid'         => $this->uuid,
            'name'         => $this->name,
            'status'       => $this->status,
            'objects'      => $this->objects ?? [],
            'objectCount'  => count($this->objects ?? []),
            'approvedBy'   => $this->approvedBy,
            'approvedAt'   => $this->approvedAt instanceof DateTime ? $this->approvedAt->format('c') : null,
            'notes'        => $this->notes,
            'organisation' => $this->organisation,
            'created'      => $this->created instanceof DateTime ? $this->created->format('c') : null,
            'updated'      => $this->updated instanceof DateTime ? $this->updated->format('c') : null,
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
        if (isset($data['uuid']) === true) {
            $this->setUuid($data['uuid']);
        }

        if (isset($data['name']) === true) {
            $this->setName($data['name']);
        }

        if (isset($data['status']) === true) {
            $this->setStatus($data['status']);
        }

        if (isset($data['objects']) === true) {
            $this->setObjects($data['objects']);
        }

        if (isset($data['notes']) === true) {
            $this->setNotes($data['notes']);
        }

        if (isset($data['organisation']) === true) {
            $this->setOrganisation($data['organisation']);
        }

        return $this;
    }//end hydrate()
}//end class
