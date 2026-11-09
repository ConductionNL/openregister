<?php

/**
 * DeliveryOrigin: the rule and object a delivery belongs to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The rule, the object and the firing a delivery or history row belongs to.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class DeliveryOrigin {

	/**
	 * Constructor.
	 *
	 * @param string       $ruleId  The rule (annotation key) the delivery is filed under.
	 * @param ObjectEntity $object  The object the event happened on.
	 * @param string|null  $eventId The firing's event identifier, when there is one.
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly ObjectEntity $object,
		public readonly ?string $eventId = null,
	) {
	}//end __construct()

	/**
	 * The origin of a firing: its rule, object and event identifier.
	 *
	 * @param RuleFiring $firing The rule and the event.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public static function ofFiring(RuleFiring $firing): self {
		return new self(ruleId: $firing->ruleId, object: $firing->object, eventId: $firing->eventId);
	}//end ofFiring()
}//end class
