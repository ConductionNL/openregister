<?php

/**
 * NotificationHistory entity for the audit trail of notification dispatches.
 *
 * One row per (rule, channel, recipient) emission. Closes the
 * `notificatie-engine` spec's
 * "Notification history MUST be stored and queryable for audit
 * purposes" requirement.
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
 * NotificationHistory.
 *
 * @method string getRuleId()
 * @method void setRuleId(string $ruleId)
 * @method string|null getSchemaId()
 * @method void setSchemaId(?string $schemaId)
 * @method string|null getRegisterId()
 * @method void setRegisterId(?string $registerId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method string getRecipient()
 * @method void setRecipient(string $recipient)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method string|null getLocale()
 * @method void setLocale(?string $locale)
 * @method DateTime getDispatchedAt()
 * @method void setDispatchedAt(DateTime $dispatchedAt)
 * @method DateTime|null getReadAt()
 * @method void setReadAt(?DateTime $readAt)
 * @method string|null getSubjectType()
 * @method void setSubjectType(?string $subjectType)
 * @method string|null getSubjectId()
 * @method void setSubjectId(?string $subjectId)
 * @method DateTime|null getSnoozedUntil()
 * @method void setSnoozedUntil(?DateTime $snoozedUntil)
 * @method DateTime|null getArchivedAt()
 * @method void setArchivedAt(?DateTime $archivedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One property per column of
 * `openregister_notification_history`. The bell filters and sorts on the
 * subject axis, the snooze and the archive, so those have to be columns a
 * query can reach; folding them into a blob would make the list unfilterable.
 */
class NotificationHistory extends Entity implements JsonSerializable {

	/**
	 * Annotation key (per-schema rule identifier).
	 *
	 * @var string|null
	 */
	protected ?string $ruleId = null;

	/**
	 * Schema the rule lives on.
	 *
	 * @var string|null
	 */
	protected ?string $schemaId = null;

	/**
	 * Register the object lives in.
	 *
	 * @var string|null
	 */
	protected ?string $registerId = null;

	/**
	 * Object the event happened on.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * Channel the notification was emitted on.
	 *
	 * One of `nc-notification`, `email`, `activity`, `webhook`, `talk`.
	 *
	 * @var string|null
	 */
	protected ?string $channel = null;

	/**
	 * Recipient identifier.
	 *
	 * Per-recipient channels: the user's uid. Broadcast channels:
	 * `__webhook__` / `__talk__`.
	 *
	 * @var string|null
	 */
	protected ?string $recipient = null;

	/**
	 * Interpolated subject string actually emitted.
	 *
	 * @var string|null
	 */
	protected ?string $subject = null;

	/**
	 * Status of the dispatch.
	 *
	 * One of `dispatched`, `rate-limited`, `failed`.
	 *
	 * @var string|null
	 */
	protected ?string $status = null;

	/**
	 * Error message — populated when status is `failed`.
	 *
	 * @var string|null
	 */
	protected ?string $errorMessage = null;

	/**
	 * Locale of the recipient (null for broadcast channels).
	 *
	 * @var string|null
	 */
	protected ?string $locale = null;

	/**
	 * Wall-clock timestamp of the dispatch.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $dispatchedAt = null;

	/**
	 * When the recipient read this notice, or null while it is unread.
	 *
	 * Per-user by construction: a history row carries exactly one recipient, so
	 * the row IS the per-user fact and no join is needed to answer "is this
	 * unread for me". Written only by NotificationClearingService, which is also
	 * what clears a notice when the work it asked for is opened.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $readAt = null;

	/**
	 * What the notice is ABOUT, as an axis for the list.
	 *
	 * Distinct from `schema_id`, which says which schema's rule produced it. A
	 * bell with four hundred entries needs to be narrowable to "documents" or
	 * "messages", and those are not schemas.
	 *
	 * @var string|null
	 */
	protected ?string $subjectType = null;

