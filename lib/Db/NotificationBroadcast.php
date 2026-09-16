<?php

/**
 * NotificationBroadcast entity: one administered message to every user.
 *
 * The loudest act the system has, and therefore the one with the fullest
 * record. Who sent it, what it said and the period it is shown for all live on
 * the row, because a message that reached everybody and cannot be traced is a
 * message nobody owns.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * NotificationBroadcast.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getSubject()
 * @method void setSubject(string $subject)
 * @method string|null getBody()
 * @method void setBody(?string $body)
 * @method string getSender()
 * @method void setSender(string $sender)
 * @method DateTime getStartsAt()
 * @method void setStartsAt(DateTime $startsAt)
 * @method DateTime getEndsAt()
 * @method void setEndsAt(DateTime $endsAt)
 * @method DateTime getCreated()
 * @method void setCreated(DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class NotificationBroadcast extends Entity implements JsonSerializable {

	/**
	 * Stable identifier, used by the receipt rows and the acknowledge route.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The one-line message.
	 *
	 * @var string|null
	 */
	protected ?string $subject = null;

	/**
	 * The longer text, when there is one.
	 *
	 * @var string|null
	 */
	protected ?string $body = null;

	/**
	 * The uid of whoever sent it.
	 *
	 * @var string|null
	 */
	protected ?string $sender = null;

	/**
	 * When it starts showing.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $startsAt = null;

	/**
	 * When it stops showing.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $endsAt = null;

	/**
	 * When the record was written.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'subject', type: 'string');
		$this->addType(fieldName: 'body', type: 'string');
		$this->addType(fieldName: 'sender', type: 'string');
		$this->addType(fieldName: 'startsAt', type: 'datetime');
		$this->addType(fieldName: 'endsAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Whether this broadcast is showing at a given moment.
	 *
	 * Inclusive at both ends: a broadcast for "today" covers the whole of
	 * today, which is what an administrator sending one for today means.
	 *
	 * @param DateTime|null $asOf The moment to judge against, defaulting to now.
	 *
	 * @return boolean True when the broadcast is within its period.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function isActiveAt(?DateTime $asOf = null): bool {
		if ($this->startsAt === null || $this->endsAt === null) {
			return false;
		}

		$moment = ($asOf ?? new DateTime());
		return ($this->startsAt <= $moment && $this->endsAt >= $moment);

	}//end isActiveAt()

	/**
	 * JSON serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'subject' => $this->subject,
			'body' => $this->body,
			'sender' => $this->sender,
			'startsAt' => $this->startsAt?->format(DateTime::ATOM),
			'endsAt' => $this->endsAt?->format(DateTime::ATOM),
			'created' => $this->created?->format(DateTime::ATOM),
			'active' => $this->isActiveAt(),
		];

	}//end jsonSerialize()
}//end class
