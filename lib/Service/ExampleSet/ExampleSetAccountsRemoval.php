<?php

/**
 * OpenRegister Example Set Accounts Removal
 *
 * What removing an example set does to its accounts and groups: the
 * sub-admins and members it added leave their groups, a group it only named
 * gets its old name back, the groups and accounts it created are deleted,
 * and the accounts it adopted are left in place.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Removes an example set's memberships, groups and accounts; each method answers what stays.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
final class ExampleSetAccountsRemoval {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetAccounts $accounts Accounts and groups.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetAccounts $accounts,
	) {
	}//end __construct()

	/**
	 * Take the sub-admins the set added out of their groups; the pairs that stay.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs  The gid and uid pairs.
	 * @param array<string, mixed>                    $report The report; failures are added.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function removeSubAdmins(array $pairs, array &$report): array {
		foreach ($pairs as $index => [$gid, $uid]) {
			if ($this->accounts->removeSubAdmin(gid: $gid, uid: $uid) === false) {
				$report['failed'][] = 'sub-admin '.$gid.' / '.$uid;
				continue;
			}

			unset($pairs[$index]);
		}

		return array_values($pairs);
	}//end removeSubAdmins()

	/**
	 * Take the members the set added out of their groups; the pairs that stay.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs  The gid and uid pairs.
	 * @param array<string, mixed>                    $report The report; counts and failures are added.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function removeMembers(array $pairs, array &$report): array {
		foreach ($pairs as $index => [$gid, $uid]) {
			if ($this->accounts->removeFromGroup(gid: $gid, uid: $uid) === false) {
				$report['failed'][] = 'membership '.$gid.' / '.$uid;
				continue;
			}

			unset($pairs[$index]);
			$report['removed']['memberships']++;
		}

		return array_values($pairs);
	}//end removeMembers()

	/**
	 * Give a group the set only named its old name back, while it still carries the set's; none of them stays.
	 *
	 * @param array<int|string, array<string, mixed>> $names The names the set gave, by gid: `old` and `new`.
	 *
	 * @return array<int|string, array<string, mixed>> No names.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function restoreGroupNames(array $names): array {
		foreach ($names as $gid => $name) {
			if ($this->accounts->groupName(gid: (string) $gid) === (string) $name['new']) {
				$this->accounts->setGroupName(gid: (string) $gid, displayName: (string) $name['old']);
			}

			unset($names[$gid]);
		}

		return $names;
	}//end restoreGroupNames()

	/**
	 * Delete the groups the set created; the groups that stay.
	 *
	 * @param array<int, string>   $groups The gids.
	 * @param array<string, mixed> $report The report; counts and failures are added.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function deleteGroups(array $groups, array &$report): array {
		$kept = [];
		foreach ($groups as $gid) {
			if ($this->accounts->deleteGroup(gid: $gid) === false) {
				$report['failed'][] = 'group '.$gid;
				$kept[]             = $gid;
				continue;
			}

			$report['removed']['groups']++;
		}

		return $kept;
	}//end deleteGroups()

	/**
	 * Delete the accounts the set created and forget the ones it adopted (named under `left`); the accounts that stay.
	 *
	 * @param array<string, string> $users  How each account came into the set, by uid: `created` or `adopted`.
	 * @param array<string, mixed>  $report The report; counts, adopted accounts and failures are added.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function removeUsers(array $users, array &$report): array {
		foreach ($users as $uid => $how) {
			if ($this->letGoOf(uid: $uid, how: $how, report: $report) === true) {
				unset($users[$uid]);
			}
		}

		return $users;
	}//end removeUsers()

	/**
	 * Let go of one account of the set: forget an adopted one (named under `left`), delete a created one; false when
	 * it could not be deleted (named under `failed`).
	 *
	 * @param int|string           $uid    The account.
	 * @param mixed                $how    `created` or `adopted`.
	 * @param array<string, mixed> $report The report; counts, adopted accounts and failures are added.
	 *
	 * @return bool
	 */
	private function letGoOf(int|string $uid, mixed $how, array &$report): bool {
		if ($how === 'adopted') {
			$report['left'][] = $uid;
			return true;
		}

		if ($this->accounts->deleteUser(uid: $uid) === false) {
			$report['failed'][] = 'account '.$uid;
			return false;
		}

		$report['removed']['accounts']++;

		return true;
	}//end letGoOf()
}//end class
