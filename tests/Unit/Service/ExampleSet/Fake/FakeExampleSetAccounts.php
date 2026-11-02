<?php

/**
 * In-memory accounts and groups for the example-set tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccounts;

/**
 * Keeps accounts, their passwords and group members in memory.
 */
class FakeExampleSetAccounts extends ExampleSetAccounts {
	/** @var array<string, array{displayName: string, email: string, password: string}> Accounts by uid. */
	public array $users = [];

	/** @var array<string, array<int, string>> Members by gid. */
	public array $groups = [];

	/** @var array<string, array<int, string>> Sub-admins by gid. */
	public array $subAdmins = [];

	/** @var array<int, string> Accounts that exist but userExists() does not see yet (another writer made them). */
	public array $unseenUsers = [];

	/** @var int Number of accounts created. */
	public int $userCreates = 0;

	/** @var int Number of passwords made. */
	private int $passwords = 0;

	/**
	 * No managers.
	 */
	public function __construct() {
	}

	public function userExists(string $uid): bool {
		return isset($this->users[$uid]);
	}

	public function newPassword(): string {
		$this->passwords++;

		return 'generated-'.$this->passwords;
	}

	public function createUser(string $uid, string $displayName, string $email, string $password): string {
		if (in_array($uid, $this->unseenUsers, true) === true) {
			$this->unseenUsers = array_values(array_diff($this->unseenUsers, [$uid]));
			$this->users[$uid] = ['displayName' => $displayName, 'email' => $email, 'password' => 'theirs'];
			return ExampleSetAccounts::ALREADY_EXISTS;
		}

		$this->userCreates++;
		$this->users[$uid] = ['displayName' => $displayName, 'email' => $email, 'password' => $password];

		return '';
	}

	public function deleteUser(string $uid): bool {
		unset($this->users[$uid]);

		return true;
	}

	/** @var array<string, string> Display names by group id. */
	public array $groupNames = [];

	public function groupName(string $gid): string {
		return ($this->groupNames[$gid] ?? '');
	}

	public function setGroupName(string $gid, string $displayName): bool {
		if (isset($this->groups[$gid]) === false) {
			return false;
		}

		$this->groupNames[$gid] = $displayName;

		return true;
	}

	public function groupExists(string $gid): bool {
		return isset($this->groups[$gid]);
	}

	public function createGroup(string $gid, string $displayName): bool {
		$this->groups[$gid] = [];
		$this->groupNames[$gid] = ($displayName === '' ? $gid : $displayName);

		return true;
	}

	public function deleteGroup(string $gid): bool {
		unset($this->groups[$gid]);

		return true;
	}

	public function inGroup(string $gid, string $uid): bool {
		return in_array($uid, ($this->groups[$gid] ?? []), true);
	}

	public function addToGroup(string $gid, string $uid): bool {
		if (isset($this->groups[$gid]) === false || isset($this->users[$uid]) === false) {
			return false;
		}

		$this->groups[$gid][] = $uid;

		return true;
	}

	public function removeFromGroup(string $gid, string $uid): bool {
		$this->groups[$gid] = array_values(array_diff(($this->groups[$gid] ?? []), [$uid]));

		return true;
	}

	public function isSubAdmin(string $gid, string $uid): bool {
		return in_array($uid, ($this->subAdmins[$gid] ?? []), true);
	}

	public function addSubAdmin(string $gid, string $uid): bool {
		if (isset($this->groups[$gid]) === false || isset($this->users[$uid]) === false) {
			return false;
		}

		$this->subAdmins[$gid][] = $uid;

		return true;
	}

	public function removeSubAdmin(string $gid, string $uid): bool {
		$this->subAdmins[$gid] = array_values(array_diff(($this->subAdmins[$gid] ?? []), [$uid]));

		return true;
	}
}
