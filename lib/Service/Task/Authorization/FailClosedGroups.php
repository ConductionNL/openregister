<?php

/**
 * The group backend, read fail-closed.
 *
 * One reading of IGroupManager for the task and case authorization
 * services: no backend, a throwing backend or an empty group id GRANTS
 * nothing, and a role that does not resolve to a group is reported as the
 * reason a decision cannot be determined. Callers throw their own
 * exception type with that reason, so their messages stay their own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task\Authorization;

use OCP\IGroupManager;
use Throwable;

/**
 * Administrator, membership and role-resolution answers that deny when unsure.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class FailClosedGroups {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager|null $groupManager The group backend; absent, every
	 *                                         answer that needs it denies.
	 */
	public function __construct(
		private readonly ?IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * Whether the backend affirms that a uid is an administrator.
	 *
	 * @param string $uid The acting identity.
	 *
	 * @return boolean False without a backend or when it fails.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function isAdmin(string $uid): bool {
		$backend = $this->groupManager;
		if ($backend === null) {
			return false;
		}

		try {
			return $backend->isAdmin($uid);
		} catch (Throwable) {
			// An unavailable backend cannot GRANT anything.
			return false;
		}
	}//end isAdmin()

	/**
	 * Whether the backend affirms that a uid is in a group.
	 *
	 * @param string $uid     The acting identity.
	 * @param string $groupId The group to test.
	 *
	 * @return boolean False without a backend, for an empty group id, or when it fails.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function isInGroup(string $uid, string $groupId): bool {
		$backend = $this->groupManager;
		if ($backend === null || $groupId === '') {
			return false;
		}

		try {
			return $backend->isInGroup($uid, $groupId);
		} catch (Throwable) {
			return false;
		}
	}//end isInGroup()

	/**
	 * Why a role cannot be used, or null when it resolves to the group of the same name.
	 *
	 * @param string $role The role name.
	 *
	 * @return string|null The reason (the tail of a denial message), or null.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function roleProblem(string $role): ?string {
		$backend = $this->groupManager;
		if ($backend === null) {
			return sprintf("role '%s' cannot be resolved because no group backend is available.", $role);
		}

		if ($this->groupExists(backend: $backend, role: $role) === false) {
			return sprintf("role '%s' does not resolve to any group.", $role);
		}

		return null;
	}//end roleProblem()

	/**
	 * Whether a group of that name exists; a failing backend says no.
	 *
	 * @param IGroupManager $backend The backend.
	 * @param string        $role    The group name.
	 *
	 * @return boolean True only when the backend affirms it.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function groupExists(IGroupManager $backend, string $role): bool {
		try {
			return $backend->groupExists($role);
		} catch (Throwable) {
			return false;
		}
	}//end groupExists()
}//end class