	/**
	 * The identifier of the subject within its type.
	 *
	 * For an object notice this is the object uuid; for a sub-resource notice it
	 * is that sub-resource's own id, which is what lets opening one tab clear
	 * only the notices about that tab.
	 *
	 * @var string|null
	 */
	protected ?string $subjectId = null;

	/**
	 * When a snoozed notice returns to the unread list.
	 *
	 * Absent from the unread list until that moment, and unread afterwards: a
	 * snooze postpones a notice, it never reads it.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $snoozedUntil = null;

	/**
	 * When the notice was taken out of the list without being read.
	 *
	 * Archiving is not reading. The read state is left exactly as it was, so a
	 * notice archived unread still says so.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $archivedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'ruleId', type: 'string');
		$this->addType(fieldName: 'schemaId', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'channel', type: 'string');
		$this->addType(fieldName: 'recipient', type: 'string');
		$this->addType(fieldName: 'subject', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'errorMessage', type: 'string');
		$this->addType(fieldName: 'locale', type: 'string');
		$this->addType(fieldName: 'dispatchedAt', type: 'datetime');
		$this->addType(fieldName: 'readAt', type: 'datetime');
		$this->addType(fieldName: 'subjectType', type: 'string');
		$this->addType(fieldName: 'subjectId', type: 'string');
		$this->addType(fieldName: 'snoozedUntil', type: 'datetime');
		$this->addType(fieldName: 'archivedAt', type: 'datetime');

	}//end __construct()

	/**
	 * Whether this notice belongs in the unread list at a given moment.
	 *
	 * THE SINGLE DEFINITION of the three list-state rules, in the terms the
	 * requirement states them:
	 *
	 *  - a notice that has been read is not unread,
	 *  - an archived notice has left the list without being read,
	 *  - a notice snoozed past this moment is absent now and back afterwards,
	 *    still unread.
	 *
	 * `NotificationHistoryMapper::applyListStateFilters()` is the SQL pushdown
	 * of exactly this predicate, so the list can be paged and counted in the
	 * database rather than in PHP. The two are kept side by side deliberately:
	 * this one is what the rules MEAN and is unit-testable against a clock, and
	 * the mapper's is the same three clauses in the same order. A change to
	 * either without the other is the drift to watch for.
	 *
	 * @param DateTime|null $asOf The moment to judge against, defaulting to now.
	 *
	 * @return boolean True when the notice is in the unread list at that moment.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function isInUnreadListAt(?DateTime $asOf = null): bool {
		if ($this->readAt !== null || $this->archivedAt !== null) {
			return false;
		}

		if ($this->snoozedUntil === null) {
			return true;
		}

		return ($this->snoozedUntil <= ($asOf ?? new DateTime()));

	}//end isInUnreadListAt()

	/**
	 * JSON serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'ruleId' => $this->ruleId,
			'schemaId' => $this->schemaId,
			'registerId' => $this->registerId,
			'objectUuid' => $this->objectUuid,
			'channel' => $this->channel,
			'recipient' => $this->recipient,
			'subject' => $this->subject,
			'status' => $this->status,
			'errorMessage' => $this->errorMessage,
			'locale' => $this->locale,
			'dispatchedAt' => $this->dispatchedAt?->format(DateTime::ATOM),
			'readAt' => $this->readAt?->format(DateTime::ATOM),
			'read' => ($this->readAt !== null),
			'subjectType' => $this->subjectType,
			'subjectId' => $this->subjectId,
			'snoozedUntil' => $this->snoozedUntil?->format(DateTime::ATOM),
			'archivedAt' => $this->archivedAt?->format(DateTime::ATOM),
			// Answered here so a client that has just snoozed or archived
			// something knows whether it left the list, without having to
			// re-derive the three rules for itself and get one of them wrong.
			'inUnreadList' => $this->isInUnreadListAt(),
		];

	}//end jsonSerialize()
}//end class
