<?php

/**
 * CallerPolicyInterface: CallerPolicy as the API caller middleware reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
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

namespace OCA\OpenRegister\Service\ApiCaller;

/**
 * CallerPolicy, typed for callers outside the Service component.
 *
 * Its outside caller, the API caller middleware, calls allowsAddress() and limitFor(), so those are
 * the whole contract. Binding it in ContractBindings lets that caller resolve
 * CallerPolicy without naming the class.
 */
interface CallerPolicyInterface {
	/**
	 * Whether a caller bound to addresses may call from this one.
	 *
	 * @param string $principal The caller.
	 * @param string $address The address the call came from.
	 *
	 * @return bool True when the call is allowed from this address.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function allowsAddress(string $principal, string $address): bool;

	/**
	 * The ceiling for one caller, or null when none is administered.
	 *
	 * A named entry wins over the wildcard, so an administrator can bound
	 * everybody loosely and one runaway leverancier tightly.
	 *
	 * @param string $principal The caller, or the empty string for anonymous.
	 *
	 * @return array{limit: int, windowSeconds: int}|null The ceiling, or null.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function limitFor(string $principal): ?array;
}//end interface
