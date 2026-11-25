<?php

/**
 * OpenRegister PermissionMatrixSubjects
 *
 * The groups, or the users with their groups, a permission matrix lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Reads every group, or every user and their groups, from Nextcloud.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */
class PermissionMatrixSubjects {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Lists the groups and a user's groups.
	 * @param IUserManager  $userManager  Walks every user.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The subjects of one level, sorted by id so two exports of one instance line up.
	 *
	 * @param string $level `group` or `user`.
	 *
	 * @return array<int, PermissionMatrixSubject> The subjects.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function of(string $level): array {
		$subjects = $this->groups();
		if ($level === PermissionMatrixSubject::TYPE_USER) {
			$subjects = $this->users();
		}

		usort($subjects, static fn (PermissionMatrixSubject $a, PermissionMatrixSubject $b): int => strcmp($a->id, $b->id));

		return $subjects;
	}//end of()

	/**
	 * Every group.
	 *
	 * @return array<int, PermissionMatrixSubject> The group subjects.
	 */
	private function groups(): array {
		return array_values(
			array_map(
				static fn (IGroup $group): PermissionMatrixSubject => PermissionMatrixSubject::group($group->getGID()),
				$this->groupManager->search('')
			)
		);
	}//end groups()

	/**
	 * Every user, with the groups they are a member of.
	 *
	 * @return array<int, PermissionMatrixSubject> The user subjects.
	 */
	private function users(): array {
		$subjects = [];
		$this->userManager->callForAllUsers(
			function (IUser $user) use (&$subjects): void {
				$groups = $this->groupManager->getUserGroupIds($user);
				sort($groups);
				$subjects[] = PermissionMatrixSubject::user($user->getUID(), $groups);
			}
		);

		return $subjects;
	}//end users()
}//end class
