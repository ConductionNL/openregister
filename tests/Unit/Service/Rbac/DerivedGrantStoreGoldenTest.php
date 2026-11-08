<?php

/**
 * Golden outcomes of the claim-derived grant store, pinned before its refactor.
 *
 * A derived grant is access admitted because of an identity-provider claim;
 * no grant is access refused. Each test records a transcript of what the store
 * answered and what it wrote, as the code gave it before its two config stores
 * were bundled: grants derived for a matching claim, none for a claim that
 * matches no rule, none when the rules cannot be read, a forgotten account
 * holding nothing, and a re-derivation that drops what no longer matches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins admitted and refused derived grants.
 */
class DerivedGrantStoreGoldenTest extends TestCase {

	/**
	 * The per-user preferences, `uid/key` => value.
	 *
	 * @var array<string, string>
	 */
	private array $preferences = [];

	/**
	 * The stored rule set, or a Throwable to throw on read.
	 *
	 * @var string|RuntimeException
	 */
	private string|RuntimeException $rules = '';

	/**
	 * The rule set the store reads in most tests.
	 *
	 * @var string
	 */
	private const RULES = '[{"claim":"department","equals":"vergunningen","groups":["behandelaars"]},'
		. '{"claim":"role","oneOf":["teamleider","manager"],"groups":["leiding"],"scopedTo":{"schemas":["zaak"]}},'
		. '{"equals":"nobody"}]';

	/**
	 * Build the store over the in-memory preferences.
	 *
	 * @param string[]|RuntimeException $accounts The accounts the walk visits, or a failure.
	 *
	 * @return DerivedGrantStore The store.
	 */
	private function store(array|RuntimeException $accounts=[]): DerivedGrantStore {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $userId, string $app, string $key, $default = ''): string => ($this->preferences[$userId . '/' . $key] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, $value): void {
				$this->preferences[$userId . '/' . $key] = $value;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (): string {
				if ($this->rules instanceof RuntimeException) {
					throw $this->rules;
				}

				return $this->rules;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			function (callable $callback) use ($accounts): void {
				if ($accounts instanceof RuntimeException) {
					throw $accounts;
				}

				foreach ($accounts as $uid) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$callback($user);
				}
			}
		);

