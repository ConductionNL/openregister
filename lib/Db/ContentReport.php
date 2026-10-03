<?php

/**
 * A report of content, with the copy taken when the report was filed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One piece of content somebody reported, and the evidence of what it said.
 *
 * ⚠️ THE COPY IS TAKEN WHEN THE REPORT IS FILED, NOT WHEN THE REMOVAL RUNS
 * (D-5). A copy made at deletion time races the deletion, and the race is not
 * theoretical: the reason content gets removed quickly is usually the reason
 * somebody wanted the evidence. Filing the report is also the moment somebody
 * first believed the content mattered, so it is the honest instant to freeze.
 *
 * ⚠️ THE COPY IS NOT THE OBJECT. It is a frozen snapshot with its own
 * retention, deliberately longer than the content's own: removing the content
 * must not destroy the evidence, which is the whole requirement. That means
 * this row survives the object it describes, so it stores the object's uuid
 * rather than a foreign key nothing can resolve afterwards.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method string|null getReportedBy()
 * @method void setReportedBy(?string $reportedBy)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method array|null getCopy()
 * @method void setCopy(?array $copy)
 * @method string|null getCopyHash()
 * @method void setCopyHash(?string $copyHash)
 * @method string|null getReviewerGroup()
 * @method void setReviewerGroup(?string $reviewerGroup)
 * @method string|null getRetentionPeriod()
 * @method void setRetentionPeriod(?string $retentionPeriod)
 * @method DateTime|null getExpires()
 * @method void setExpires(?DateTime $expires)
 * @method DateTime|null getRemovedAt()
 * @method void setRemovedAt(?DateTime $removedAt)
 * @method string|null getRemovalAudit()
 * @method void setRemovalAudit(?string $removalAudit)
 * @method string|null getOrganisationId()
 * @method void setOrganisationId(?string $organisationId)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @SuppressWarnings(PHPMD.TooManyFields) A report carries the copy, its checksum, its own retention and
 *   the removal that names it; each is a column the requirement asks for, and splitting them would put
 *   the evidence and the record of its removal in different tables.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class ContentReport extends Entity implements JsonSerializable {
	/**
	 * Filed and waiting for a reviewer.
	 *
	 * @var string
	 */
	public const STATUS_OPEN = 'open';

	/**
	 * A reviewer agreed with the report.
	 *
	 * @var string
	 */
	public const STATUS_UPHELD = 'upheld';

	/**
	 * A reviewer disagreed with the report.
	 *
	 * @var string
	 */
	public const STATUS_DISMISSED = 'dismissed';

	/**
	 * The review vocabulary.
	 *
	 * @var string[]
	 */
	public const STATUS_VOCABULARY = [self::STATUS_OPEN, self::STATUS_UPHELD, self::STATUS_DISMISSED];

	/**
	 * Stable identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The uuid of the object reported. Kept as a uuid rather than a foreign
	 * key, because this row outlives the object by design.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The register the content lived in, when the report was filed.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * The schema the content followed, when the report was filed.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * Why it was reported, in the reporter's words.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * The uid of whoever filed the report.
	 *
	 * @var string|null
	 */
	protected ?string $reportedBy = null;

	/**
	 * Where the review stands.
	 *
	 * @var string|null
	 */
	protected ?string $status = self::STATUS_OPEN;

	/**
	 * The frozen content, exactly as it read when the report was filed.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $copy = null;

	/**
	 * A SHA-256 over the copy, so a reviewer can tell an intact copy from an
	 * edited one. The copy is not hash-chained the way the audit trail is; this
	 * is a checksum, and it claims no more than that.
	 *
	 * @var string|null
	 */
	protected ?string $copyHash = null;

	/**
	 * The group whose members may read this copy, as it stood when the report
	 * was filed. Stored rather than read from configuration at review time, so
	 * changing the configured group does not silently widen access to copies
	 * already taken.
	 *
	 * @var string|null
	 */
	protected ?string $reviewerGroup = null;

	/**
	 * The retention token this copy's expiry came from, so a later purge is
	 * explainable from the row itself.
	 *
	 * @var string|null
	 */
	protected ?string $retentionPeriod = null;

	/**
	 * When this copy may be destroyed. Its OWN retention, deliberately not the
	 * content's: the copy exists to survive the content.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expires = null;

	/**
	 * When the reported content was removed, if it has been.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $removedAt = null;

	/**
	 * The uuid of the audit entry that recorded the removal, so the removal and
	 * the copy name each other from both ends.
	 *
	 * @var string|null
	 */
	protected ?string $removalAudit = null;

	/**
	 * Owning organisation, when the instance is multi-tenant.
	 *
	 * @var string|null
	 */
	protected ?string $organisationId = null;

	/**
	 * When the report was filed, which is also when the copy was taken.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Last change time.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Register the entity's typed columns.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'reportedBy', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'copy', type: 'json');
		$this->addType(fieldName: 'copyHash', type: 'string');
		$this->addType(fieldName: 'reviewerGroup', type: 'string');
		$this->addType(fieldName: 'retentionPeriod', type: 'string');
		$this->addType(fieldName: 'expires', type: 'datetime');
		$this->addType(fieldName: 'removedAt', type: 'datetime');
		$this->addType(fieldName: 'removalAudit', type: 'string');
		$this->addType(fieldName: 'organisationId', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
	}//end __construct()

	/**
	 * Whether the supplied status string is in the review vocabulary.
	 *
	 * @param string|null $status Candidate status string.
	 *
	 * @return bool True when the status is one this entity recognises.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public static function isValidStatus(?string $status): bool {
		if ($status === null || $status === '') {
			return false;
		}

		return in_array(needle: $status, haystack: self::STATUS_VOCABULARY, strict: true);
	}//end isValidStatus()

	/**
	 * Whether the reported content has since been removed.
	 *
	 * @return bool True when a removal has been recorded against this report.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function isRemoved(): bool {
		return $this->removedAt !== null;
	}//end isRemoved()

	/**
	 * Whether the stored copy still matches its checksum.
	 *
	 * @return bool True when the copy hashes to what was recorded.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function copyIsIntact(): bool {
		if ($this->copyHash === null || $this->copyHash === '') {
			return false;
		}

		return hash_equals($this->copyHash, self::hashCopy(copy: ($this->copy ?? [])));
	}//end copyIsIntact()

	/**
	 * The checksum over a copy.
	 *
	 * @param array<string, mixed> $copy The frozen content.
	 *
	 * @return string The SHA-256 hex digest.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public static function hashCopy(array $copy): string {
		return hash('sha256', (string)json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end hashCopy()

	/**
	 * Render the report as JSON, WITHOUT the copy.
	 *
	 * ⚠️ THE COPY IS NOT IN HERE, AND THAT IS THE ACCESS CONTROL. The report
	 * itself says what was reported and by whom; the content it froze is the
	 * part only a reviewer may read, and it is served by its own endpoint
	 * behind its own check. A copy added to this method would leak through
	 * every list that has ever serialised a report, which is exactly the shape
	 * of accident this comment exists to prevent.
	 *
	 * @return array<string, mixed> The serialized report.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'reason' => $this->reason,
			'reportedBy' => $this->reportedBy,
			'status' => $this->status,
			'copyHash' => $this->copyHash,
			'copyIntact' => $this->copyIsIntact(),
			'reviewerGroup' => $this->reviewerGroup,
			'retentionPeriod' => $this->retentionPeriod,
			'expires' => $this->expires?->format('c'),
			'removed' => $this->isRemoved(),
			'removedAt' => $this->removedAt?->format('c'),
			'removalAudit' => $this->removalAudit,
			'organisationId' => $this->organisationId,
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
		];
	}//end jsonSerialize()
}//end class
