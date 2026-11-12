<?php

/**
 * CallerRateLimiterInterface: CallerRateLimiter as the API caller middleware reaches it.
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
 * CallerRateLimiter, typed for callers outside the Service component.
 *
 * Its outside caller, the API caller middleware, calls consume(), so that is
 * the whole contract. Binding it in ContractBindings lets that caller resolve
 * CallerRateLimiter without naming the class.
 */
interface CallerRateLimiterInterface {
	/**
	 * Count one call and say whether it is allowed.
	 *
	 * Fails OPEN on every internal error, and on an instance whose distributed
	 * cache cannot count atomically. A limiter that refuses traffic because its
	 * counter store is briefly unreachable converts somebody else's outage into
	 * this gemeente's outage, and the thing it is protecting against is a
	 * runaway loop, not an attacker.
	 *
	 * @param string $principal The caller, or the empty string for anonymous.
	 * @param int $now The current unix time, injected so the window can be tested.
	 *
	 * @return array{allowed: bool, limit: int, remaining: int, resetAt: int}|null
	 *         The outcome, or null when no ceiling is administered for this caller.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function consume(string $principal, int $now): ?array;
}//end interface
