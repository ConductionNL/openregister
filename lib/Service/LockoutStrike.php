<?php

/**
 * One counted strike against a login lockout.
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
 * One counted strike against a login lockout: the count after it, the lockout key it may set and the event it logs.
 *
 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
 */
final class LockoutStrike {
	/**
	 * Hold the values.
	 *
	 * @param mixed  $attempts The count after this attempt.
	 * @param string $key      The lockout cache key.
	 * @param string $event    The security event to log when the limit is reached.
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function __construct(
		public readonly mixed $attempts,
		public readonly string $key,
		public readonly string $event,
	) {
	}//end __construct()
}//end class
