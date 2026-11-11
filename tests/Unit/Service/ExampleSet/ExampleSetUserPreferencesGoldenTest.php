<?php

/**
 * Golden test for ExampleSetUserPreferences.
 *
 * Pins what refusal(), apply(), follow(), lost() and revert() return, and what
 * they write to the preference store, for every branch, ahead of a parameter
 * refactor of the private preference lookups.
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

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccounts;
use OCA\OpenRegister\Service\ExampleSet\UserPreferenceKey;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden pins for the user preference steps of an example set.
 *
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 */
class ExampleSetUserPreferencesGoldenTest extends TestCase {

	/**
	 * The preference store: uid => app => key => value.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private array $values = [];

	/**
	 * Every write, in order.
	 *
	 * @var array<int, string>
	 */
	private array $writes = [];

	/**
	 * Uids whose store access throws.
	 *
	 * @var array<int, string>
	 */
	private array $broken = [];

	/**
	 * Keys the user config flags sensitive; 'throw' makes the check throw.
	 *
	 * @var array<string, string>
	 */
	private array $sensitive = [];

	/**
	 * The unit under test.
	 *
	 * @var ExampleSetUserPreferences
	 */
	private ExampleSetUserPreferences $preferences;

	/**
	 * Build the unit over an in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->values = [
			'ann'  => ['alpha' => ['tour' => '1.0', 'theme' => 'dark']],
			'bob'  => ['alpha' => []],
			'root' => ['alpha' => []],
		];

		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturnCallback(fn (string $uid, string $app): array => array_keys($this->row($uid)[$app] ?? []));
		$config->method('getUserValue')->willReturnCallback(fn (string $uid, string $app, string $key, $default): string => ($this->row($uid)[$app][$key] ?? $default));
		$config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, $value): void {
			$this->row($uid);
			$this->writes[] = 'set '.$uid.' '.$app.' '.$key.'='.$value;
			$this->values[$uid][$app][$key] = (string) $value;
		});
		$config->method('deleteUserValue')->willReturnCallback(function (string $uid, string $app, string $key): void {
			$this->row($uid);
			$this->writes[] = 'delete '.$uid.' '.$app.' '.$key;
			unset($this->values[$uid][$app][$key]);
		});

		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('isSensitive')->willReturnCallback(function (string $uid, string $app, string $key): bool {
			if (($this->sensitive[$key] ?? '') === 'throw') {
				throw new RuntimeException('lookup failed');
			}

			return isset($this->sensitive[$key]);
		});

		$accounts = $this->createMock(ExampleSetAccounts::class);
		$accounts->method('inGroup')->willReturnCallback(fn (string $gid, string $uid): bool => ($gid === 'admin' && $uid === 'root'));

		$this->preferences = new ExampleSetUserPreferences(config: $config, userConfig: $userConfig, accounts: $accounts);
	}//end setUp()

	/**
	 * One account's row of the store; throws for a broken account.
	 *
	 * @param string $uid The account.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function row(string $uid): array {
		if (in_array($uid, $this->broken, true) === true) {
			throw new RuntimeException('store down for '.$uid);
		}

		return ($this->values[$uid] ?? []);
	}//end row()

	/**
	 * Every refusal branch.
	 *
	 * @return void
	 */
	public function testRefusal(): void {
		$this->sensitive = ['theme' => 'yes', 'flagged' => 'yes', 'tour' => 'throw'];
		$this->values['ann']['alpha']['flagged'] = 'x';

		$got = [
			'outsider'  => $this->preferences->refusal(preference: new UserPreferenceKey(entry: ['user' => 'eve', 'app' => 'alpha', 'key' => 'tour']), users: ['ann']),
			'admin'     => $this->preferences->refusal(preference: new UserPreferenceKey(entry: ['user' => 'root', 'app' => 'alpha', 'key' => 'tour']), users: ['ann']),
			'secret'    => $this->preferences->refusal(preference: new UserPreferenceKey(entry: ['user' => 'ann', 'app' => 'alpha', 'key' => 'api_key']), users: ['ann']),
			'sensitive' => $this->preferences->refusal(preference: new UserPreferenceKey(entry: ['user' => 'ann', 'app' => 'alpha', 'key' => 'theme']), users: ['ann']),
			'unset'     => $this->preferences->refusal(preference: new UserPreferenceKey(entry: ['user' => 'ann', 'app' => 'alpha', 'key' => 'colour']), users: ['ann']),
			'throws'    => $this->preferences->refusal(preference: new UserPreferenceKey(entry: ['user' => 'ann', 'app' => 'alpha', 'key' => 'tour']), users: ['ann']),
			'broken'    => '',
		];
		$this->broken  = ['ann'];
		$got['broken'] = $this->preferences->refusal(preference: new UserPreferenceKey(entry: ['user' => 'ann', 'app' => 'alpha', 'key' => 'theme']), users: ['ann']);

		$this->assertSame(
			[
				'outsider'  => 'account "eve" is not one the set creates or adopts',
				'admin'     => 'account "root" is one of Nextcloud\'s administrators and not in the set\'s cast; an example set never changes their preferences',
				'secret'    => 'user preference alpha api_key holds a secret; a set never writes one',
				'sensitive' => 'user preference alpha theme holds a secret; a set never writes one',
				'unset'     => '',
				'throws'    => 'user preference alpha tour holds a secret; a set never writes one',
				'broken'    => '',
			],
			$got
		);
	}//end testRefusal()

