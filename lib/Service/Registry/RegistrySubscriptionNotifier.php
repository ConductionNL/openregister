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

use OCA\OpenRegister\Db\AuditTrailMapper;
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
	 * @param AuditTrailMapper $auditTrailMapper Writes the audit rows.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly AuditTrailMapper $auditTrailMapper,
	) {
	}//end __construct()

	/**
	 * Announce a subscription request: dispatch the connector event and
	 * audit it against the session user.
	 *
	 * @param ObjectEntity $object The object requesting a subscription.
	 * @param string $register Register slug/id the object lives in.
	 * @param string $schema Schema slug/id the object was saved against.
	 * @param string $registry The registry id (`brp`, `kvk`, ...).
	 * @param string $identityValue The object's identity value at that registry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function requested(ObjectEntity $object, string $register, string $schema, string $registry, string $identityValue): void {
		$this->eventDispatcher->dispatchTyped(new RegistrySubscriptionRequestedEvent(
			objectUuid: (string)$object->getUuid(),
			register: $register,
			schema: $schema,
			registry: $registry,
			identityValue: $identityValue,
		));

		$this->auditTrailMapper->createAuditTrailEntry(
			object: $object,
			action: 'registry.subscription.requested',
			context: ['registry' => $registry],
		);
	}//end requested()

	/**
	 * Announce a subscription end: dispatch the connector event and audit
	 * it against the session user.
	 *
	 * @param ObjectEntity $object The object ending its subscription.
	 * @param string $registry The registry id (`brp`, `kvk`, ...).
	 * @param string $identityValue The object's identity value at that registry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function ended(ObjectEntity $object, string $registry, string $identityValue): void {
		$this->eventDispatcher->dispatchTyped(new RegistrySubscriptionEndedEvent(
			objectUuid: (string)$object->getUuid(),
			registry: $registry,
			identityValue: $identityValue,
		));

		$this->auditTrailMapper->createAuditTrailEntry(
			object: $object,
			action: 'registry.subscription.ended',
			context: ['registry' => $registry],
		);
	}//end ended()

	/**
	 * Audit an applied inbound update with the REGISTRY as actor — see
	 * `RegistrySubscriptionService::applyInboundUpdate()` for why this is a
	 * second, explicit row rather than relying on the ordinary save path's
	 * own session-derived audit entry.
	 *
	 * @param ObjectEntity $object The updated object.
	 * @param string $registry The registry id (`brp`, `kvk`, ...).
	 * @param string $eventReference The registry's own event reference for this change.
	 * @param array<int, string> $properties The property names that were changed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	public function auditInboundUpdate(ObjectEntity $object, string $registry, string $eventReference, array $properties): void {
		$this->auditTrailMapper->createAuditTrailEntry(
			object: $object,
			action: 'registry.update',
			context: ['registry' => $registry, 'eventReference' => $eventReference, 'properties' => $properties],
			actorId: 'registry:' . $registry,
			actorName: 'Registry (' . $registry . ')',
		);
	}//end auditInboundUpdate()
}//end class
