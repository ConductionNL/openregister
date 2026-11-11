<?php

/**
 * RegistrySubscriptionLifecycleInterface: request, end and apply updates of a registry subscription.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Registry;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The registry-subscription steps the subscription and inbound-update endpoints run.
 *
 * Bound to RegistrySubscriptionService in ContractBindings.
 */
interface RegistrySubscriptionLifecycleInterface {

	/**
	 * Request a subscription on an object. Caller MUST have already checked
	 * the requesting user has `update` on `$object` — this service does not
	 * re-check permission (per this codebase's Controller -> Service ->
	 * Mapper layering; the per-object IDOR guard belongs in the controller).
	 *
	 * @param ObjectEntity $object The object to subscribe.
	 * @param Schema $schema The object's schema (must declare `x-openregister-registry`).
	 *
	 * @return RegistrySubscription The persisted row.
	 *
	 * @throws InvalidArgumentException When the schema declares no registry annotation,
	 *                                  or the object has no value for the identity property.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function requestSubscription(ObjectEntity $object, Schema $schema): RegistrySubscription;

	/**
	 * End a subscription on an object. Caller MUST have already checked the
	 * requesting user has `update` on `$object`.
	 *
	 * @param ObjectEntity $object The object to unsubscribe.
	 *
	 * @return RegistrySubscription The persisted row.
	 *
	 * @throws DoesNotExistException When the object never had a subscription row.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function endSubscription(ObjectEntity $object): RegistrySubscription;

	/**
	 * Apply an inbound registry update (`registry-subscriptions` REQ 3) to
	 * every object actively subscribed to `$registry`/`$identityValue`.
	 *
	 * @param InboundUpdate $update The registry and identity value the update is for, the changed properties the
	 *                              registry is offering and its own event reference for this change.
	 *
	 * @return array{matched: int, applied: array<int, string>, rejected: array<int, array{objectUuid: string, properties: array<int, string>}>}
	 *         `matched` is how many active subscriptions exist for this
	 *         registry/identity — 0 means "no such subscription", which the
	 *         controller answers 404, distinct from a match whose payload
	 *         changed nothing (`matched` > 0, `applied` and `rejected` both
	 *         empty), which is a 200 no-op per REQ 3 ("an unchanged payload
	 *         announces nothing"). `applied` lists the object uuids that
	 *         were updated; `rejected` names, per object, which supplied
	 *         properties it does not own (that object's update was skipped
	 *         entirely — a partial write of only the owned subset is never
	 *         applied, so a caller cannot misread a rejected update as a
	 *         smaller accepted one).
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	public function applyInboundUpdate(InboundUpdate $update): array;
}//end interface
