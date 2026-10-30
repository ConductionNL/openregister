<?php

/**
 * OpenRegister Example Set Profiles
 *
 * What a staff list shows of a cast person beyond their name and e-mail: the
 * profile headline (their function) and role, an absence (the dav app's out
 * of office, Nextcloud 28 and later) and a user status with a message. Each
 * is written on an account the set created, at every install, so a changed
 * cast reaches an instance that already has the account. A part whose app
 * is missing is skipped and named, never fatal.
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
 * @spec openspec/changes/example-set-staff-profiles/specs/example-sets/spec.md#requirement-a-cast-person-may-carry-a-function-a-role-an-absence-and-a-status
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTimeImmutable;
use OCP\Accounts\IAccountManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Writes the profile, absence and status of a cast person's account.
 *
 * @spec openspec/changes/example-set-staff-profiles/specs/example-sets/spec.md#requirement-a-cast-person-may-carry-a-function-a-role-an-absence-and-a-status
 */
class ExampleSetProfiles {

	/**
	 * The dav app's absence service (out of office).
	 */
	private const ABSENCE_SERVICE = 'OCA\DAV\Service\AbsenceService';

	/**
	 * The user_status app's status service.
	 */
	private const STATUS_SERVICE = 'OCA\UserStatus\Service\StatusService';

