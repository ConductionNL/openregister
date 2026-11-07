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

/**
 * Event dispatched to request a registry subscription on an object.
 *
 * Carries the triggering object's uuid/register/schema, the registry id
 * (`brp`, `kvk`, ...) and the identity value (BSN, KvK number, ...) a
 * connector needs to establish the subscription at the source.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */
class RegistrySubscriptionRequestedEvent extends AbstractRegistrySubscriptionEvent {

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
		string $objectUuid,
		private readonly string $register,
		private readonly string $schema,
		string $registry,
		string $identityValue,
	) {
		parent::__construct(objectUuid: $objectUuid, registry: $registry, identityValue: $identityValue);
	}//end __construct()

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
	 * Flatten for a job boundary / CloudEvent payload.
	 *
	 * @return array<string, string>
	 */
	public function getPayload(): array {
		return [
			'objectUuid' => $this->getObjectUuid(),
			'register' => $this->register,
			'schema' => $this->schema,
			'registry' => $this->getRegistry(),
			'identityValue' => $this->getIdentityValue(),
		];
	}//end getPayload()
}//end class
