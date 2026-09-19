<?php

/**
 * OpenRegister RegistrySubscriptionEndedEvent
 *
 * Cross-app command event (finding B22): dispatched when a user with
 * `update` on an object ends its registry subscription. A connector app
 * (integriq) registers an IEventListener for this event and unsubscribes
 * at the source.
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
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Event dispatched to end a registry subscription on an object.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */
class RegistrySubscriptionEndedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid UUID of the object ending its subscription.
	 * @param string $registry The registry id (`brp`, `kvk`, ...).
	 * @param string $identityValue The object's identity value at that registry.
	 */
	public function __construct(
		private readonly string $objectUuid,
		private readonly string $registry,
		private readonly string $identityValue,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the object's UUID.
	 *
	 * @return string
	 */
	public function getObjectUuid(): string {
		return $this->objectUuid;
	}//end getObjectUuid()

	/**
	 * Get the registry id (`brp`, `kvk`, ...).
	 *
	 * @return string
	 */
	public function getRegistry(): string {
		return $this->registry;
	}//end getRegistry()

	/**
	 * Get the object's identity value at the registry.
	 *
	 * @return string
	 */
	public function getIdentityValue(): string {
		return $this->identityValue;
	}//end getIdentityValue()
}//end class
