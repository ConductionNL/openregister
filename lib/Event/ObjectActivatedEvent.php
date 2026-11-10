<?php

/**
 * An object left draft through a submit: the moment of receipt (decision 180).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Receipt listeners subscribe here.
 *
 * An object created straight into `active` (no draft) is received at create:
 * a receipt listener treats `ObjectCreatedEvent` for a non-draft object and
 * this event as the same moment. A draft's own create and updates are not a
 * receipt, so a receipt listener skips an object whose `isDraft()` is true.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */
class ObjectActivatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity      $object     The object, now `active`.
	 * @param DateTimeImmutable $receivedAt The moment it left draft.
	 */
	public function __construct(
		private readonly ObjectEntity $object,
		private readonly DateTimeImmutable $receivedAt,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The activated object.
	 *
	 * @return ObjectEntity The object.
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * The moment of receipt.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	public function getReceivedAt(): DateTimeImmutable {
		return $this->receivedAt;
	}//end getReceivedAt()
}//end class
