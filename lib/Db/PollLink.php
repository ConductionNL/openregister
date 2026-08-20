<?php

/**
 * PollLink entity for linking Nextcloud Polls polls to OpenRegister objects.
 *
 * Tier-2 schema: carries register_id, schema_id, poll_title, poll_type,
 * deadline, voter_count, option_count, closed so the link row alone can
 * hydrate the sidebar tab + picker UX without a per-poll roundtrip to
 * Polls. Replaces the original PollsProvider's title-marker convention
 * (`[or:{uuid}]` embedded in the poll title) with a proper persistence
 * layer.
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
 * Class PollLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getPollId()
 * @method void setPollId(int $pollId)
 * @method string|null getPollTitle()
 * @method void setPollTitle(?string $pollTitle)
 * @method string|null getPollType()
 * @method void setPollType(?string $pollType)
 * @method DateTime|null getDeadline()
 * @method void setDeadline(?DateTime $deadline)
 * @method int|null getVoterCount()
 * @method void setVoterCount(?int $voterCount)
 * @method int|null getOptionCount()
 * @method void setOptionCount(?int $optionCount)
 * @method bool getClosed()
 * @method void setClosed(bool $closed)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class PollLink extends Entity implements JsonSerializable {

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
	 * The Polls poll id (primary key in `oc_polls_polls`).
	 *
	 * @var integer|null
	 */
	protected ?int $pollId = null;

	/**
	 * The poll title (cached at link time).
	 *
	 * @var string|null
	 */
	protected ?string $pollTitle = null;

	/**
	 * The poll type (e.g. `textPoll` / `datePoll`).
	 *
	 * @var string|null
	 */
	protected ?string $pollType = null;

	/**
	 * The poll deadline (cached at link time).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $deadline = null;

	/**
	 * Cached voter count (distinct users who voted at least once).
	 *
	 * @var integer|null
	 */
	protected ?int $voterCount = null;

	/**
	 * Cached option count.
	 *
	 * @var integer|null
	 */
	protected ?int $optionCount = null;

	/**
	 * Whether the poll is closed (deadline elapsed).
	 *
	 * @var boolean|null
	 */
	protected ?bool $closed = false;

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
		$this->addType(fieldName: 'pollId', type: 'integer');
		$this->addType(fieldName: 'pollTitle', type: 'string');
		$this->addType(fieldName: 'pollType', type: 'string');
		$this->addType(fieldName: 'deadline', type: 'datetime');
		$this->addType(fieldName: 'voterCount', type: 'integer');
		$this->addType(fieldName: 'optionCount', type: 'integer');
		$this->addType(fieldName: 'closed', type: 'boolean');
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
			'pollId' => $this->pollId,
			'pollTitle' => $this->pollTitle,
			'pollType' => $this->pollType,
			'deadline' => $this->deadline?->format(DateTime::ATOM),
			'voterCount' => $this->voterCount,
			'optionCount' => $this->optionCount,
			'closed' => (bool)$this->closed,
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
