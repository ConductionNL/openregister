<?php

/**
 * NotificationBroadcastReceipt entity: one user has seen one broadcast.
 *
 * This row is what makes "once" true per person rather than per page load. The
 * unique index on (broadcast_uuid, user_id) is the enforcement; the entity is
 * the shape it stores.
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
 * NotificationBroadcastReceipt.
 *
 * @method string getBroadcastUuid()
 * @method void setBroadcastUuid(string $broadcastUuid)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method DateTime getSeenAt()
 * @method void setSeenAt(DateTime $seenAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class NotificationBroadcastReceipt extends Entity implements JsonSerializable {

	/**
	 * The broadcast that was seen.
	 *
	 * @var string|null
	 */
	protected ?string $broadcastUuid = null;

	/**
	 * Who saw it.
	 *
	 * @var string|null
	 */
	protected ?string $userId = null;

	/**
	 * When they saw it.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $seenAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'broadcastUuid', type: 'string');
		$this->addType(fieldName: 'userId', type: 'string');
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
			'broadcastUuid' => $this->broadcastUuid,
			'userId' => $this->userId,
			'seenAt' => $this->seenAt?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
