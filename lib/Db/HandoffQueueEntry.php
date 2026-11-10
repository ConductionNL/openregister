<?php

/**
 * OpenRegister Handoff Queue Entry Entity
 *
 * Durable record of a handoff request parked because no installed schema
 * implemented the target kind (`whenUnavailable: queue`, ADR-051). Mirrors
 * the `WebhookLog` durable-retry pattern: append-only attempt semantics with
 * an `attempt` counter, timestamps, a status field and a last-error column.
 *
 * Status values: `parked` (waiting for a provider), `executed` (drained
 * successfully), `failed-permission` (requester lost create permission at
 * drain time), `failed-validation` (target schema rejected the mapped
 * object), `cancelled` (withdrawn).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCA\OpenRegister\Db\HandoffQueueEntry\HandoffQueueEntryFieldTypes;
use OCP\AppFramework\Db\Entity;

/**
 * HandoffQueueEntry entity — one parked (or drained) queue-mode handoff.
 *
 * @method string getSourceObjectUuid()
 * @method void setSourceObjectUuid(string $sourceObjectUuid)
 * @method int getSourceRegister()
 * @method void setSourceRegister(int $sourceRegister)
 * @method int getSourceSchema()
 * @method void setSourceSchema(int $sourceSchema)
 * @method string getHandoffId()
 * @method void setHandoffId(string $handoffId)
 * @method string getTargetKind()
 * @method void setTargetKind(string $targetKind)
 * @method string getRequestingUser()
 * @method void setRequestingUser(string $requestingUser)
 * @method string getCorrelationId()
 * @method void setCorrelationId(string $correlationId)
 * @method string|null getMappingHash()
 * @method void setMappingHash(?string $mappingHash)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getAttempt()
 * @method void setAttempt(int $attempt)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method DateTime getCreated()
 * @method void setCreated(DateTime $created)
 * @method DateTime|null getLastAttemptAt()
 * @method void setLastAttemptAt(?DateTime $lastAttemptAt)
 * @method DateTime|null getExecutedAt()
 * @method void setExecutedAt(?DateTime $executedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Scenario: No provider installed, queue mode)
 */
class HandoffQueueEntry extends Entity implements JsonSerializable {

	/**
	 * Entry is waiting for a providing schema to be installed.
	 *
	 * @var string
	 */
	public const STATUS_PARKED = 'parked';

	/**
	 * Entry was drained successfully (target object created).
	 *
	 * @var string
	 */
	public const STATUS_EXECUTED = 'executed';

	/**
	 * Requester lost create permission at drain time (no escalation).
	 *
	 * @var string
	 */
	public const STATUS_FAILED_PERMISSION = 'failed-permission';

	/**
	 * The mapped object was rejected by the resolved target schema.
	 *
	 * @var string
	 */
	public const STATUS_FAILED_VALIDATION = 'failed-validation';

	/**
	 * Entry withdrawn before a provider appeared.
	 *
	 * @var string
	 */
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * UUID of the source object whose handoff was parked.
	 *
	 * @var string
	 */
	protected string $sourceObjectUuid = '';

	/**
	 * Register id of the source object.
	 *
	 * @var integer
	 */
	protected int $sourceRegister = 0;

	/**
	 * Schema id of the source object.
	 *
	 * @var integer
	 */
	protected int $sourceSchema = 0;

	/**
	 * The declared handoff entry id (dialect `id`).
	 *
	 * @var string
	 */
	protected string $handoffId = '';

	/**
	 * The target kind URI the handoff waits on.
	 *
	 * @var string
	 */
	protected string $targetKind = '';

	/**
	 * The user who requested the handoff; drain re-evaluates RBAC as this
	 * user (never a privilege-escalation time capsule).
	 *
	 * @var string
	 */
	protected string $requestingUser = '';

	/**
	 * Correlation id minted at park time, echoed on audit rows + the event.
	 *
	 * @var string
	 */
	protected string $correlationId = '';

	/**
	 * Hash of the mapping snapshot at park time (drift observability).
	 *
	 * @var string|null
	 */
	protected ?string $mappingHash = null;

	/**
	 * Entry status (see STATUS_* constants).
	 *
	 * @var string
	 */
	protected string $status = self::STATUS_PARKED;

	/**
	 * Drain attempt counter (append-only semantics per the WebhookLog pattern).
	 *
	 * @var integer
	 */
	protected int $attempt = 0;

	/**
	 * Last drain error, when a drain attempt failed.
	 *
	 * @var string|null
	 */
	protected ?string $lastError = null;

	/**
	 * Park timestamp.
	 *
	 * @var DateTime
	 */
	protected DateTime $created;

	/**
	 * Timestamp of the most recent drain attempt.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastAttemptAt = null;

	/**
	 * Timestamp of successful execution (drain).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $executedAt = null;

	/**
	 * Constructor — declares column types and stamps the park timestamp.
	 *
	 * @return void
	 */
	public function __construct() {
		foreach (HandoffQueueEntryFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}

		$this->created = new DateTime();
	}//end __construct()

	/**
	 * JSON serialize the entry (availability endpoint `queued` state).
	 *
	 * @return array<string, mixed> The serialized entry.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Handoff REST surface)
	 */
	public function jsonSerialize(): array {
		$json = (new TypedFieldsJson())->pick(values: get_object_vars($this), names: array_diff(array_keys($this->getFieldTypes()), ['mappingHash']));
		$json['created'] = $this->created->format('c');

		return $json;
	}//end jsonSerialize()
}//end class
