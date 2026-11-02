<?php

/**
 * Tests for the per-user preferences a fragment declares, through the real
 * catalogue, installer and remover: the demo staff's tour state in dossiq.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetUserPreferencesTest extends ExampleSetTestCase {
	private const KEY = 'pref_walkthrough_completed_version';

	protected function setUp(): void {
		parent::setUp();
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * A fragment of app alpha with the given preferences and one service account.
	 *
	 * @param array<int, array<string, mixed>> $preferences The declared preferences.
	 *
	 * @return void
	 */
	private function alpha(array $preferences): void {
		$this->app(app: 'alpha', fragment: [
			'serviceAccounts' => [['key' => 'background', 'uid' => 'demo_background']],
			'userPreferences' => $preferences,
		]);
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function testTheStaffSkipTheTourAndRemoveTakesTheValuesAway(): void {
		$this->alpha(preferences: [
			['user' => '{{cast:esra.uid}}', 'key' => self::KEY, 'value' => '1.2.0'],
			['user' => '{{account:background.uid}}', 'app' => 'alpha', 'key' => self::KEY, 'value' => '1.2.0'],
		]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('1.2.0', $this->userValues['esra.demir']['alpha'][self::KEY]);
		$this->assertSame('1.2.0', $this->userValues['demo_background']['alpha'][self::KEY]);
		$this->assertSame(
			['alpha/esra.demir/'.self::KEY => 'created', 'alpha/demo_background/'.self::KEY => 'created'],
			$report['userPreferences']
		);

		$again = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertTrue($again['ok']);
		$this->assertSame(['kept', 'kept'], array_values($again['userPreferences']), 'a second install writes nothing');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertSame(2, $removed['removed']['user preferences']);
		$this->assertSame([], $this->userValues['esra.demir']['alpha']);
		$this->assertSame([], $this->config, 'the record is gone');
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function testAnAdoptedAccountGetsItsOwnValueBackUnlessItChangedSince(): void {
		$this->accounts->users['sanne.devries'] = ['displayName' => 'Sanne', 'email' => '', 'password' => 'theirs'];
		$this->accounts->users['esra.demir']    = ['displayName' => 'Esra', 'email' => '', 'password' => 'theirs'];
		$this->userValues['sanne.devries']['alpha'][self::KEY] = '0.9.0';
		$this->userValues['esra.demir']['alpha'][self::KEY]    = '0.9.0';
		$this->alpha(preferences: [
			['user' => '{{cast:sanne.uid}}', 'key' => self::KEY, 'value' => '1.2.0'],
			['user' => '{{cast:esra.uid}}', 'key' => self::KEY, 'value' => '1.2.0'],
		]);
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), adopt: ['sanne.devries', 'esra.demir'], today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('1.2.0', $this->userValues['sanne.devries']['alpha'][self::KEY]);

		$this->userValues['esra.demir']['alpha'][self::KEY] = '1.3.0';
		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame('0.9.0', $this->userValues['sanne.devries']['alpha'][self::KEY], 'the old value comes back');
		$this->assertSame('1.3.0', $this->userValues['esra.demir']['alpha'][self::KEY], 'a value changed since stays');
		$this->assertContains('user preference alpha/esra.demir/'.self::KEY.', which somebody changed after the install', $removed['left']);
	}

	/**
	 * An account outside the set, an administrator outside the cast and a secret key are refused before any write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function testAnAccountTheSetDoesNotOwnAnAdministratorOutsideTheCastAndASecretAreRefusedBeforeAnyWrite(): void {
		$this->accounts->users['someone'] = ['displayName' => 'Someone', 'email' => '', 'password' => 'x'];
		$this->accounts->users['admin']   = ['displayName' => 'Admin', 'email' => '', 'password' => 'x'];
		$this->accounts->groups['admin']  = ['admin'];
		$this->alpha(preferences: [
			['user' => 'someone', 'key' => self::KEY, 'value' => '1.2.0'],
			['user' => 'admin', 'key' => self::KEY, 'value' => '1.2.0'],
			['user' => '{{cast:esra.uid}}', 'key' => 'api_token', 'value' => 'abc'],
		]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$where  = 'alpha/lib/Settings/example-sets/demo.json: user preference ';
		$admins = 'account "admin" is one of Nextcloud\'s administrators and not in the set\'s cast; an example set never changes their preferences';
		$this->assertSame(
			expected: [
				$where.'"'.self::KEY.'": account "someone" is not one the set creates or adopts',
				$where.'"'.self::KEY.'": '.$admins,
				$where.'"api_token": user preference alpha api_token holds a secret; a set never writes one',
			],
			actual: array_values(array_filter($report['refusals'], static fn (string $line): bool => str_contains($line, 'user preference')))
		);
		$this->assertSame(expected: [], actual: $this->userValues);
		$this->assertSame(expected: 0, actual: $this->accounts->userCreates);
	}

	/**
	 * An administrator the cast declares, here adopted, gets the preference and stays the only administrator.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function testAnAdministratorTheCastDeclaresGetsThePreference(): void {
		$this->accounts->users['sanne.devries'] = ['displayName' => 'Sanne', 'email' => '', 'password' => 'theirs'];
		$this->accounts->groups['admin']        = ['sanne.devries'];
		$this->alpha(preferences: [['user' => '{{cast:sanne.uid}}', 'key' => self::KEY, 'value' => '1.2.0']]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), adopt: ['sanne.devries'], today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: '1.2.0', actual: $this->userValues['sanne.devries']['alpha'][self::KEY]);
		$this->assertSame(expected: ['sanne.devries'], actual: $this->accounts->groups['admin'], message: 'the set adds and removes no administrator');
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function testAKeyNextcloudKeepsAsSensitiveIsRefused(): void {
		$this->accounts->users['esra.demir'] = ['displayName' => 'Esra', 'email' => '', 'password' => 'theirs'];
		$this->userValues['esra.demir']['alpha']['remote_link']          = 'x';
		$this->sensitiveUserValues['esra.demir']['alpha']['remote_link'] = true;
		$this->alpha(preferences: [['user' => '{{cast:esra.uid}}', 'key' => 'remote_link', 'value' => 'y']]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), adopt: ['esra.demir'], today: $this->today());

		$this->assertSame(['alpha/lib/Settings/example-sets/demo.json: user preference "remote_link": user preference alpha remote_link holds a secret; a set never writes one'], $report['refusals']);
		$this->assertSame('x', $this->userValues['esra.demir']['alpha']['remote_link']);
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function testAPreferenceOfAnotherAppRefusesTheSet(): void {
		$this->alpha(preferences: [['user' => '{{cast:esra.uid}}', 'app' => 'beta', 'key' => self::KEY, 'value' => '1.2.0']]);

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('a fragment sets only its own app\'s preferences');
		$this->catalogue->gather(set: 'demo');
	}
}
