<?php

/**
 * RegistrySubscriptionServiceInterface: the registry subscription lifecycle and the inbound update.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\Schema;

/**
 * What the registry subscription and inbound update controllers need of RegistrySubscriptionService.
 * Bound to RegistrySubscriptionService in ContractBindings, so it resolves to the same shared instance.
 */
interface RegistrySubscriptionServiceInterface {

	/**
	 * Request a subscription on an object. The caller has already checked `update` on it.
	 *
	 * @param ObjectEntity $object The object to subscribe.
	 * @param Schema       $schema The object's schema (must declare `x-openregister-registry`).
	 *
	 * @return RegistrySubscription The persisted row.
	 */
	public function requestSubscription(ObjectEntity $object, Schema $schema): RegistrySubscription;

	/**
	 * End a subscription on an object. The caller has already checked `update` on it.
	 *
	 * @param ObjectEntity $object The object to unsubscribe.
	 *
	 * @return RegistrySubscription The persisted row.
	 */
	public function endSubscription(ObjectEntity $object): RegistrySubscription;

	/**
	 * Apply an inbound registry update to every object actively subscribed to it.
	 *
	 * @param InboundUpdate $update The registry and identity value the update is for, the changed properties the
	 *                              registry is offering and its own event reference for this change.
	 *
	 * @return array{matched: int, applied: array<int, string>, rejected: array<int, array{objectUuid: string, properties: array<int, string>}>}
	 */
	public function applyInboundUpdate(InboundUpdate $update): array;
}//end interface
