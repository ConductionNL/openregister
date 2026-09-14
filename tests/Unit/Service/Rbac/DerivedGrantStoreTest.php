<?php

/**
 * The derived set is kept, re-derived when a rule moves, and counted.
 *
 * The count is the product here, not a nicety. Narrowing a rule that reached two
 * hundred people is a decision somebody should see the size of before they walk
 * away from the screen, and an access change nobody is told about is the one
 * that surprises an auditor.
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tasks 8.1 and 8.3: what is stored, and what a rule change reports.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\DerivedGrantStore
 */
class DerivedGrantStoreTest extends TestCase {

	/**
	 * The preferences every case reads and writes, keyed `<user>/<key>`.
	 *
	 * @var array<string, string>
	 */
	private array $preferences = [];

	/**
	 * The declared rules, as the app config holds them.
	 *
	 * @var string
	 */
	private string $rules = '';

	/**
	 * Reset the fixture store before every case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->preferences = [];
		$this->rules = '';
	}//end setUp()

	/**
	 * A store over the in-memory preferences above.
	 *
	 * @param array<int, string> $accounts The accounts the instance has seen.
	 *
	 * @return DerivedGrantStore The store under test.
	 */
	private function storeFor(array $accounts): DerivedGrantStore {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, string $default = ''): string {
				return ($this->preferences[$userId . '/' . $key] ?? $default);
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, string $value): void {
				$this->preferences[$userId . '/' . $key] = $value;
			}
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (): string => $this->rules);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			function (callable $callback) use ($accounts): void {
				foreach ($accounts as $uid) {
					$user = $this->createMock(originalClassName: IUser::class);
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
	}//end storeFor()

	/**
	 * The rule set as an administrator writes it.
	 *
	 * @param array<int, mixed> $rules The rules.
	 *
	 * @return void
	 */
	private function declareRules(array $rules): void {
		$this->rules = (string)json_encode($rules);
	}//end declareRules()

	/**
	 * 🔴 A sign-in's claims become the grants the rules declare, and are kept.
	 *
	 * @return void
	 */
	public function testASignInStoresTheClaimsAndTheGrants(): void {
		$this->declareRules(
			[['claim' => 'department', 'equals' => 'vergunningen', 'groups' => ['behandelaars']]]
		);

		$store = $this->storeFor(accounts: ['ana']);
		$grants = $store->apply(userId: 'ana', claims: ['department' => 'vergunningen']);

		$this->assertCount(1, $grants);
		$this->assertSame(['behandelaars'], $store->grantsFor(userId: 'ana')[0]['groups']);
		$this->assertSame(['department' => 'vergunningen'], $store->claimsFor(userId: 'ana'));
	}//end testASignInStoresTheClaimsAndTheGrants()

	/**
	 * 🔴 Narrowing a rule re-derives and reports how many accounts moved.
	 *
	 * @return void
	 */
	public function testNarrowingARuleReportsWhatMoved(): void {
		$this->declareRules(
			[['claim' => 'department', 'oneOf' => ['vergunningen', 'handhaving'], 'groups' => ['behandelaars']]]
		);

		$store = $this->storeFor(accounts: ['ana', 'bea', 'cas']);
		$store->apply(userId: 'ana', claims: ['department' => 'vergunningen']);
		$store->apply(userId: 'bea', claims: ['department' => 'handhaving']);
		$store->apply(userId: 'cas', claims: ['department' => 'directie']);

		// The control: all three are accounted for, and two of them hold access.
		$before = $store->reapply();
		$this->assertSame(3, $before['users']);
		$this->assertSame(0, $before['changed'], 'a re-run against unchanged rules moved something');
		$this->assertSame(2, $before['grantsAfter']);

		// The rule is narrowed to one department.
		$this->declareRules(
			[['claim' => 'department', 'equals' => 'vergunningen', 'groups' => ['behandelaars']]]
		);

		$after = $store->reapply();
		$this->assertSame(3, $after['users']);
		$this->assertSame(1, $after['changed'], 'the narrowed rule did not report the account it moved');
		$this->assertSame(['bea'], $after['accounts']);
		$this->assertSame(2, $after['grantsBefore']);
		$this->assertSame(1, $after['grantsAfter']);
	}//end testNarrowingARuleReportsWhatMoved()

	/**
	 * An account that never signed in with claims is not walked over.
	 *
	 * @return void
	 */
	public function testAnAccountWithNoClaimsIsNotCounted(): void {
		$this->declareRules([['claim' => 'department', 'equals' => 'vergunningen', 'groups' => ['behandelaars']]]);

		$store = $this->storeFor(accounts: ['ana', 'nooit']);
		$store->apply(userId: 'ana', claims: ['department' => 'vergunningen']);

		$this->assertSame(1, $store->reapply()['users']);
	}//end testAnAccountWithNoClaimsIsNotCounted()

	/**
	 * Forgetting an account leaves it with no derived access.
	 *
	 * @return void
	 */
	public function testForgettingAnAccountLeavesItWithNothing(): void {
		$this->declareRules([['claim' => 'department', 'equals' => 'vergunningen', 'groups' => ['behandelaars']]]);

		$store = $this->storeFor(accounts: ['ana']);
		$store->apply(userId: 'ana', claims: ['department' => 'vergunningen']);
		$this->assertCount(1, $store->grantsFor(userId: 'ana'));

		$store->forget(userId: 'ana');

		$this->assertSame([], $store->grantsFor(userId: 'ana'));
	}//end testForgettingAnAccountLeavesItWithNothing()

	/**
	 * A rule set nobody can parse derives nothing.
	 *
	 * Carrying on with the last one that worked would hide the typo for as long
	 * as nobody signs in from a department that needed it.
	 *
	 * @return void
	 */
	public function testAnUnreadableRuleSetDerivesNothing(): void {
		$this->rules = '{not json at all';

		$store = $this->storeFor(accounts: ['ana']);

		$this->assertSame([], $store->rules());
		$this->assertSame([], $store->apply(userId: 'ana', claims: ['department' => 'vergunningen']));
	}//end testAnUnreadableRuleSetDerivesNothing()
}//end class
