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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */
class ExampleSetAccounts {
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
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an account exists.
	 *
	 * @param string $uid The account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function userExists(string $uid): bool {
		return $this->users->userExists($uid) === true;
	}//end userExists()

	/**
	 * A new strong password.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function createUser(string $uid, string $displayName, string $email, string $password): string {
		try {
			$user = $this->users->createUser($uid, $password);
		} catch (Throwable $e) {
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function groupExists(string $gid): bool {
		return $this->groups->groupExists($gid) === true;
	}//end groupExists()

	/**
	 * Create a group; true when it exists afterwards.
	 *
	 * @param string $gid         The group id.
	 * @param string $displayName The name shown, or ''.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
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
}//end class
