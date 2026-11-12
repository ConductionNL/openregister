<?php

/**
 * One rule a holder holds, with the role it came through.
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
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * A rule entry as `/permissions` reports it for one holder: the entry as
 * written and the role it was reached through, or null when it was not.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
final class HeldRule {

	/**
	 * Constructor.
	 *
	 * @param mixed $entry The entry as written (a role grant carries its holder list).
	 * @param mixed $role  The role it came through, or null.
	 */
	public function __construct(
		public readonly mixed $entry,
		public readonly mixed $role,
	) {
	}//end __construct()

	/**
	 * The `match` clause of a direct entry; a role grant carries none of its own.
	 *
	 * @return mixed The clause, or null.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function matchClause(): mixed {
		if ($this->role === null && is_array($this->entry) === true) {
			return ($this->entry['match'] ?? null);
		}

		return null;
	}//end matchClause()
}//end class
