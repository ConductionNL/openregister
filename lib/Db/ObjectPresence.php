<?php

/**
 * One reader, with one object open.
 *
 * 🔴 THE ROW SAYS WHO AND WHERE, AND DELIBERATELY NOTHING ELSE. It carries no
 * page, no tab, no action and no device: "Anna has this case open" is the whole
 * disclosure, and anything more would make a presence table a record of what
 * colleagues are doing all day. Design D-4 keeps it off the object too, so
 * presence writes no property, cuts no version and leaves no audit entry.
 *
 * 🔑 `arrivedAt` IS KEPT ACROSS BEATS AND `lastSeen` IS NOT. A reader who has
 * had the page open for an hour and one who opened it ten seconds ago are
 * different facts to the person deciding whether to start typing, and the
 * heartbeat would flatten them into one if arrival moved with every beat.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One presence row.
 *
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method DateTime|null getArrivedAt()
 * @method void setArrivedAt(?DateTime $arrivedAt)
 * @method DateTime|null getLastSeen()
 * @method void setLastSeen(?DateTime $lastSeen)
 */
class ObjectPresence extends Entity implements JsonSerializable {

	/**
	 * The reader.
	 *
	 * @var string|null
	 */
	protected ?string $userId = null;

	/**
	 * The object they have open.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * When they arrived, kept across beats.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $arrivedAt = null;

	/**
	 * When their client last said they were still there.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastSeen = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'arrivedAt', type: 'datetime');
		$this->addType(fieldName: 'lastSeen', type: 'datetime');
	}//end __construct()

	/**
	 * The row as a client reads it.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function jsonSerialize(): array {
		return [
			'user' => $this->userId,
			'object' => $this->objectUuid,
			'arrivedAt' => $this->arrivedAt?->format('c'),
			'lastSeen' => $this->lastSeen?->format('c'),
		];
	}//end jsonSerialize()
}//end class
