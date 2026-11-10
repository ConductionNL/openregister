<?php

/**
 * One read of the caller's membership rows for a $memberOf operand.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-follows-membership/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The normalised operand and the caller whose rows are read. Built and consumed by MemberOfResolver only; it never leaves that class.
 *
 * @spec openspec/changes/access-follows-membership/specs/rbac-scopes/spec.md
 */
final class MemberOfLookup {
	/**
	 * Set the fields.
	 *
	 * @param array<string, mixed> $spec   The normalised operand.
	 * @param string               $userId The current user.
	 */
	public function __construct(
		public readonly array $spec,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
