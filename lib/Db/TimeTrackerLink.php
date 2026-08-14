<?php

/**
 * TimeTrackerLink entity for linking NC TimeManager entries (clients,
 * tasks, time entries) to OpenRegister objects.
 *
 * Tier-2 schema: carries register_id, schema_id, an entry_type
 * discriminator (`client` | `task` | `time`), the three upstream uuids
 * (client_id / task_id / time_id), plus cached name, duration, billable
 * and started_at so the link row alone can hydrate the sidebar tab + the
 * picker UX without a per-entry roundtrip to NC TimeManager. Replaces the
 * Tier-1 `TimeProvider`'s `[or:{uuid}]` note/name-marker convention with
 * a proper persistence layer that survives entity renames.
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
 * Class TimeTrackerLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string getEntryType()
 * @method void setEntryType(string $entryType)
 * @method string|null getClientId()
 * @method void setClientId(?string $clientId)
 * @method string|null getTaskId()
 * @method void setTaskId(?string $taskId)
 * @method string|null getTimeId()
 * @method void setTimeId(?string $timeId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int|null getDuration()
 * @method void setDuration(?int $duration)
 * @method bool|null getBillable()
 * @method void setBillable(?bool $billable)
 * @method DateTime|null getStartedAt()
 * @method void setStartedAt(?DateTime $startedAt)
 * @method string|null getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class TimeTrackerLink extends Entity implements JsonSerializable {

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
	 * The entry kind discriminator: `client`, `task` or `time`.
	 *
	 * @var string|null
	 */
	protected ?string $entryType = null;

	/**
	 * The NC TimeManager client uuid (set for `client` entries; the
	 * owning client for `task`/`time` entries when known).
	 *
	 * @var string|null
	 */
	protected ?string $clientId = null;

	/**
	 * The NC TimeManager task uuid (set for `task` entries).
	 *
	 * @var string|null
	 */
	protected ?string $taskId = null;

	/**
	 * The NC TimeManager time-entry uuid (set for `time` entries).
	 *
	 * @var string|null
	 */
	protected ?string $timeId = null;

	/**
	 * The entry display name (cached at link time).
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * The cached duration in seconds.
	 *
	 * @var integer|null
	 */
	protected ?int $duration = null;

	/**
	 * The cached billable flag.
	 *
	 * @var boolean|null
	 */
	protected ?bool $billable = null;

	/**
	 * The cached entry start timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $startedAt = null;

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
	public function __construct() {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'entryType', type: 'string');
		$this->addType(fieldName: 'clientId', type: 'string');
		$this->addType(fieldName: 'taskId', type: 'string');
		$this->addType(fieldName: 'timeId', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'duration', type: 'integer');
		$this->addType(fieldName: 'billable', type: 'boolean');
		$this->addType(fieldName: 'startedAt', type: 'datetime');
		$this->addType(fieldName: 'linkedBy', type: 'string');
		$this->addType(fieldName: 'linkedAt', type: 'datetime');
	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'entryType' => $this->entryType,
			'clientId' => $this->clientId,
			'taskId' => $this->taskId,
			'timeId' => $this->timeId,
			'name' => $this->name,
			'duration' => $this->duration,
			'billable' => $this->billable,
			'startedAt' => $this->startedAt?->format(DateTime::ATOM),
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
