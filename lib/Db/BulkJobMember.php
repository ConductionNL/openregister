<?php

/**
 * BulkJobMember entity — one object inside a bulk job, and what happened to it.
 *
 * A member row is written once at preview and updated once at commit, so the
 * same row carries both the rehearsal and the result. It is also what makes a
 * retry safe: a member already marked applied is never acted on twice (D-5).
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
 * Class BulkJobMember
 *
 * @method int|null getJobId()
 * @method void setJobId(?int $jobId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getOutcome()
 * @method void setOutcome(?string $outcome)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method string|null getSchemaVersion()
 * @method void setSchemaVersion(?string $schemaVersion)
 * @method bool getAddedAtCommit()
 * @method void setAddedAtCommit(bool $addedAtCommit)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class BulkJobMember extends Entity implements JsonSerializable {

	/**
	 * The action has not reached this member yet.
	 *
	 * @var string
	 */
	public const OUTCOME_PENDING = 'pending';

	/**
	 * The action would apply, or did apply, to this member.
	 *
	 * @var string
	 */
	public const OUTCOME_APPLIED = 'applied';

	/**
	 * The action does not apply to this member, and says why (D-3).
	 *
	 * @var string
	 */
	public const OUTCOME_SKIPPED = 'skipped';

	/**
	 * The actor may not write this member, and the rule that refused it is
	 * named. A refusal is never reported as a skip (D-3).
	 *
	 * @var string
	 */
	public const OUTCOME_REFUSED = 'refused';

	/**
	 * The write threw, and the message is kept.
	 *
	 * @var string
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * The commit stopped before reaching this member (D-4).
	 *
	 * @var string
	 */
	public const OUTCOME_CANCELLED = 'cancelled';

	/**
	 * The job this member belongs to.
	 *
	 * @var integer|null
	 */
	protected ?int $jobId = null;

	/**
	 * The object's uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The outcome for this object.
	 *
	 * @var string|null
	 */
	protected ?string $outcome = null;

	/**
	 * Why the outcome is what it is: the skip reason, the rule that refused,
	 * or the failure message.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * The schema version the object carried at preview, for the homogeneity
	 * guard (D-6).
	 *
	 * @var string|null
	 */
	protected ?string $schemaVersion = null;

	/**
	 * True when the member appeared only at commit, because a query-backed
	 * selection grew between preview and commit (D-2).
	 *
	 * @var boolean
	 */
	protected bool $addedAtCommit = false;

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
		$this->addType(fieldName: 'jobId', type: 'integer');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'outcome', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'schemaVersion', type: 'string');
		$this->addType(fieldName: 'addedAtCommit', type: 'boolean');
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
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function hydrate(array $object): static {
		foreach ($object as $key => $value) {
			$method = 'set'.ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Silently ignore invalid properties.
			}
		}

		return $this;
	}//end hydrate()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised member.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'jobId' => $this->jobId,
			'objectUuid' => $this->objectUuid,
			'outcome' => $this->outcome,
			'reason' => $this->reason,
			'schemaVersion' => $this->schemaVersion,
			'addedAtCommit' => $this->addedAtCommit,
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
