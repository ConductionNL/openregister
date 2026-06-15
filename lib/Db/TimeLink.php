<?php

/**
 * TimeLink entity for linking time entries to OpenRegister objects.
 *
 * Stores per-entry rows and a denormalized per-object hour total for fast
 * dashboard rendering (AD-2: totals denormalized into link table).
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
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class TimeLink
 *
 * Represents a single time entry linked to an OpenRegister object.
 * The `totalMinutes` field is a denormalized aggregate of all entries for
 * the object and is recalculated on every write.
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method string getBackendEntryId()
 * @method void setBackendEntryId(string $backendEntryId)
 * @method string getBackendName()
 * @method void setBackendName(string $backendName)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getDurationMinutes()
 * @method void setDurationMinutes(int $durationMinutes)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method DateTime|null getEntryDate()
 * @method void setEntryDate(?DateTime $entryDate)
 * @method int getTotalMinutes()
 * @method void setTotalMinutes(int $totalMinutes)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
 */
class TimeLink extends Entity implements JsonSerializable
{

    /**
     * The object uuid this time entry belongs to.
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
     * The backend's own entry ID (e.g. Time Manager record ID).
     *
     * @var string|null
     */
    protected ?string $backendEntryId = null;

    /**
     * The name of the backing time-tracking app (e.g. 'timemanager').
     *
     * @var string|null
     */
    protected ?string $backendName = null;

    /**
     * The user who logged the time.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * Duration in minutes for this single entry.
     *
     * @var integer|null
     */
    protected ?int $durationMinutes = null;

    /**
     * Optional description for this time entry.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * The date of the time entry.
     *
     * @var DateTime|null
     */
    protected ?DateTime $entryDate = null;

    /**
     * Denormalized total minutes across ALL entries for this object.
     * Recalculated on every write (AD-2).
     *
     * @var integer|null
     */
    protected ?int $totalMinutes = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * Last-updated timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updatedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'backendEntryId', type: 'string');
        $this->addType(fieldName: 'backendName', type: 'string');
        $this->addType(fieldName: 'userId', type: 'string');
        $this->addType(fieldName: 'durationMinutes', type: 'integer');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'entryDate', type: 'datetime');
        $this->addType(fieldName: 'totalMinutes', type: 'integer');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'updatedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->id,
            'objectUuid'      => $this->objectUuid,
            'registerId'      => $this->registerId,
            'backendEntryId'  => $this->backendEntryId,
            'backendName'     => $this->backendName,
            'userId'          => $this->userId,
            'durationMinutes' => $this->durationMinutes,
            'description'     => $this->description,
            'entryDate'       => $this->entryDate?->format(DateTime::ATOM),
            'totalMinutes'    => $this->totalMinutes,
            'createdAt'       => $this->createdAt?->format(DateTime::ATOM),
            'updatedAt'       => $this->updatedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
