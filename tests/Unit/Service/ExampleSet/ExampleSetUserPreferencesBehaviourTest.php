<?php

/**
 * Unit test for ExampleSetUserPreferences: whose preferences a set may write,
 * which keys it refuses as secrets, and how it writes, follows, proves and puts back.
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
 * @version GIT: <git_id>
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @covers \OCA\OpenRegister\Service\ExampleSet\UserPreferenceKey
 */
class ExampleSetUserPreferencesBehaviourTest extends TestCase {

	/**
	 * Nextcloud's config.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * The typed user config.
	 *
	 * @var IUserConfig&MockObject
	 */
	private IUserConfig&MockObject $userConfig;

	/**
	 * The accounts.
	 *
	 * @var ExampleSetAccounts&MockObject
	 */
	private ExampleSetAccounts&MockObject $accounts;

	/**
	 * The class under test.
	 *
	 * @var ExampleSetUserPreferences
	 */
	private ExampleSetUserPreferences $subject;

	/**
	 * Mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config     = $this->createMock(IConfig::class);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->accounts   = $this->createMock(ExampleSetAccounts::class);
		$this->subject    = new ExampleSetUserPreferences($this->config, $this->userConfig, $this->accounts);
	}//end setUp()

	/**
	 * An administrator outside the cast is refused as an administrator; another stranger as a stranger.
	 *
	 * @return void
	 */
	public function testAnAccountOutsideTheCastIsRefused(): void {
		$this->accounts->method('inGroup')->willReturnCallback(fn (string $gid, string $uid): bool => $gid === 'admin' && $uid === 'root');

		$this->assertSame(
			'account "root" is one of Nextcloud\'s administrators and not in the set\'s cast; an example set never changes their preferences',
			$this->subject->refusal(preference: new UserPreferenceKey(entry: ['user' => 'root', 'app' => 'files', 'key' => 'view']), users: ['anna'])
		);
		$this->assertSame(
			'account "bob" is not one the set creates or adopts',
			$this->subject->refusal(preference: new UserPreferenceKey(entry: ['user' => 'bob', 'app' => 'files', 'key' => 'view']), users: ['anna'])
		);
	}//end testAnAccountOutsideTheCastIsRefused()

