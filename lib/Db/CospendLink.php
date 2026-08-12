<?php

/**
 * CospendLink entity for linking NC Cospend (Costs) projects + bills to
 * OpenRegister objects.
 *
 * Tier-2 schema: carries register_id, schema_id, entry_type
 * (`project`|`bill`), project_id, bill_id, name, amount and currency so
 * the link row alone can hydrate the sidebar tab + picker UX without a
 * per-entry roundtrip to NC Cospend. Replaces the Tier-1
 * `CospendProvider`'s `[or:{objectUuid}]` marker-in-name convention with a
 * proper persistence layer that survives project renames + bill edits.
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
 * Class CospendLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string getEntryType()
 * @method void setEntryType(string $entryType)
 * @method string getProjectId()
 * @method void setProjectId(string $projectId)
 * @method int|null getBillId()
 * @method void setBillId(?int $billId)
 * @method string getName()
 * @method void setName(string $name)
 * @method float|null getAmount()
 * @method void setAmount(?float $amount)
 * @method string|null getCurrency()
 * @method void setCurrency(?string $currency)
 * @method string|null getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class CospendLink extends Entity implements JsonSerializable {

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
	 * The entry type — `project` or `bill`.
	 *
	 * @var string|null
	 */
	protected ?string $entryType = null;

	/**
	 * The NC Cospend project id (primary key in `cospend_projects`).
	 *
	 * @var string|null
	 */
	protected ?string $projectId = null;

	/**
	 * The NC Cospend bill id (primary key in `cospend_bills`); null for
	 * project rows.
	 *
	 * @var integer|null
	 */
	protected ?int $billId = null;

	/**
	 * The cached display name (project name or bill `what`).
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * The cached bill amount (null for projects).
	 *
	 * @var float|null
	 */
	protected ?float $amount = null;

	/**
	 * The cached currency name (owned by the project).
	 *
	 * @var string|null
	 */
	protected ?string $currency = null;

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
		$this->addType(fieldName: 'projectId', type: 'string');
		$this->addType(fieldName: 'billId', type: 'integer');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'amount', type: 'float');
		$this->addType(fieldName: 'currency', type: 'string');
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
			'projectId' => $this->projectId,
			'billId' => $this->billId,
			'name' => $this->name,
			'amount' => $this->amount,
			'currency' => $this->currency,
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
