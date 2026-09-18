<?php

/**
 * ErasurePreview entity — the recorded answer an erasure runs from.
 *
 * A preview is a reading of the world at a moment, and an erasure is
 * irreversible. Keeping the reading as a row, with the digest of what it said
 * and who approved it, is what lets the run refuse two different ways: it was
 * never approved, or it was approved for a world that has since moved.
 *
 * The report column holds the preview verbatim — counts, items and named
 * protected records — because the sentence a handler sends the data subject is
 * written from it, and re-deriving it later would answer about a different day.
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
 * Class ErasurePreview
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getSubjectType()
 * @method void setSubjectType(?string $subjectType)
 * @method string|null getEraseMode()
 * @method void setEraseMode(?string $eraseMode)
 * @method string|null getRequestId()
 * @method void setRequestId(?string $requestId)
 * @method string|null getDigest()
 * @method void setDigest(?string $digest)
 * @method array|null getReport()
 * @method void setReport(?array $report)
 * @method array|null getOutcome()
 * @method void setOutcome(?array $outcome)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method string|null getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method DateTime|null getApprovedAt()
 * @method void setApprovedAt(?DateTime $approvedAt)
 * @method DateTime|null getConsumedAt()
 * @method void setConsumedAt(?DateTime $consumedAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ErasurePreview extends Entity implements JsonSerializable {
	/**
	 * Recorded, not yet approved. An erasure refuses on it.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Approved. An erasure may run from it, once.
	 *
	 * @var string
	 */
	public const STATUS_APPROVED = 'approved';

	/**
	 * Already run. A second run refuses rather than erasing twice.
	 *
	 * @var string
	 */
	public const STATUS_CONSUMED = 'consumed';

	/**
	 * Stable UUID, the identifier the API speaks in.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The data subject's identifier value.
	 *
	 * @var string|null
	 */
	protected ?string $subject = null;

	/**
	 * Optional PII type the preview was narrowed to.
	 *
	 * @var string|null
	 */
	protected ?string $subjectType = null;

	/**
	 * The erase mode the preview was computed for.
	 *
	 * @var string|null
	 */
	protected ?string $eraseMode = null;

	/**
	 * The data subject request this preview answers, when there is one.
	 *
	 * @var string|null
	 */
	protected ?string $requestId = null;

	/**
	 * The digest of what the preview said.
	 *
	 * @var string|null
	 */
	protected ?string $digest = null;

	/**
	 * The preview verbatim: counts, items, named protected records.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $report = null;

	/**
	 * What the run actually did, written when the preview is consumed.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $outcome = null;

	/**
	 * Lifecycle status.
	 *
	 * @var string|null
	 */
	protected ?string $status = self::STATUS_PENDING;

	/**
	 * Who asked for the preview.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * Who approved it.
	 *
	 * @var string|null
	 */
	protected ?string $approvedBy = null;

	/**
	 * When it was approved.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $approvedAt = null;

	/**
	 * When the erasure ran from it.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $consumedAt = null;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Last-update timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'subject', type: 'string');
		$this->addType(fieldName: 'subjectType', type: 'string');
		$this->addType(fieldName: 'eraseMode', type: 'string');
		$this->addType(fieldName: 'requestId', type: 'string');
		$this->addType(fieldName: 'digest', type: 'string');
		$this->addType(fieldName: 'report', type: 'json');
		$this->addType(fieldName: 'outcome', type: 'json');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'approvedBy', type: 'string');
		$this->addType(fieldName: 'approvedAt', type: 'datetime');
		$this->addType(fieldName: 'consumedAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * Hydrate the entity from an array.
	 *
	 * @param array<string, mixed> $object The source data.
	 *
	 * @return static This entity, hydrated.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function hydrate(array $object): static {
		foreach ($object as $key => $value) {
			$method = 'set' . ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Silently ignore invalid properties.
			}
		}

		return $this;
	}//end hydrate()

	/**
	 * Whether an erasure may run from this preview.
	 *
	 * @return bool True only while the preview is approved and unconsumed.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function isRunnable(): bool {
		return ($this->status === self::STATUS_APPROVED && $this->consumedAt === null);
	}//end isRunnable()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised preview.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'subject' => $this->subject,
			'subjectType' => $this->subjectType,
			'eraseMode' => $this->eraseMode,
			'requestId' => $this->requestId,
			'digest' => $this->digest,
			'report' => ($this->report ?? []),
			'outcome' => ($this->outcome ?? []),
			'status' => $this->status,
			'createdBy' => $this->createdBy,
			'approvedBy' => $this->approvedBy,
			'approvedAt' => $this->approvedAt?->format(DateTime::ATOM),
			'consumedAt' => $this->consumedAt?->format(DateTime::ATOM),
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
