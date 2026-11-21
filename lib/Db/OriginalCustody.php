<?php

/**
 * OpenRegister OriginalCustody
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * The custody of an unredacted original whose redacted copy was published:
 * the retention applied, the deletion date, and what the retention job did.
 * A held original stays scheduled with the reason it was last held.
 *
 * @method int getOriginalFileId()
 * @method void setOriginalFileId(int $originalFileId)
 * @method int getCopyFileId()
 * @method void setCopyFileId(int $copyFileId)
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string getRetention()
 * @method void setRetention(string $retention)
 * @method DateTime|null getPublishedAt()
 * @method void setPublishedAt(DateTime $publishedAt)
 * @method DateTime|null getDeleteAfter()
 * @method void setDeleteAfter(?DateTime $deleteAfter)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getOutcomeAt()
 * @method void setOutcomeAt(?DateTime $outcomeAt)
 * @method string|null getOutcomeReason()
 * @method void setOutcomeReason(?string $outcomeReason)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */
class OriginalCustody extends Entity implements JsonSerializable {

	/**
	 * The original waits for its deletion date.
	 */
	public const STATUS_SCHEDULED = 'scheduled';

	/**
	 * The retention job deleted the original.
	 */
	public const STATUS_DELETED = 'deleted';

	/**
	 * The original was already gone when the job came for it.
	 */
	public const STATUS_GONE = 'gone';

	/**
	 * The unredacted original.
	 *
	 * @var integer
	 */
	protected int $originalFileId = 0;

	/**
	 * The published redacted copy.
	 *
	 * @var integer
	 */
	protected int $copyFileId = 0;

	/**
	 * The object both files belong to, whose holds and nomination the job asks.
	 *
	 * @var string
	 */
	protected string $objectUuid = '';

	/**
	 * The retention applied: an ISO 8601 duration.
	 *
	 * @var string
	 */
	protected string $retention = '';

	/**
	 * When the copy was published.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $publishedAt = null;

	/**
	 * When the original may be deleted.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $deleteAfter = null;

	/**
	 * One of the STATUS_ constants.
	 *
	 * @var string
	 */
	protected string $status = self::STATUS_SCHEDULED;

	/**
	 * When the job last acted on this row.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $outcomeAt = null;

	/**
	 * Why the job held the original (`legal-hold`, `nomination-keep`, `object-unreadable`).
	 *
	 * @var string|null
	 */
	protected ?string $outcomeReason = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'originalFileId', type: 'integer');
		$this->addType(fieldName: 'copyFileId', type: 'integer');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'retention', type: 'string');
		$this->addType(fieldName: 'publishedAt', type: 'datetime');
		$this->addType(fieldName: 'deleteAfter', type: 'datetime');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'outcomeAt', type: 'datetime');
		$this->addType(fieldName: 'outcomeReason', type: 'string');
	}//end __construct()

	/**
	 * The row as JSON.
	 *
	 * @return array<string, int|string|null>
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'originalFileId' => $this->originalFileId,
			'copyFileId' => $this->copyFileId,
			'objectUuid' => $this->objectUuid,
			'retention' => $this->retention,
			'publishedAt' => $this->publishedAt?->format(DATE_ATOM),
			'deleteAfter' => $this->deleteAfter?->format(DATE_ATOM),
			'status' => $this->status,
			'outcomeAt' => $this->outcomeAt?->format(DATE_ATOM),
			'outcomeReason' => $this->outcomeReason,
		];
	}//end jsonSerialize()
}//end class
