<?php

/**
 * NotificationDedupeState entity for per-object scheduled-notification dedup.
 *
 * One row per (schema_id, rule_key, object_uuid) capturing the dispatch
 * fingerprint that the scheduled-notification engine uses to decide whether
 * a fresh dispatch is needed. When the watched-field fingerprint matches
 * the stored value the object is silently re-seen (touching `seen_at`);
 * when it differs the engine re-arms by dispatching and rewriting the row.
 *
 * Backs the notification-engine-scheduled-conditions Phase 3 dedup loop —
 * see `lib/BackgroundJob/ScheduledNotificationJob`.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * NotificationDedupeState.
 *
 * @method int getSchemaId()
 * @method void setSchemaId(int $schemaId)
 * @method string getRuleKey()
 * @method void setRuleKey(string $ruleKey)
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string getFingerprint()
 * @method void setFingerprint(string $fingerprint)
 * @method DateTime getDispatchedAt()
 * @method void setDispatchedAt(DateTime $dispatchedAt)
 * @method DateTime getSeenAt()
 * @method void setSeenAt(DateTime $seenAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class NotificationDedupeState extends Entity implements JsonSerializable {

	/**
	 * Owning schema id.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * Notification annotation key (rule key in `x-openregister-notifications`).
	 *
	 * @var string|null
	 */
	protected ?string $ruleKey = null;

	/**
	 * Target ObjectEntity UUID.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * SHA-1 fingerprint of the watched-field values at last dispatch.
	 *
	 * @var string|null
	 */
	protected ?string $fingerprint = null;

	/**
	 * Timestamp the rule was dispatched for this object (last re-arm).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $dispatchedAt = null;

	/**
	 * Last time the evaluator matched this (schema, rule, object) triple.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $seenAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'ruleKey', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'fingerprint', type: 'string');
		$this->addType(fieldName: 'dispatchedAt', type: 'datetime');
		$this->addType(fieldName: 'seenAt', type: 'datetime');

	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'schemaId' => $this->schemaId,
			'ruleKey' => $this->ruleKey,
			'objectUuid' => $this->objectUuid,
			'fingerprint' => $this->fingerprint,
			'dispatchedAt' => $this->dispatchedAt?->format(DateTime::ATOM),
			'seenAt' => $this->seenAt?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
