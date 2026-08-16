<?php

/**
 * DeckLink entity for linking Nextcloud Deck cards to OpenRegister objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Tier-2 schema: also carries schema_id, due_date, labels, assignees so the
 * link row alone can hydrate the sidebar tab + picker UX without a per-card
 * roundtrip to Deck's CardService.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Class DeckLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getStackId()
 * @method void setStackId(int $stackId)
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method string|null getCardTitle()
 * @method void setCardTitle(?string $cardTitle)
 * @method DateTime|null getDueDate()
 * @method void setDueDate(?DateTime $dueDate)
 * @method string|null getLabels()
 * @method void setLabels(?string $labels)
 * @method string|null getAssignees()
 * @method void setAssignees(?string $assignees)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class DeckLink extends Entity implements JsonSerializable {

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
	 * The board id.
	 *
	 * @var integer|null
	 */
	protected ?int $boardId = null;

	/**
	 * The stack id.
	 *
	 * @var integer|null
	 */
	protected ?int $stackId = null;

	/**
	 * The card id.
	 *
	 * @var integer|null
	 */
	protected ?int $cardId = null;

	/**
	 * The card title.
	 *
	 * @var string|null
	 */
	protected ?string $cardTitle = null;

	/**
	 * The card due date.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $dueDate = null;

	/**
	 * JSON-encoded labels payload (cached at link time so the sidebar tab
	 * can render without a fresh Deck roundtrip). Schema:
	 *   [ {id, title, color}, ... ]
	 *
	 * @var string|null
	 */
	protected ?string $labels = null;

	/**
	 * JSON-encoded assignees payload. Schema:
	 *   [ {uid, type, displayName}, ... ]
	 *
	 * @var string|null
	 */
	protected ?string $assignees = null;

	/**
	 * The linked by.
	 *
	 * @var string|null
	 */
	protected ?string $linkedBy = null;

	/**
	 * The linked at.
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
		$this->addType(fieldName: 'boardId', type: 'integer');
		$this->addType(fieldName: 'stackId', type: 'integer');
		$this->addType(fieldName: 'cardId', type: 'integer');
		$this->addType(fieldName: 'cardTitle', type: 'string');
		$this->addType(fieldName: 'dueDate', type: 'datetime');
		$this->addType(fieldName: 'labels', type: 'string');
		$this->addType(fieldName: 'assignees', type: 'string');
		$this->addType(fieldName: 'linkedBy', type: 'string');
		$this->addType(fieldName: 'linkedAt', type: 'datetime');
	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * Decodes the JSON `labels` / `assignees` columns into arrays so the
	 * leaf row is directly consumable by the sidebar tab and picker UX.
	 * Always returns arrays for those keys even on malformed JSON.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		$labels = [];
		$assignees = [];

		if ($this->labels !== null && $this->labels !== '') {
			$decoded = json_decode($this->labels, true);
			if (is_array($decoded) === true) {
				$labels = $decoded;
			}
		}

		if ($this->assignees !== null && $this->assignees !== '') {
			$decoded = json_decode($this->assignees, true);
			if (is_array($decoded) === true) {
				$assignees = $decoded;
			}
		}

		return [
			'id' => $this->id,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'boardId' => $this->boardId,
			'stackId' => $this->stackId,
			'cardId' => $this->cardId,
			'cardTitle' => $this->cardTitle,
			'dueDate' => $this->dueDate?->format(DateTime::ATOM),
			'labels' => $labels,
			'assignees' => $assignees,
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
