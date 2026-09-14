<?php

/**
 * Where derived access is kept, and what changes when a rule moves.
 *
 * The derivation itself is pure and lives in {@see DerivedGrantResolver}. This
 * is the half that touches the instance: the rules an administrator wrote, the
 * claims one account signed in with, the grants those two produced, and the
 * recount when the rules change.
 *
 * NO NEW TABLE. The claims and the grants are user preferences, which is what
 * they are: per account, small, and rewritten at every sign-in. A table would
 * have needed a migration to hold three keys and a sweep to keep them true.
 *
 * WHY A RULE CHANGE IS REPORTED IN NUMBERS. An access change nobody is told
 * about is the one that surprises an auditor. Narrowing a rule that reached 240
 * objects is a decision somebody should see the size of before they walk away
 * from the screen (design D-11).
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Reads the rules, keeps the derived grants, and recounts when a rule moves.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class DerivedGrantStore {

	/**
	 * The app every key below belongs to.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * The app-config key holding the declared rules.
	 *
	 * Instance-wide rather than per register, because a claim mapping is a
	 * statement about the identity provider and not about one register. Each
	 * rule names the area it reaches with `scopedTo`.
	 *
	 * @var string
	 */
	public const RULES_KEY = 'claim_derived_grants';

	/**
	 * The user-preference key holding the claims one account signed in with.
	 *
	 * @var string
	 */
	public const CLAIMS_KEY = 'identity_claims';

	/**
	 * The user-preference key holding the grants those claims produced.
	 *
	 * @var string
	 */
	public const GRANTS_KEY = 'derived_grants';

	/**
	 * Constructor.
	 *
	 * @param IConfig              $config      Per-user preferences.
	 * @param IAppConfig           $appConfig   Where the rules are declared.
	 * @param IUserManager         $userManager The accounts a re-run walks.
	 * @param DerivedGrantResolver $resolver    The pure derivation.
	 * @param LoggerInterface      $logger      Where a rule nobody can read is reported.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly IUserManager $userManager,
		private readonly DerivedGrantResolver $resolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The declared rules.
	 *
	 * @return array<int, mixed> The rules, or an empty list when none are declared.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function rules(): array {
		try {
			$raw = $this->appConfig->getValueString(app: self::APP_ID, key: self::RULES_KEY, default: '');
		} catch (\Throwable $e) {
			return [];
		}

		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			// A rule set nobody can parse derives nothing. The other reading,
			// carrying on with the last one that worked, hides the typo for as
			// long as nobody signs in from a department that needed it.
			$this->logger->warning(
				message: '[DerivedGrantStore] The declared claim rules are not readable JSON; deriving nothing',
				context: ['file' => __FILE__, 'line' => __LINE__, 'key' => self::RULES_KEY]
			);
			return [];
		}

		return $decoded;
	}//end rules()

	/**
	 * The grants one account currently holds by derivation.
	 *
	 * @param string|null $userId The account.
	 *
	 * @return array<int, array<string, mixed>> The grants.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function grantsFor(?string $userId): array {
		if ($userId === null || $userId === '') {
			return [];
		}

		return $this->decodeList(
			raw: $this->config->getUserValue($userId, self::APP_ID, self::GRANTS_KEY, '')
		);
	}//end grantsFor()

	/**
	 * The claims one account last signed in with.
	 *
	 * @param string $userId The account.
	 *
	 * @return array<string, mixed> The claims.
	 */
	public function claimsFor(string $userId): array {
		$raw = $this->config->getUserValue($userId, self::APP_ID, self::CLAIMS_KEY, '');
		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end claimsFor()

	/**
	 * Derive one account's grants from these claims, and keep both.
	 *
	 * The claims are kept so a rule change can be re-run without waiting for
	 * everybody to sign in again, which is the difference between an access
	 * change taking effect today and taking effect over a fortnight.
	 *
	 * @param string               $userId The account.
	 * @param array<string, mixed> $claims The claims the provider asserted.
	 *
	 * @return array<int, array<string, mixed>> The grants that were stored.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function apply(string $userId, array $claims): array {
		$derived = $this->resolver->derive(claims: $claims, rules: $this->rules());

		foreach ($derived['skipped'] as $sentence) {
			$this->logger->warning(
				message: '[DerivedGrantStore] ' . $sentence,
				context: ['file' => __FILE__, 'line' => __LINE__, 'userId' => $userId]
			);
		}

		$this->config->setUserValue($userId, self::APP_ID, self::CLAIMS_KEY, (string)json_encode($claims));
		$this->config->setUserValue($userId, self::APP_ID, self::GRANTS_KEY, (string)json_encode($derived['grants']));

		return $derived['grants'];
	}//end apply()

	/**
	 * Forget one account's derived access.
	 *
	 * Called when a sign-in asserts nothing. Keeping the previous set would keep
	 * somebody in a department they left, which is the failure this whole
	 * mechanism is supposed to end rather than automate.
	 *
	 * @param string $userId The account.
	 *
	 * @return void
	 */
	public function forget(string $userId): void {
		$this->config->setUserValue($userId, self::APP_ID, self::GRANTS_KEY, '[]');
	}//end forget()

	/**
	 * Re-run the derivation for every account that has claims, and count what moved.
	 *
	 * @return array{users: integer, changed: integer, grantsBefore: integer, grantsAfter: integer, accounts: array<int, string>}
	 *         What the re-run did.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function reapply(): array {
		$accounts = $this->accountsWithClaims();

		$changed = [];
		$before = 0;
		$after = 0;

		foreach ($accounts as $userId) {
			$held = $this->grantsFor(userId: $userId);
			$holds = $this->apply(userId: $userId, claims: $this->claimsFor(userId: $userId));

			$before += count($held);
			$after += count($holds);

			if (json_encode($held) !== json_encode($holds)) {
				$changed[] = $userId;
			}
		}

		return [
			'users' => count($accounts),
			'changed' => count($changed),
			'grantsBefore' => $before,
			'grantsAfter' => $after,
			'accounts' => $changed,
		];
	}//end reapply()

	/**
	 * Every account that has claims stored.
	 *
	 * Walked over the accounts this instance has seen rather than queried by
	 * key: the preference API finds users by an exact VALUE, and every account
	 * here holds a different one. The walk is fine for what this is, an
	 * administrator pressing a button after editing a rule.
	 *
	 * @return array<int, string> The account ids.
	 */
	private function accountsWithClaims(): array {
		$accounts = [];

		try {
			$this->userManager->callForSeenUsers(
				function (IUser $user) use (&$accounts): ?bool {
					if ($this->claimsFor(userId: $user->getUID()) !== []) {
						$accounts[] = $user->getUID();
					}

					// Null rather than false: the callback's return value is
					// what stops the walk, and stopping it here would silently
					// leave the rest of the accounts unre-derived.
					return null;
				}
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[DerivedGrantStore] Could not walk the accounts to re-derive; reporting none',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}

		return $accounts;
	}//end accountsWithClaims()

	/**
	 * A stored JSON list, or an empty one.
	 *
	 * @param string $raw The stored value.
	 *
	 * @return array<int, array<string, mixed>> The list.
	 */
	private function decodeList(string $raw): array {
		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, 'is_array'));
	}//end decodeList()
}//end class