	/**
	 * A key whose name reads as a secret is refused without asking the config.
	 *
	 * @return void
	 */
	public function testASecretKeyNameIsRefused(): void {
		$this->config->expects($this->never())->method('getUserKeys');
		$this->assertSame(
			'user preference mail api_key holds a secret; a set never writes one',
			$this->subject->refusal(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'mail', 'key' => 'api_key']), users: ['anna'])
		);
	}//end testASecretKeyNameIsRefused()

	/**
	 * A held key Nextcloud marks sensitive is refused; a held key it does not mark is allowed.
	 *
	 * @return void
	 */
	public function testAHeldKeyIsRefusedOnlyWhenSensitive(): void {
		$this->config->method('getUserKeys')->with('anna', 'mail')->willReturn(['signature', 'theme']);
		$this->userConfig->method('isSensitive')->willReturnCallback(
			fn (string $uid, string $app, string $key): bool => $key === 'signature'
		);

		$this->assertSame(
			'user preference mail signature holds a secret; a set never writes one',
			$this->subject->refusal(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'mail', 'key' => 'signature']), users: ['anna'])
		);
		$this->assertSame('', $this->subject->refusal(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'mail', 'key' => 'theme']), users: ['anna']));
	}//end testAHeldKeyIsRefusedOnlyWhenSensitive()

	/**
	 * A key the account does not hold is allowed without asking whether it is sensitive.
	 *
	 * @return void
	 */
	public function testAnUnheldKeyIsNotAskedAboutSensitivity(): void {
		$this->config->method('getUserKeys')->willReturn([]);
		$this->userConfig->expects($this->never())->method('isSensitive');
		$this->assertSame('', $this->subject->refusal(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'mail', 'key' => 'theme']), users: ['anna']));
	}//end testAnUnheldKeyIsNotAskedAboutSensitivity()

	/**
	 * A sensitivity check that throws reads as sensitive, and a key listing that throws reads as unheld.
	 *
	 * @return void
	 */
	public function testFailingLookupsLeanTowardsRefusalAndUnheld(): void {
		$this->config->method('getUserKeys')->willReturnOnConsecutiveCalls(['theme'], $this->throwException(new RuntimeException('x')));
		$this->userConfig->method('isSensitive')->willThrowException(new RuntimeException('unknown'));

		$this->assertSame(
			'user preference mail theme holds a secret; a set never writes one',
			$this->subject->refusal(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'mail', 'key' => 'theme']), users: ['anna'])
		);
		$this->assertSame('', $this->subject->refusal(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'mail', 'key' => 'theme']), users: ['anna']));
	}//end testFailingLookupsLeanTowardsRefusalAndUnheld()

	/**
	 * Applying the value the account already holds writes nothing and records nothing.
	 *
	 * @return void
	 */
	public function testApplyingTheHeldValueWritesNothing(): void {
		$this->config->method('getUserKeys')->willReturn(['theme']);
		$this->config->method('getUserValue')->willReturn('dark');
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertSame(['error' => '', 'entry' => null], $this->subject->apply(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'core', 'key' => 'theme']), value: 'dark'));
	}//end testApplyingTheHeldValueWritesNothing()

	/**
	 * Applying a new value writes it and records what was there.
	 *
	 * @return void
	 */
	public function testApplyingANewValueRecordsTheOldOne(): void {
		$this->config->method('getUserKeys')->willReturn(['theme']);
		$this->config->method('getUserValue')->willReturn('light');
		$this->config->expects($this->once())->method('setUserValue')->with('anna', 'core', 'theme', 'dark');

		$this->assertSame(
			['error' => '', 'entry' => ['user' => 'anna', 'app' => 'core', 'key' => 'theme', 'had' => true, 'old' => 'light', 'new' => 'dark']],
			$this->subject->apply(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'core', 'key' => 'theme']), value: 'dark')
		);
	}//end testApplyingANewValueRecordsTheOldOne()

	/**
	 * Applying an unheld key whose stored read fails records it as absent with an empty old value; a failed write is the error.
	 *
	 * @return void
	 */
	public function testApplyOfAnUnheldKeyAndAFailedWrite(): void {
		$this->config->method('getUserKeys')->willReturn([]);
		$this->config->method('getUserValue')->willThrowException(new RuntimeException('read'));
		$this->config->method('setUserValue')->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('read-only')));

		$this->assertSame(
			['error' => '', 'entry' => ['user' => 'anna', 'app' => 'core', 'key' => 'theme', 'had' => false, 'old' => '', 'new' => 'dark']],
			$this->subject->apply(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'core', 'key' => 'theme']), value: 'dark')
		);
		$this->assertSame(['error' => 'read-only', 'entry' => null], $this->subject->apply(preference: new UserPreferenceKey(entry: ['user' => 'anna', 'app' => 'core', 'key' => 'theme']), value: 'dark'));
	}//end testApplyOfAnUnheldKeyAndAFailedWrite()

	/**
	 * Following outside a refresh, or to the value already written, writes nothing.
	 *
	 * @return void
	 */
	public function testFollowWritesOnlyOnARefreshWithANewValue(): void {
		$entry = ['user' => 'anna', 'app' => 'core', 'key' => 'theme', 'had' => true, 'old' => 'light', 'new' => 'dark'];
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertSame(['error' => '', 'entry' => $entry, 'wrote' => false], $this->subject->kept(entry: $entry));
		$this->assertSame(['error' => '', 'entry' => $entry, 'wrote' => false], $this->subject->follow(entry: $entry, value: 'dark'));
	}//end testFollowWritesOnlyOnARefreshWithANewValue()

	/**
	 * A refresh to a new value writes it and keeps the recorded old value; a failed write keeps the entry.
	 *
	 * @return void
	 */
	public function testFollowOnARefreshWritesTheNewValue(): void {
		$entry = ['user' => 'anna', 'app' => 'core', 'key' => 'theme', 'had' => true, 'old' => 'light', 'new' => 'dark'];
		$this->config->method('getUserKeys')->willReturn(['theme']);
		$this->config->method('getUserValue')->willReturn('dark');
		$this->config->method('setUserValue')->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('locked')));

		$this->assertSame(
			['error' => '', 'entry' => ['user' => 'anna', 'app' => 'core', 'key' => 'theme', 'had' => true, 'old' => 'light', 'new' => 'blue'], 'wrote' => true],
			$this->subject->follow(entry: $entry, value: 'blue')
		);
		$this->assertSame(['error' => 'locked', 'entry' => $entry, 'wrote' => false], $this->subject->follow(entry: $entry, value: 'blue'));
	}//end testFollowOnARefreshWritesTheNewValue()

	/**
	 * A preference is lost when its stored value is no longer the one written.
	 *
	 * @return void
	 */
	public function testAPreferenceIsLostWhenItsValueChanged(): void {
		$entry = ['user' => 'anna', 'app' => 'core', 'key' => 'theme', 'new' => 'dark'];
		$this->config->method('getUserValue')->with('anna', 'core', 'theme', '')->willReturnOnConsecutiveCalls('dark', 'light');

		$this->assertFalse($this->subject->lost(entry: $entry));
		$this->assertTrue($this->subject->lost(entry: $entry));
	}//end testAPreferenceIsLostWhenItsValueChanged()

	/**
	 * A revert leaves a value someone changed since.
	 *
	 * @return void
	 */
	public function testARevertLeavesAChangedValue(): void {
		$this->config->method('getUserKeys')->willReturn(['theme']);
		$this->config->method('getUserValue')->willReturn('blue');
		$this->config->expects($this->never())->method('setUserValue');
		$this->config->expects($this->never())->method('deleteUserValue');

		$this->assertSame('left', $this->subject->revert(entry: ['user' => 'anna', 'app' => 'core', 'key' => 'theme', 'had' => true, 'old' => 'light', 'new' => 'dark']));
	}//end testARevertLeavesAChangedValue()

	/**
	 * A revert of a value the account had puts the old value back.
	 *
	 * @return void
	 */
	public function testARevertRestoresTheOldValue(): void {
		$this->config->method('getUserKeys')->willReturn(['theme']);
		$this->config->method('getUserValue')->willReturn('dark');
		$this->config->expects($this->once())->method('setUserValue')->with('anna', 'core', 'theme', 'light');
		$this->config->expects($this->never())->method('deleteUserValue');

		$this->assertSame('restored', $this->subject->revert(entry: ['user' => 'anna', 'app' => 'core', 'key' => 'theme', 'had' => true, 'old' => 'light', 'new' => 'dark']));
	}//end testARevertRestoresTheOldValue()

	/**
	 * A revert of a value the account did not have deletes it, also when it is gone already; a failing delete fails.
	 *
	 * @return void
	 */
	public function testARevertDeletesAValueTheAccountDidNotHave(): void {
		$entry = ['user' => 'anna', 'app' => 'core', 'key' => 'theme', 'had' => false, 'old' => '', 'new' => 'dark'];
		$this->config->method('getUserKeys')->willReturn([]);
		$this->config->expects($this->exactly(2))->method('deleteUserValue')->with('anna', 'core', 'theme')
			->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('db')));
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertSame('restored', $this->subject->revert(entry: $entry));
		$this->assertSame('failed', $this->subject->revert(entry: $entry));
	}//end testARevertDeletesAValueTheAccountDidNotHave()
}//end class
