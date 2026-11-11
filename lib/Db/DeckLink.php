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
	use ObjectLinkTypesTrait;
	use TypedFieldsJsonTrait;

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
		$this->addObjectLinkTypes();
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
	 * The serialised values that differ from the stored ones, by field name.
	 *
	 * @return array<string, mixed>
	 */
	protected function jsonOverrides(): array {
		return [
			'labels' => $this->decodeList(json: $this->labels),
			'assignees' => $this->decodeList(json: $this->assignees),
		];
	}//end jsonOverrides()

	/**
	 * Decode a JSON column into an array, or an empty array when it is empty or not a JSON array/object.
	 *
	 * @param string|null $json The stored JSON.
	 *
	 * @return array<mixed> The decoded value.
	 */
	private function decodeList(?string $json): array {
		if (in_array($json, [null, ''], true) === true) {
			return [];
		}

		$decoded = json_decode($json, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decodeList()
}//end class
