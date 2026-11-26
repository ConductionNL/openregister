<?php

/**
 * OpenRegister InboundUpdate
 *
 * One update a registry posts for an identity it owns properties of.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Registry;

/**
 * One inbound registry update: whom it is for, the properties the registry
 * changed and the registry's own reference for the change.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
 */
final class InboundUpdate {

	/**
	 * Describe the update.
	 *
	 * @param RegistryIdentity     $identity       The registry and identity value the update is for.
	 * @param array<string, mixed> $properties     The changed properties the registry offers.
	 * @param string               $eventReference The registry's own event reference for this change.
	 * @param string|null          $subscriptionState The connector's answer to a requested subscription:
	 *                                                `active` or `failed`, null when it says nothing.
	 * @param string               $error          Why the connector could not subscribe, with `failed`.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	public function __construct(
		public readonly RegistryIdentity $identity,
		public readonly array $properties,
		public readonly string $eventReference,
		public readonly ?string $subscriptionState=null,
		public readonly string $error='',
	) {
	}//end __construct()

	/**
	 * The names of the properties the update carries.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	public function propertyNames(): array {
		return array_keys($this->properties);
	}//end propertyNames()
}//end class