	/**
	 * Constructor.
	 *
	 * @param IUserManager       $users     The accounts.
	 * @param IAccountManager    $profiles  The profile properties.
	 * @param ContainerInterface $container Hands out the absence and status services when their apps are there.
	 */
	public function __construct(
		private readonly IUserManager $users,
		private readonly IAccountManager $profiles,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * What of a person's profile is to be written, with absence days resolved against the install day.
	 *
	 * @param array<string, mixed> $person The cast person.
	 * @param DateTimeImmutable    $today  The install day.
	 *
	 * @return array{headline: string, role: string, organisation: string, absence: array<string, string>|null, status: array<string, string>|null}
	 *
	 * @spec openspec/changes/example-set-staff-profiles/specs/example-sets/spec.md#requirement-a-cast-person-may-carry-a-function-a-role-an-absence-and-a-status
	 */
	public function fromPerson(array $person, DateTimeImmutable $today): array {
		$absence = null;
		if (is_array($person['absence'] ?? null) === true) {
			$from    = (int) ($person['absence']['fromDay'] ?? 0);
			$until   = (int) ($person['absence']['untilDay'] ?? 0);
			$absence = [
				'firstDay' => $today->modify(sprintf('%+d days', $from))->format('Y-m-d'),
				'lastDay'  => $today->modify(sprintf('%+d days', $until))->format('Y-m-d'),
				'status'   => (string) ($person['absence']['status'] ?? ''),
				'message'  => (string) ($person['absence']['message'] ?? ''),
			];
		}

		$status = null;
		if (is_array($person['status'] ?? null) === true) {
			$status = [
				'status'  => (string) ($person['status']['status'] ?? 'online'),
				'message' => (string) ($person['status']['message'] ?? ''),
				'icon'    => (string) ($person['status']['icon'] ?? ''),
			];
		}

		$roles = ($person['roles'] ?? '');
		if (is_array($roles) === true) {
			$roles = implode(', ', $roles);
		}

		return [
			'headline'     => (string) ($person['function'] ?? ''),
			'role'         => (string) $roles,
			'organisation' => (string) ($person['department'] ?? ''),
			'absence'      => $absence,
			'status'       => $status,
		];
	}//end fromPerson()

	/**
	 * Write a profile on an account; the reasons a part was not written, empty when all were.
	 *
	 * @param string               $uid     The account.
	 * @param array<string, mixed> $profile What to write, as fromPerson() gives it.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-set-staff-profiles/specs/example-sets/spec.md#requirement-a-cast-person-may-carry-a-function-a-role-an-absence-and-a-status
	 */
	public function apply(string $uid, array $profile): array {
		$user = $this->users->get($uid);
		if ($user === null) {
			return ['account '.$uid.' is not there'];
		}

		return array_values(
			array_filter(
				[
					$this->writeProperties(user: $user, profile: $profile),
					$this->writeAbsence(user: $user, absence: $profile['absence']),
					$this->writeStatus(uid: $uid, status: $profile['status']),
				]
			)
		);
	}//end apply()

	/**
	 * Give an account the set adopted the function and role it lacks; a value it holds is never touched.
	 *
	 * Absence and status are a person's own day, not missing fields, so an adopted account keeps them as they are.
	 *
	 * @param string               $uid     The account.
	 * @param array<string, mixed> $profile What the cast declares, as fromPerson() gives it.
	 *
	 * @return array<int, string> Why a part was not written; empty when all were.
	 *
	 * @spec openspec/changes/example-set-adopted-accounts-gain-missing-profile/specs/example-sets/spec.md#requirement-an-adopted-account-gains-the-profile-fields-it-lacks
	 */
	public function fillMissing(string $uid, array $profile): array {
		$user = $this->users->get($uid);
		if ($user === null) {
			return ['account '.$uid.' is not there'];
		}

		try {
			$account = $this->profiles->getAccount($user);
			$missing = [];
			foreach ($this->propertyValues(profile: $profile) as $name => $value) {
				if ($value !== '' && trim((string) $account->getProperty($name)->getValue()) === '') {
					$missing[$name] = $value;
				}
			}

			if ($missing === []) {
				return [];
			}

			foreach ($missing as $name => $value) {
				$account->setProperty($name, $value, IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
			}

			$this->profiles->updateAccount($account);
		} catch (Throwable $e) {
			return ['profile of '.$uid.': '.$e->getMessage()];
		}

		return [];
	}//end fillMissing()

	/**
	 * The profile properties a cast person fills: function as headline, roles
	 * as role, department as organisation.
	 *
	 * @param array<string, mixed> $profile The profile, as fromPerson() gives it.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/example-set-staff-profiles/specs/example-sets/spec.md#requirement-a-cast-person-may-carry-a-function-a-role-an-absence-and-a-status
	 */
	private function propertyValues(array $profile): array {
		return [
			IAccountManager::PROPERTY_HEADLINE     => (string) ($profile['headline'] ?? ''),
			IAccountManager::PROPERTY_ROLE         => (string) ($profile['role'] ?? ''),
			IAccountManager::PROPERTY_ORGANISATION => (string) ($profile['organisation'] ?? ''),
		];
	}//end propertyValues()

	/**
	 * Write the headline, role and organisation; '' when written or when there is nothing to write.
	 *
	 * @param IUser                $user    The account.
	 * @param array<string, mixed> $profile The profile.
	 *
	 * @return string
	 */
	private function writeProperties(IUser $user, array $profile): string {
		if (array_filter($this->propertyValues(profile: $profile)) === []) {
			return '';
		}

		try {
			$account = $this->profiles->getAccount($user);
			foreach ($this->propertyValues(profile: $profile) as $name => $value) {
				if ($value !== '') {
					$account->setProperty($name, $value, IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
				}
			}

			$this->profiles->updateAccount($account);
		} catch (Throwable $e) {
			return 'profile of '.$user->getUID().': '.$e->getMessage();
		}

		return '';
	}//end writeProperties()

	/**
	 * Write the absence; '' when written or when there is none.
	 *
	 * @param IUser                      $user    The account.
	 * @param array<string, string>|null $absence The absence.
	 *
	 * @return string
	 */
	private function writeAbsence(IUser $user, ?array $absence): string {
		if ($absence === null) {
			return '';
		}

		try {
			$this->container->get(self::ABSENCE_SERVICE)->createOrUpdateAbsence(
				$user,
				$absence['firstDay'],
				$absence['lastDay'],
				$absence['status'],
				$absence['message']
			);
		} catch (Throwable $e) {
			return 'absence of '.$user->getUID().': '.$e->getMessage();
		}

		return '';
	}//end writeAbsence()

	/**
	 * Write the status and its message; '' when written or when there is none.
	 *
	 * @param string                     $uid    The account.
	 * @param array<string, string>|null $status The status.
	 *
	 * @return string
	 */
	private function writeStatus(string $uid, ?array $status): string {
		if ($status === null) {
			return '';
		}

		try {
			$service = $this->container->get(self::STATUS_SERVICE);
			$service->setStatus($uid, $status['status'], null, true);
			if ($status['message'] !== '') {
				$icon = null;
				if ($status['icon'] !== '') {
					$icon = $status['icon'];
				}

				$service->setCustomMessage($uid, $icon, $status['message'], null);
			}
		} catch (Throwable $e) {
			return 'status of '.$uid.': '.$e->getMessage();
		}

		return '';
	}//end writeStatus()
}//end class
