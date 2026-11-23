<?php
/**
 * AuthRateLimitCeilingInterface: the inbound-authentication ceiling Core enforces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The administered inbound-authentication ceiling, as Core's rate limiter reads it.
 *
 * SecurityService (Core) applies the ceiling; the hardening policy (Compliance)
 * resolves it from the defaults and the administered overrides, and binds its
 * answer in its own registrar.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
 */
interface AuthRateLimitCeilingInterface {

	/**
	 * The inbound authentication ceiling this instance enforces.
	 *
	 * @return array{attemptsPerIdentity: int, attemptsPerAddress: int, windowSeconds: int, lockoutSeconds: int} The ceiling.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function authRateLimit(): array;
}//end interface
