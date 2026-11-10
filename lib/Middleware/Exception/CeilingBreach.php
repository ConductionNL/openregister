<?php

/**
 * A caller's call that went over its administered ceiling.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware\Exception;

/**
 * The ceiling a caller went over, the window it applies over, when that window rolls, and the time of the call.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Exception
 *
 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
 */
final class CeilingBreach {

	/**
	 * Constructor.
	 *
	 * @param int $limit The administered ceiling.
	 * @param int $windowSeconds The window the ceiling applies over.
	 * @param int $resetAt The unix time the window rolls.
	 * @param int $now The current unix time.
	 */
	public function __construct(
		public readonly int $limit,
		public readonly int $windowSeconds,
		public readonly int $resetAt,
		public readonly int $now,
	) {
	}//end __construct()

	/**
	 * The seconds until the window rolls, never less than one.
	 *
	 * @return int The seconds a caller should wait.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function retryAfter(): int {
		return max(1, ($this->resetAt - $this->now));
	}//end retryAfter()
}//end class