		return new DerivedGrantStore(
			config: $config,
			appConfig: $appConfig,
			userManager: $userManager,
			resolver: new DerivedGrantResolver(),
			logger: new NullLogger()
		);
	}//end store()

	/**
	 * Signing in derives, stores and answers grants; unmatched claims derive none.
	 *
	 * @return void
	 */
	public function testSignInIsPinned(): void {
		$this->rules = self::RULES;
		$store = $this->store();

		$transcript = [
			'rules' => count($store->rules()),
			'ana' => $store->apply(userId: 'ana', claims: ['department' => 'vergunningen', 'role' => 'manager']),
			'bob' => $store->apply(userId: 'bob', claims: ['department' => 'financien']),
			'anaHeld' => $store->grantsFor(userId: 'ana'),
			'anaClaims' => $store->claimsFor(userId: 'ana'),
			'null' => $store->grantsFor(userId: null),
			'empty' => $store->grantsFor(userId: ''),
			'unknown' => $store->grantsFor(userId: 'nobody'),
		];
		$store->forget(userId: 'ana');
		$transcript['anaForgotten'] = $store->grantsFor(userId: 'ana');
		$transcript['anaClaimsKept'] = $store->claimsFor(userId: 'ana');
		$transcript['written'] = $this->preferences;

		$this->assertSame(self::expectedSignIn(), $transcript);
	}//end testSignInIsPinned()

	/**
	 * The recorded sign-in transcript.
	 *
	 * @return array<string, mixed> The transcript.
	 */
	private static function expectedSignIn(): array {
		return [
			'rules' => 3,
			'ana' => [
				[
					'rule' => '0',
					'claim' => 'department',
					'groups' => [
						'behandelaars',
					],
					'role' => null,
					'scopedTo' => null,
					'until' => null,
				],
				[
					'rule' => '1',
					'claim' => 'role',
					'groups' => [
						'leiding',
					],
					'role' => null,
					'scopedTo' => [
						'schemas' => [
							'zaak',
						],
					],
					'until' => null,
				],
			],
			'bob' => [],
			'anaHeld' => [
				[
					'rule' => '0',
					'claim' => 'department',
					'groups' => [
						'behandelaars',
					],
					'role' => null,
					'scopedTo' => null,
					'until' => null,
				],
				[
					'rule' => '1',
					'claim' => 'role',
					'groups' => [
						'leiding',
					],
					'role' => null,
					'scopedTo' => [
						'schemas' => [
							'zaak',
						],
					],
					'until' => null,
				],
			],
			'anaClaims' => [
				'department' => 'vergunningen',
				'role' => 'manager',
			],
			'null' => [],
			'empty' => [],
			'unknown' => [],
			'anaForgotten' => [],
			'anaClaimsKept' => [
				'department' => 'vergunningen',
				'role' => 'manager',
			],
			'written' => [
				'ana/identity_claims' => '{"department":"vergunningen","role":"manager"}',
				'ana/derived_grants' => '[]',
				'bob/identity_claims' => '{"department":"financien"}',
				'bob/derived_grants' => '[]',
			],
		];
	}//end expectedSignIn()

	/**
	 * Unreadable or failing rules derive nothing, and a malformed stored
	 * grants list keeps only its array entries.
	 *
	 * @return void
	 */
	public function testUnreadableStateIsPinned(): void {
		$this->rules = '{not json';
		$store = $this->store();
		$transcript = ['badJson' => $store->apply(userId: 'ana', claims: ['department' => 'vergunningen'])];

		$this->rules = new RuntimeException('config down');
		$transcript['throws'] = $store->apply(userId: 'ana', claims: ['department' => 'vergunningen']);
		$transcript['rulesWhenThrowing'] = $store->rules();

		$this->rules = '   ';
		$transcript['blank'] = $store->rules();

		$this->preferences['cees/' . DerivedGrantStore::GRANTS_KEY] = '[{"groups":["x"]}, 5, "y", null]';
		$this->preferences['cees/' . DerivedGrantStore::CLAIMS_KEY] = '"scalar"';
		$this->preferences['dirk/' . DerivedGrantStore::GRANTS_KEY] = 'not json';
		$transcript['ceesHeld'] = $store->grantsFor(userId: 'cees');
		$transcript['ceesClaims'] = $store->claimsFor(userId: 'cees');
		$transcript['dirkHeld'] = $store->grantsFor(userId: 'dirk');

		$this->assertSame(self::expectedUnreadable(), $transcript);
	}//end testUnreadableStateIsPinned()

	/**
	 * The recorded unreadable-state transcript.
	 *
	 * @return array<string, mixed> The transcript.
	 */
	private static function expectedUnreadable(): array {
		return [
			'badJson' => [],
			'throws' => [],
			'rulesWhenThrowing' => [],
			'blank' => [],
			'ceesHeld' => [
				[
					'groups' => [
						'x',
					],
				],
			],
			'ceesClaims' => [],
			'dirkHeld' => [],
		];
	}//end expectedUnreadable()

	/**
	 * Re-deriving drops what no longer matches and reports the change.
	 *
	 * @return void
	 */
	public function testReapplyIsPinned(): void {
		$this->rules = self::RULES;
		$store = $this->store(accounts: ['ana', 'bob', 'cees']);
		$store->apply(userId: 'ana', claims: ['department' => 'vergunningen']);
		$store->apply(userId: 'cees', claims: ['role' => 'teamleider']);

		// The rules change: teamleider no longer leads.
		$this->rules = '[{"claim":"department","equals":"vergunningen","groups":["behandelaars"]}]';

		$transcript = [
			'report' => $store->reapply(),
			'ana' => $store->grantsFor(userId: 'ana'),
			'cees' => $store->grantsFor(userId: 'cees'),
			'bobTouched' => array_key_exists('bob/' . DerivedGrantStore::GRANTS_KEY, $this->preferences),
			'walkFails' => $this->store(accounts: new RuntimeException('users down'))->reapply(),
		];

		$this->assertSame(self::expectedReapply(), $transcript);
	}//end testReapplyIsPinned()

	/**
	 * The recorded re-derivation transcript.
	 *
	 * @return array<string, mixed> The transcript.
	 */
	private static function expectedReapply(): array {
		return [
			'report' => [
				'users' => 2,
				'changed' => 1,
				'grantsBefore' => 2,
				'grantsAfter' => 1,
				'accounts' => [
					'cees',
				],
			],
			'ana' => [
				[
					'rule' => '0',
					'claim' => 'department',
					'groups' => [
						'behandelaars',
					],
					'role' => null,
					'scopedTo' => null,
					'until' => null,
				],
			],
			'cees' => [],
			'bobTouched' => false,
			'walkFails' => [
				'users' => 0,
				'changed' => 0,
				'grantsBefore' => 0,
				'grantsAfter' => 0,
				'accounts' => [],
			],
		];
	}//end expectedReapply()
}//end class
