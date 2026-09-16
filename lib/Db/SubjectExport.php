<?php

/**
 * SubjectExport entity — one data subject's request for their own copy.
 *
 * The row is the request and its lifecycle, not the file. Assembling
 * everything an instance holds about a person produces the most sensitive
 * bytes it will ever produce, so those bytes are re-assembled under the
 * requester's own scope at download time and never written to rest. What the
 * row keeps is what somebody has to be able to answer later: who asked, about
 * whom, when it became ready, what it contained (a count and a hash) and when
 * the delivery stops working.
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
 * Class SubjectExport
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getSubjectType()
 * @method void setSubjectType(?string $subjectType)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getRequestedBy()
 * @method void setRequestedBy(?string $requestedBy)
 * @method string|null getRequestId()
 * @method void setRequestId(?string $requestId)
 * @method int getObjectCount()
 * @method void setObjectCount(int $objectCount)
 * @method string|null getContentHash()
 * @method void setContentHash(?string $contentHash)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method DateTime|null getReadyAt()
 * @method void setReadyAt(?DateTime $readyAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method DateTime|null getDeliveredAt()
 * @method void setDeliveredAt(?DateTime $deliveredAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class SubjectExport extends Entity implements JsonSerializable {
	/**
	 * Requested, waiting for the job to pick it up.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Assembled and downloadable until its expiry.
	 *
	 * @var string
	 */
	public const STATUS_READY = 'ready';

	/**
	 * The job could not assemble it, and `error` says why.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * How long a ready export stays downloadable, in seconds.
	 *
	 * Seven days: long enough for a person to act on an email, short enough
	 * that the most sensitive answer the instance produces does not stay
	 * reachable for a quarter because nobody came back to it.
	 *
	 * @var int
	 */
	public const DEFAULT_TTL_SECONDS = 604800;

	/**
	 * Stable UUID, the identifier the API speaks in.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The data subject the export is about.
	 *
	 * @var string|null
	 */
	protected ?string $subject = null;

	/**
	 * Optional PII type the export was narrowed to.
	 *
	 * @var string|null
	 */
	protected ?string $subjectType = null;

	/**
	 * Lifecycle status.
	 *
	 * @var string|null
	 */
	protected ?string $status = self::STATUS_PENDING;

	/**
	 * Who asked. A handler acting for the subject, or the subject themselves.
	 *
	 * @var string|null
	 */
	protected ?string $requestedBy = null;

	/**
	 * The data subject request this export answers, when there is one.
	 *
	 * @var string|null
	 */
	protected ?string $requestId = null;

	/**
	 * How many objects the assembled export held.
	 *
	 * @var int
	 */
	protected int $objectCount = 0;

	/**
	 * The hash of what was assembled, so a delivered copy can be recognised.
	 *
	 * @var string|null
	 */
	protected ?string $contentHash = null;

	/**
	 * Why the assembly failed, when it did.
	 *
	 * @var string|null
	 */
	protected ?string $error = null;

	/**
	 * When it became downloadable.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $readyAt = null;

	/**
	 * When the delivery stops working.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * When it was last handed over.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $deliveredAt = null;

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
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'requestedBy', type: 'string');
		$this->addType(fieldName: 'requestId', type: 'string');
		$this->addType(fieldName: 'objectCount', type: 'integer');
		$this->addType(fieldName: 'contentHash', type: 'string');
		$this->addType(fieldName: 'error', type: 'string');
		$this->addType(fieldName: 'readyAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'deliveredAt', type: 'datetime');
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
	 * Whether the delivery still works at this moment.
	 *
	 * Both halves are asked. A ready export past its expiry is refused, and so
	 * is one that never became ready: a link handed out before the job finished
	 * must not serve a half-assembled answer.
	 *
	 * @param DateTime|null $now The moment, or null for the real one.
	 *
	 * @return bool True when it may still be downloaded.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function isDownloadableAt(?DateTime $now = null): bool {
		if ($this->status !== self::STATUS_READY) {
			return false;
		}

		if ($this->expiresAt === null) {
			// An export with no expiry is an export that never stops being
			// reachable. Fail closed: the whole point is that it ends.
			return false;
		}

		return ($this->expiresAt > ($now ?? new DateTime()));
	}//end isDownloadableAt()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised export request.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'subject' => $this->subject,
			'subjectType' => $this->subjectType,
			'status' => $this->status,
			'requestedBy' => $this->requestedBy,
			'requestId' => $this->requestId,
			'objectCount' => $this->objectCount,
			'contentHash' => $this->contentHash,
			'error' => $this->error,
			'readyAt' => $this->readyAt?->format(DateTime::ATOM),
			'expiresAt' => $this->expiresAt?->format(DateTime::ATOM),
			'deliveredAt' => $this->deliveredAt?->format(DateTime::ATOM),
			'downloadable' => $this->isDownloadableAt(),
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
