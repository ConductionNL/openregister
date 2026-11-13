<?php

/**
 * OpenRegister MachineCredentialService
 *
 * Service accounts: principals for integrations, owned by a team, that cannot
 * sign in interactively (REQ-SAT-004, D-C40-2).
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
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use InvalidArgumentException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Provision and recognise service accounts.
 *
 * A service account is a Nextcloud user carrying a marker in OpenRegister's
 * user config, with the team that owns it, a contact and a purpose beside it.
 *
 * 🔑 OWNED BY A TEAM, NOT BY ITS CREATOR. The administrator who provisions the
 * account is recorded for the audit, and nothing else reads it: no grant, no
 * token and no check follows the creator, so disabling or removing them leaves
 * the account working. An integration that ran as a person broke when that
 * person left; this is the whole point of the account.
 *
 * 🔑 NO PASSWORD ANYONE KNOWS. The account is created with a long random
 * password that is never stored or returned, so it has nothing to sign in
 * with but the tokens issued to it. The sign-in refusal
 * ({@see \OCA\OpenRegister\Listener\ServiceAccountLoginListener}) is the
 * second lock, for the ways in that do not need this password (single sign-on,
 * a reset).
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
 */
class MachineCredentialService {
	/**
	 * The app whose user config carries the marker and the account's details.
	 *
	 * @var string
	 */
	public const APP = 'openregister';

	/**
	 * The user-config key that marks a user as a service account.
	 *
	 * @var string
	 */
	public const MARKER = 'service_account';

	/**
	 * The prefix of a service account's user id, so it reads as one in every list.
	 *
	 * @var string
	 */
	public const UID_PREFIX = 'svc-';

	/**
	 * Length of the throwaway password the account is created with.
	 *
	 * @var int
	 */
	private const PASSWORD_LENGTH = 72;

	/**
	 * Constructor.
	 *
	 * @param IUserManager  $users  Creates the backing Nextcloud user.
	 * @param IGroupManager $groups Checks the owning team exists.
	 * @param IConfig       $config Keeps the marker and the account's details.
	 * @param ISecureRandom $random Makes the password nobody learns.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function __construct(
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
		private readonly IConfig $config,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Provision a service account.
	 *
	 * Refused, before anything is created, without a name, an owning team that
	 * exists, a contact name, a valid contact e-mail and a purpose: an account
	 * nobody can be asked about is the shared login this is meant to replace.
	 *
	 * @param ServiceAccountRequest $request   The name, owning team, contact and purpose.
	 * @param string                $createdBy The administrator who provisions it, for the record only.
	 *
	 * @return array<string, string> The account as {@see describe()} gives it.
	 *
	 * @throws InvalidArgumentException When a detail is missing or wrong, or the user id is taken.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function provision(ServiceAccountRequest $request, string $createdBy): array {
		$this->assertComplete(request: $request);

		$uid = self::uidFor(name: $request->name);
		if ($this->users->userExists($uid) === true) {
			throw new InvalidArgumentException(sprintf('A user "%s" already exists.', $uid));
		}

		$password = $this->random->generate(self::PASSWORD_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC.ISecureRandom::CHAR_SYMBOLS);
		$user     = $this->users->createUser($uid, $password);
		if (($user instanceof IUser) === false) {
			throw new InvalidArgumentException(sprintf('The user "%s" could not be created.', $uid));
		}

		$user->setDisplayName(trim($request->name));

		$details = [
			self::MARKER => '1',
			'service_account_owner_group' => trim($request->ownerGroup),
			'service_account_contact_name' => trim($request->contactName),
			'service_account_contact_email' => trim($request->contactEmail),
			'service_account_purpose' => trim($request->purpose),
			'service_account_created_by' => $createdBy,
		];
		foreach ($details as $key => $value) {
			$this->config->setUserValue($uid, self::APP, $key, $value);
		}

		return $this->describe(uid: $uid);
	}//end provision()

	/**
	 * Whether a user is a service account.
	 *
	 * @param string $uid The user id.
	 *
	 * @return bool True when the user carries the marker.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function isServiceAccount(string $uid): bool {
		return $this->config->getUserValue($uid, self::APP, self::MARKER, '') === '1';
	}//end isServiceAccount()

	/**
	 * A service account's owner, contact and purpose.
	 *
	 * @param string $uid The user id.
	 *
	 * @return array<string, string> The account; empty when the user is not a service account.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function describe(string $uid): array {
		if ($this->isServiceAccount(uid: $uid) === false) {
			return [];
		}

		$read = fn (string $key): string => (string)$this->config->getUserValue($uid, self::APP, $key, '');

		return [
			'uid' => $uid,
			'name' => (string)($this->users->get($uid)?->getDisplayName() ?? $uid),
			'ownerGroup' => $read('service_account_owner_group'),
			'contactName' => $read('service_account_contact_name'),
			'contactEmail' => $read('service_account_contact_email'),
			'purpose' => $read('service_account_purpose'),
			'createdBy' => $read('service_account_created_by'),
		];
	}//end describe()

	/**
	 * The user id a name gives: the prefix, then the name in lower case with
	 * anything but letters, digits and dashes turned into dashes.
	 *
	 * @param string $name The account's name.
	 *
	 * @return string The user id.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public static function uidFor(string $name): string {
		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name))), '-');

		return self::UID_PREFIX.substr($slug, 0, 56);
	}//end uidFor()

	/**
	 * Refuse a request that leaves the account without a name, owner, contact or purpose.
	 *
	 * @param ServiceAccountRequest $request The request.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Naming the first thing missing.
	 */
	private function assertComplete(ServiceAccountRequest $request): void {
		$required = [
			'name' => self::uidFor(name: $request->name) !== self::UID_PREFIX,
			'owner group' => trim($request->ownerGroup) !== '',
			'contact name' => trim($request->contactName) !== '',
			'contact e-mail' => filter_var(trim($request->contactEmail), FILTER_VALIDATE_EMAIL) !== false,
			'purpose' => trim($request->purpose) !== '',
		];
		foreach ($required as $what => $present) {
			if ($present === false) {
				throw new InvalidArgumentException(sprintf('A service account needs a %s.', $what));
			}
		}

		if ($this->groups->groupExists(trim($request->ownerGroup)) === false) {
			throw new InvalidArgumentException(sprintf('The owner group "%s" does not exist.', trim($request->ownerGroup)));
		}
	}//end assertComplete()
}//end class
