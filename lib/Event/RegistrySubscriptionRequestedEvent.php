<?php

/**
 * OpenRegister RegistrySubscriptionRequestedEvent
 *
 * Cross-app command event (finding B22): dispatched when a user with
 * `update` on an object requests a registry subscription. OpenRegister
 * does NOT talk to BRP or KvK itself and does NOT call integriq directly
 * (direct cross-app RPC is gate-27-forbidden) — it only carries the
 * request. A connector app (integriq) registers an IEventListener for this
 * event and turns it into a live subscription at the source, then reports
 * back through the inbound update endpoint.
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
 * Event dispatched to request a registry subscription on an object.
 *
 * Carries the triggering object's uuid/register/schema, the registry id
 * (`brp`, `kvk`, ...) and the identity value (BSN, KvK number, ...) a
 * connector needs to establish the subscription at the source.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */
class RegistrySubscriptionRequestedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid UUID of the object requesting a subscription.
	 * @param string $register Register slug/id the object lives in.
	 * @param string $schema Schema slug/id the object was saved against.
	 * @param string $registry The registry id (`brp`, `kvk`, ...).
	 * @param string $identityValue The object's identity value at that registry.
	 */
	public function __construct(
		private readonly string $objectUuid,
		private readonly string $register,
		private readonly string $schema,
		private readonly string $registry,
		private readonly string $identityValue,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the triggering object's UUID.
	 *
	 * @return string
	 */
	public function getObjectUuid(): string {
		return $this->objectUuid;
	}//end getObjectUuid()

	/**
	 * Get the triggering object's register slug/id.
	 *
	 * @return string
	 */
	public function getRegister(): string {
		return $this->register;
	}//end getRegister()

	/**
	 * Get the triggering object's schema slug/id.
	 *
	 * @return string
	 */
	public function getSchema(): string {
		return $this->schema;
	}//end getSchema()

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

	/**
	 * Flatten for a job boundary / CloudEvent payload.
	 *
	 * @return array<string, string>
	 */
	public function getPayload(): array {
		return [
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'registry' => $this->registry,
			'identityValue' => $this->identityValue,
		];
	}//end getPayload()
}//end class
