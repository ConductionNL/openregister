<?php

/**
 * One failed login or inbound authentication attempt.
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
 * One failed login or inbound authentication attempt: who tried, from where, and why it failed.
 *
 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
 */
final class FailedAttempt {
	/**
	 * Hold the values.
	 *
	 * @param string $subject   The username or authentication identity ('' when unknown).
	 * @param string $ipAddress The client address of the attempt.
	 * @param string $reason    Why the attempt failed.
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $ipAddress,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