	/**
	 * apply() and follow() for an unchanged, a changed, a new and a failing write.
	 *
	 * @return void
	 */
	public function testApplyAndFollow(): void {
		$got = [
			'same'    => $this->preferences->apply(preference: new UserPreferenceKey(entry: ['user' => 'ann', 'app' => 'alpha', 'key' => 'tour']), value: '1.0'),
			'changed' => $this->preferences->apply(preference: new UserPreferenceKey(entry: ['user' => 'ann', 'app' => 'alpha', 'key' => 'tour']), value: '2.0'),
			'new'     => $this->preferences->apply(preference: new UserPreferenceKey(entry: ['user' => 'bob', 'app' => 'alpha', 'key' => 'tour']), value: '2.0'),
		];

		$entry               = $got['new']['entry'];
		$got['notRefreshed'] = $this->preferences->kept(entry: $entry);
		$got['sameValue']    = $this->preferences->follow(entry: $entry, value: '2.0');
		$got['followed']     = $this->preferences->follow(entry: $entry, value: '3.0');
		$this->broken        = ['bob'];
		$got['failedApply']  = $this->preferences->apply(preference: new UserPreferenceKey(entry: ['user' => 'bob', 'app' => 'alpha', 'key' => 'tour']), value: '4.0');
		$got['failedFollow'] = $this->preferences->follow(entry: $entry, value: '4.0');

		$this->assertSame(
			'{"same":{"error":"","entry":null},'
			.'"changed":{"error":"","entry":{"user":"ann","app":"alpha","key":"tour","had":true,"old":"1.0","new":"2.0"}},'
			.'"new":{"error":"","entry":{"user":"bob","app":"alpha","key":"tour","had":false,"old":"","new":"2.0"}},'
			.'"notRefreshed":{"error":"","entry":{"user":"bob","app":"alpha","key":"tour","had":false,"old":"","new":"2.0"},"wrote":false},'
			.'"sameValue":{"error":"","entry":{"user":"bob","app":"alpha","key":"tour","had":false,"old":"","new":"2.0"},"wrote":false},'
			.'"followed":{"error":"","entry":{"user":"bob","app":"alpha","key":"tour","had":false,"old":"","new":"3.0"},"wrote":true},'
			.'"failedApply":{"error":"store down for bob","entry":null},'
			.'"failedFollow":{"error":"store down for bob","entry":{"user":"bob","app":"alpha","key":"tour","had":false,"old":"","new":"2.0"},"wrote":false}}',
			json_encode($got)
		);
		$this->assertSame(['set ann alpha tour=2.0', 'set bob alpha tour=2.0', 'set bob alpha tour=3.0'], $this->writes);
	}//end testApplyAndFollow()

	/**
	 * lost() and every revert() outcome.
	 *
	 * @return void
	 */
	public function testLostAndRevert(): void {
		$had     = ['user' => 'ann', 'app' => 'alpha', 'key' => 'tour', 'had' => true, 'old' => '0.9', 'new' => '1.0'];
		$fresh   = ['user' => 'ann', 'app' => 'alpha', 'key' => 'theme', 'had' => false, 'old' => '', 'new' => 'dark'];
		$changed = ['user' => 'ann', 'app' => 'alpha', 'key' => 'tour', 'had' => true, 'old' => '0.9', 'new' => '0.5'];
		$gone    = ['user' => 'bob', 'app' => 'alpha', 'key' => 'tour', 'new' => '1.0'];

		$got = [
			'lostHad'      => $this->preferences->lost(entry: $had),
			'lostChanged'  => $this->preferences->lost(entry: $changed),
			'lostGone'     => $this->preferences->lost(entry: $gone),
			'revertLeft'   => $this->preferences->revert(entry: $changed),
			'revertHad'    => $this->preferences->revert(entry: $had),
			'revertFresh'  => $this->preferences->revert(entry: $fresh),
			'revertGone'   => $this->preferences->revert(entry: $gone),
		];
		$this->broken          = ['ann'];
		$got['lostBroken']     = $this->preferences->lost(entry: $had);
		$got['revertBroken']   = $this->preferences->revert(entry: $had);

		$this->assertSame(
			[
				'lostHad'      => false,
				'lostChanged'  => true,
				'lostGone'     => true,
				'revertLeft'   => 'left',
				'revertHad'    => 'restored',
				'revertFresh'  => 'restored',
				'revertGone'   => 'restored',
				'lostBroken'   => true,
				'revertBroken' => 'failed',
			],
			$got
		);
		$this->assertSame(['set ann alpha tour=0.9', 'delete ann alpha theme', 'delete bob alpha tour'], $this->writes);
		$this->assertSame(['ann' => ['alpha' => ['tour' => '0.9']], 'bob' => ['alpha' => []], 'root' => ['alpha' => []]], $this->values);
	}//end testLostAndRevert()
}//end class
