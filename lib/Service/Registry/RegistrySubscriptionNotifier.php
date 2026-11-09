<?php

/**
 * OpenRegister RegistrySubscriptionNotifier
 *
 * Bundles the event-dispatch and audit-trail side effects of a registry
 * subscription action behind one small collaborator, so
 * {@see RegistrySubscriptionService} depends on this instead of directly on
 * `IEventDispatcher`, `AuditTrailMapper` and both event classes. Extracted
 * to keep that service's coupling under this repo's PHPMD threshold — a
 * mechanical constraint, but one that also names a real seam: "how does a
 * subscription action get announced" is one concern, separate from "what
 * counts as a valid subscription state transition".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
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

namespace OCA\OpenRegister\Service\Registry;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\RegistrySubscriptionEndedEvent;
use OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Dispatches the connector-facing events and writes the audit rows for a
 * registry subscription action.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md
 */
class RegistrySubscriptionNotifier {

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Dispatches the connector-facing events.
	 * @param AuditTrailMapperInterface $auditTrailMapper Writes the audit rows.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
	}//end __construct()

	/**
	 * Announce a subscription request: dispatch the connector event and
	 * audit it against the session user.
	 *
	 * The event names the register and schema the object itself carries.
	 *
	 * @param ObjectEntity $object The object requesting a subscription.
	 * @param RegistryIdentity $identity The registry and the object's identity value there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function requested(ObjectEntity $object, RegistryIdentity $identity): void {
		$this->eventDispatcher->dispatchTyped(new RegistrySubscriptionRequestedEvent(
			objectUuid: (string)$object->getUuid(),
			register: (string)$object->getRegister(),
			schema: (string)$object->getSchema(),
			registry: $identity->registry,
			identityValue: $identity->identityValue,
		));

		$this->auditTrailMapper->createAuditTrailEntry(
			object: $object,
			action: 'registry.subscription.requested',
			context: ['registry' => $identity->registry],
		);
	}//end requested()

	/**
	 * Announce a subscription end: dispatch the connector event and audit
	 * it against the session user.
	 *
	 * @param ObjectEntity $object The object ending its subscription.
	 * @param RegistryIdentity $identity The registry and the object's identity value there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function ended(ObjectEntity $object, RegistryIdentity $identity): void {
		$this->eventDispatcher->dispatchTyped(new RegistrySubscriptionEndedEvent(
			objectUuid: (string)$object->getUuid(),
			registry: $identity->registry,
			identityValue: $identity->identityValue,
		));

		$this->auditTrailMapper->createAuditTrailEntry(
			object: $object,
			action: 'registry.subscription.ended',
			context: ['registry' => $identity->registry],
		);
	}//end ended()

	/**
	 * Audit an applied inbound update with the REGISTRY as actor — see
	 * `RegistrySubscriptionService::applyInboundUpdate()` for why this is a
	 * second, explicit row rather than relying on the ordinary save path's
	 * own session-derived audit entry.
	 *
	 * @param ObjectEntity $object The updated object.
	 * @param InboundUpdate $update The applied update (registry, event reference, changed properties).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	public function auditInboundUpdate(ObjectEntity $object, InboundUpdate $update): void {
		$registry = $update->identity->registry;
		$this->auditTrailMapper->createAuditTrailEntry(
			object: $object,
			action: 'registry.update',
			context: ['registry' => $registry, 'eventReference' => $update->eventReference, 'properties' => $update->propertyNames()],
			actorId: 'registry:' . $registry,
			actorName: 'Registry (' . $registry . ')',
		);
	}//end auditInboundUpdate()
}//end class
