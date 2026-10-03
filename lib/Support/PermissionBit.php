<?php

/**
 * PermissionBit — one answer to "which core permission bit does this verb need?"
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

use OCP\Constants;

/**
 * Maps an OpenRegister action onto the Nextcloud share permission bit it needs.
 *
 * WHY THIS IS ITS OWN CLASS. The lookup used to live as a public static method
 * on `ObjectGrantResolver`, which is a real service: it takes an `IManager`,
 * caches resolved grants per user, and answers questions about live shares.
 * Reaching into a stateful service by class name to read a constant table is
 * the static access worth objecting to, because it is invisible in the
 * caller's constructor and it ties a pure question to an object that needs
 * wiring to exist. `HierarchyGrantExpander::narrow()` did exactly that.
 *
 * Moving the table here makes the static call honest. This class holds no
 * state, has no collaborators, and takes the only thing it needs as an
 * argument, so there is nothing to inject and nothing to stub. It sits beside
 * `FleetAppId`, `QueryLimit` and `FilterParams` for the same reason those do:
 * several call paths must reach the SAME answer, and a service would let one
 * of them be constructed with a different implementation.
 *
 * Core's bitmask has five verbs. An OpenRegister action outside that set — a
 * custom verb like ZGW's `besluit_nemen` — has NO bit, so `forAction()`
 * returns null and every caller fails closed. That is the conservative
 * direction and it matches design Q5: RBAC narrows, it never widens.
 *
 * @psalm-suppress UnusedClass Referenced from the RBAC grant paths; psalm's
 *  entry-point analysis does not follow the controllers that reach them.
 */
final class PermissionBit {

	/**
	 * Resolve an action to the core permission bit it requires.
	 *
	 * @param string $action The action, for example 'read' or 'update'.
	 *
	 * @return integer|null The bit, or null when the action has none.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public static function forAction(string $action): ?int {
		$bits = [
			'read' => Constants::PERMISSION_READ,
			'update' => Constants::PERMISSION_UPDATE,
			'create' => Constants::PERMISSION_CREATE,
			'delete' => Constants::PERMISSION_DELETE,
			'share' => Constants::PERMISSION_SHARE,
		];

		return ($bits[$action] ?? null);
	}//end forAction()
}//end class
