<?php

/**
 * OpenRegister Example Set Accounts
 *
 * The Nextcloud accounts and groups of an example set's cast. Makes a strong
 * password when the administrator gives none; the password goes back to the
 * caller once and is never logged.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates, finds and deletes the cast's accounts and groups.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One public method per Nextcloud account or group operation, so a test can stand in for each.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */
class ExampleSetAccounts {

	/**
	 * What createUser() answers when the account id is already taken: the user backend may have cached the
	 * account as absent, so userExists() cannot be asked again in the same run.
	 */
	public const ALREADY_EXISTS = 'the account already exists';

	/**
	 * Length of a generated password, before the suffix that satisfies common password policies.
	 */
	private const PASSWORD_LENGTH = 24;

	/**
	 * Constructor.
	 *
	 * @param IUserManager    $users  Nextcloud accounts.
	 * @param IGroupManager   $groups Nextcloud groups.
	 * @param ISecureRandom   $random Makes passwords.
	 * @param LoggerInterface $logger Logger; never given a password.
	 * @param ISubAdmin|null  $subAdmins Nextcloud group sub-admins.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
		private readonly ?ISubAdmin $subAdmins=null,
	) {
	}//end __construct()

	/**
	 * Whether an account exists.
	 *
	 * @param string $uid The account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function userExists(string $uid): bool {
		return $this->users->userExists($uid) === true;
	}//end userExists()

	/**
	 * A new strong password.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function newPassword(): string {
		return $this->random->generate(self::PASSWORD_LENGTH, ISecureRandom::CHAR_HUMAN_READABLE).'-Ex7!';
	}//end newPassword()

	/**
	 * Create an account.
	 *
	 * @param string $uid         The account id.
	 * @param string $displayName The name shown.
	 * @param string $email       The e-mail address, or ''.
	 * @param string $password    The password.
	 *
	 * @return string '' when created, otherwise why not.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function createUser(string $uid, string $displayName, string $email, string $password): string {
		try {
			$user = $this->users->createUser($uid, $password);
		} catch (Throwable $e) {
			if (self::isDuplicate(error: $e) === true) {
				return self::ALREADY_EXISTS;
			}

			$this->logger->warning('OpenRegister: an example set account was not created', ['uid' => $uid, 'reason' => $e->getMessage()]);
			return $e->getMessage();
		}

		if ($user === false) {
			return 'Nextcloud refused the account';
		}

		$user->setDisplayName($displayName);
		if ($email !== '') {
			$user->setSystemEMailAddress($email);
		}

		return '';
	}//end createUser()

	/**
	 * Delete an account; true when it is gone.
	 *
	 * @param string $uid The account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function deleteUser(string $uid): bool {
		$user = $this->users->get($uid);
		if ($user === null) {
			return true;
		}

		return $user->delete() === true;
	}//end deleteUser()

	/**
	 * Whether a group exists.
	 *
	 * @param string $gid The group id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function groupExists(string $gid): bool {
		return $this->groups->groupExists($gid) === true;
	}//end groupExists()

	/**
	 * A group's display name; '' when the group is not there.
	 *
	 * @param string $gid The group id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-group-without-a-name-gets-the-declared-one
	 */
	public function groupName(string $gid): string {
		return (string) $this->groups->get($gid)?->getDisplayName();
	}//end groupName()

	/**
	 * Set a group's display name; true when it was set.
	 *
	 * @param string $gid         The group id.
	 * @param string $displayName The name shown.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-group-without-a-name-gets-the-declared-one
	 */
	public function setGroupName(string $gid, string $displayName): bool {
		return $this->groups->get($gid)?->setDisplayName($displayName) === true;
	}//end setGroupName()

	/**
	 * Create a group; true when it exists afterwards.
	 *
	 * @param string $gid         The group id.
	 * @param string $displayName The name shown, or ''.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function createGroup(string $gid, string $displayName): bool {
		$group = $this->groups->createGroup($gid);
		if ($group === null) {
			return false;
		}

		if ($displayName !== '') {
			$group->setDisplayName($displayName);
		}

		return true;
	}//end createGroup()

	/**
	 * Delete a group; true when it is gone.
	 *
	 * @param string $gid The group id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function deleteGroup(string $gid): bool {
		$group = $this->groups->get($gid);
		if ($group === null) {
			return true;
		}

		return $group->delete() === true;
	}//end deleteGroup()

	/**
	 * Whether an account is in a group.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function inGroup(string $gid, string $uid): bool {
		return $this->groups->isInGroup($uid, $gid) === true;
	}//end inGroup()

	/**
	 * Add an account to a group; true when it is a member afterwards.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function addToGroup(string $gid, string $uid): bool {
		$group = $this->groups->get($gid);
		$user  = $this->users->get($uid);
		if ($group === null || $user === null) {
			return false;
		}

		$group->addUser($user);

		return true;
	}//end addToGroup()

	/**
	 * Take an account out of a group; true when it is no member afterwards.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function removeFromGroup(string $gid, string $uid): bool {
		$group = $this->groups->get($gid);
		$user  = $this->users->get($uid);
		if ($group === null || $user === null) {
			return true;
		}

		$group->removeUser($user);

		return true;
	}//end removeFromGroup()

	/**
	 * Whether an account is a sub-admin of a group.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-group-may-name-its-sub-admins-from-the-cast
	 */
	public function isSubAdmin(string $gid, string $uid): bool {
		$group = $this->groups->get($gid);
		$user  = $this->users->get($uid);
		if ($group === null || $user === null || $this->subAdmins === null) {
			return false;
		}

		return $this->subAdmins->isSubAdminOfGroup($user, $group);
	}//end isSubAdmin()

	/**
	 * Make an account a sub-admin of a group.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The account id.
	 *
	 * @return bool False when the group, the account or the sub-admin service is not there.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-group-may-name-its-sub-admins-from-the-cast
	 */
	public function addSubAdmin(string $gid, string $uid): bool {
		$group = $this->groups->get($gid);
		$user  = $this->users->get($uid);
		if ($group === null || $user === null || $this->subAdmins === null) {
			return false;
		}

		try {
			$this->subAdmins->createSubAdmin($user, $group);
		} catch (Throwable $e) {
			$this->logger->warning('[ExampleSetAccounts] Could not make '.$uid.' sub-admin of '.$gid.': '.$e->getMessage());
			return false;
		}

		return true;
	}//end addSubAdmin()

	/**
	 * Take an account's sub-admin right on a group away.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The account id.
	 *
	 * @return bool True also when there was nothing to take away.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-group-may-name-its-sub-admins-from-the-cast
	 */
	public function removeSubAdmin(string $gid, string $uid): bool {
		$group = $this->groups->get($gid);
		$user  = $this->users->get($uid);
		if ($group === null || $user === null || $this->subAdmins === null) {
			return true;
		}

		try {
			$this->subAdmins->deleteSubAdmin($user, $group);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end removeSubAdmin()
	/**
	 * Whether creating an account failed because the id is taken (a unique key on the users table).
	 *
	 * @param Throwable $error What createUser threw.
	 *
	 * @return bool
	 */
	private static function isDuplicate(Throwable $error): bool {
		for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof \OCP\DB\Exception && $cause->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return true;
			}

			if (preg_match('/unique constraint|duplicate entry|duplicate key/i', $cause->getMessage()) === 1) {
				return true;
			}
		}

		return false;
	}//end isDuplicate()
}//end class
