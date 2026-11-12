<?php

/**
 * One failed inbound authentication being counted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * One failed inbound authentication being counted: its identity+address key, who it was and the ceiling in force.
 *
 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
 */
final class AuthFailureRun {
	/**
	 * Hold the values.
	 *
	 * @param string                $compositeKey The identity+address cache key.
	 * @param array<string, string> $who          The identity and sanitised address (identity, ip_address).
	 * @param array<string, int>    $ceiling      The ceiling in force (attemptsPerIdentity, attemptsPerAddress, windowSeconds, lockoutSeconds).
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function __construct(
		public readonly string $compositeKey,
		public readonly array $who,
		public readonly array $ceiling,
	) {
	}//end __construct()
}//end class
