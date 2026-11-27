<?php

/**
 * PersonalTokenGrantBinderInterface: bind the calling personal token's grant to this request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * What the request middleware (Platform) runs before a controller, without
 * naming the Core PersonalTokenGrantBinder (ADR-013 Rule 1), which implements it.
 */
interface PersonalTokenGrantBinderInterface {

	/**
	 * Bind the calling app password's grant, when the request carries one.
	 *
	 * @param int $now The current time (unix seconds), for the grant's rate limit.
	 *
	 * @return object|null The bound grant, or null when none is bound.
	 *
	 * @throws \OCA\OpenRegister\Exception\TokenRateLimitExceededException When the call is over the grant's limit.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function bind(int $now): ?object;
}//end interface
