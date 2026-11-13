<?php

/**
 * OpenRegister AutomationRecord
 *
 * One run of a declared automation step: a notification evaluated for one
 * object event, or a lifecycle action reached on a transition.
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
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A stored automation run record (D-1). Recipients are never named: a
 * notification record carries counts per channel only.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getAutomationKey()
 * @method void setAutomationKey(string $automationKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getTransition()
 * @method void setTransition(?string $transition)
 * @method string|null getSchemaId()
 * @method void setSchemaId(?string $schemaId)
 * @method string|null getRegisterId()
 * @method void setRegisterId(?string $registerId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getEventId()
 * @method void setEventId(?string $eventId)
 * @method string getOutcome()
 * @method void setOutcome(string $outcome)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method string|null getChannels()
 * @method void setChannels(?string $channels)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method DateTime|null getRecordedAt()
 * @method void setRecordedAt(?DateTime $recordedAt)
 *
 * @spec openspec/changes/automation-run-records/design.md
 */
class AutomationRecord extends Entity implements JsonSerializable {

	public const KIND_NOTIFICATION = 'notification';

	public const KIND_LIFECYCLE_ACTION = 'lifecycle-action';

	public const KINDS = [self::KIND_NOTIFICATION, self::KIND_LIFECYCLE_ACTION];

	/**
	 * The record kind: notification or lifecycle-action.
	 *
	 * @var string
	 */
	protected string $kind = '';

	/**
	 * The key the app gave the declaration ('' when it gave none).
	 *
	 * @var string
	 */
	protected string $automationKey = '';

	/**
	 * The notification slug or the action name.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The transition, for a lifecycle action.
	 *
	 * @var string|null
	 */
	protected ?string $transition = null;

	/**
	 * The schema of the object.
	 *
	 * @var string|null
	 */
	protected ?string $schemaId = null;

	/**
	 * The register of the object.
	 *
	 * @var string|null
	 */
	protected ?string $registerId = null;

	/**
	 * The object the run was for.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The dispatch event id, for a notification.
	 *
	 * @var string|null
	 */
	protected ?string $eventId = null;

	/**
	 * The outcome.
	 *
	 * @var string
	 */
	protected string $outcome = '';

	/**
	 * Why the run was skipped.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * JSON counts per channel, for a notification.
	 *
	 * @var string|null
	 */
	protected ?string $channels = null;

	/**
	 * A message, for a lifecycle action.
	 *
	 * @var string|null
	 */
	protected ?string $message = null;

	/**
	 * When the record was written (UTC).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $recordedAt = null;

	/**
	 * Constructor.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function __construct() {
		$fields = [
			'kind', 'automationKey', 'name', 'transition', 'schemaId', 'registerId',
			'objectUuid', 'eventId', 'outcome', 'reason', 'channels', 'message',
		];
		foreach ($fields as $field) {
			$this->addType(fieldName: $field, type: 'string');
		}

		$this->addType(fieldName: 'recordedAt', type: 'datetime');
	}//end __construct()

	/**
	 * The channel counts as an array.
	 *
	 * @return array<string, array<string, int>> Counts per channel.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function channelCounts(): array {
		if ($this->channels === null || $this->channels === '') {
			return [];
		}

		$decoded = json_decode($this->channels, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end channelCounts()

	/**
	 * The record as the read route returns it.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function jsonSerialize(): array {
		return [
			'id'         => $this->id,
			'kind'       => $this->kind,
			'key'        => $this->automationKey,
			'name'       => $this->name,
			'transition' => $this->transition,
			'schema'     => $this->schemaId,
			'register'   => $this->registerId,
			'object'     => $this->objectUuid,
			'eventId'    => $this->eventId,
			'outcome'    => $this->outcome,
			'reason'     => $this->reason,
			'channels'   => $this->channelCounts(),
			'message'    => $this->message,
			'recordedAt' => $this->recordedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
