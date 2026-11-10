<?php

/**
 * What the platform reports about its own security settings.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Service\Hardening\PlatformConfiguration;
use OCA\OpenRegister\Service\Hardening\PlatformSecurityReader;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each reading reports the configured value, a default, or null when it cannot be read.
 *
 * @covers \OCA\OpenRegister\Service\Hardening\PlatformSecurityReader
 * @covers \OCA\OpenRegister\Service\Hardening\PlatformConfiguration
 */
final class PlatformSecurityReaderTest extends TestCase {

	private IConfig&MockObject $config;

	private IAppConfig&MockObject $appConfig;

	private IAppManager&MockObject $apps;

	private IThrottler&MockObject $throttler;

	private IRequest&MockObject $request;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The reader under test.
	 *
	 * @return PlatformSecurityReader The reader.
	 */
	private function reader(): PlatformSecurityReader {
		return new PlatformSecurityReader(
			platform: new PlatformConfiguration(config: $this->config, appConfig: $this->appConfig, appManager: $this->apps),
			throttler: $this->throttler,
			request: $this->request
		);
	}//end reader()

	/**
	 * With the policy app off every policy value is unknown and app config is never read.
	 *
	 * @return void
	 */
	public function testWithoutThePolicyAppThePolicyIsUnknown(): void {
		$this->apps->expects($this->once())->method('isEnabledForAnyone')->with('password_policy')->willReturn(false);
		$this->appConfig->expects($this->never())->method('getValueInt');

		$this->assertSame(
			['minimumLength' => null, 'blocksCommonPasswords' => null, 'checksBreachDatabase' => null],
			$this->reader()->passwordPolicy()
		);
	}//end testWithoutThePolicyAppThePolicyIsUnknown()

	/**
	 * An app manager that throws counts as the policy app not running.
	 *
	 * @return void
	 */
	public function testAFailingAppManagerCountsAsNoPolicyApp(): void {
		$this->apps->method('isEnabledForAnyone')->willThrowException(new RuntimeException('no db'));

		$this->assertFalse($this->reader()->passwordPolicyRuns());
		$this->assertNull($this->reader()->passwordPolicy()['minimumLength']);
	}//end testAFailingAppManagerCountsAsNoPolicyApp()

	/**
	 * With the policy app on its settings are read, with the documented defaults.
	 *
	 * @return void
	 */
	public function testWithThePolicyAppItsSettingsAreRead(): void {
		$this->apps->method('isEnabledForAnyone')->willReturn(true);
		$this->appConfig->expects($this->once())->method('getValueInt')->with('password_policy', 'minLength', 10)->willReturn(12);
		$this->appConfig->method('getValueBool')->willReturnMap(
			[
				['password_policy', 'enforceNonCommonPassword', true, false, true],
				['password_policy', 'enforceHaveIBeenPwned', true, false, false],
			]
		);

		$this->assertTrue($this->reader()->passwordPolicyRuns());
		$this->assertSame(
			['minimumLength' => 12, 'blocksCommonPasswords' => 1, 'checksBreachDatabase' => 0],
			$this->reader()->passwordPolicy()
		);
	}//end testWithThePolicyAppItsSettingsAreRead()

	/**
	 * Policy settings that cannot be read are reported unknown, not guessed.
	 *
	 * @return void
	 */
	public function testUnreadablePolicySettingsAreUnknown(): void {
		$this->apps->method('isEnabledForAnyone')->willReturn(true);
		$this->appConfig->method('getValueInt')->willThrowException(new RuntimeException('x'));
		$this->appConfig->method('getValueBool')->willThrowException(new RuntimeException('x'));

		$this->assertSame(
			['minimumLength' => null, 'blocksCommonPasswords' => null, 'checksBreachDatabase' => null],
			$this->reader()->passwordPolicy()
		);
	}//end testUnreadablePolicySettingsAreUnknown()

	/**
	 * The session policy reads system values with Nextcloud's defaults and every truthy spelling of the 2FA flag.
	 *
	 * @return void
	 */
	public function testTheSessionPolicyReadsSystemValues(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default): mixed => match ($key) {
				'session_lifetime' => '3600',
				'twofactor_enforced' => 'true',
				default => $default,
			}
		);

		$this->assertSame(
			['lifetimeSeconds' => 3600, 'rememberLoginSeconds' => 1296000, 'secondFactorEnforced' => 1],
			$this->reader()->sessionPolicy()
		);
	}//end testTheSessionPolicyReadsSystemValues()

	/**
	 * A 2FA flag that is not truthy reads 0; any truthy spelling reads 1.
	 *
	 * @return void
	 */
	public function testTheSecondFactorFlagSpellings(): void {
		$values = [true, '1', 1, 'false', false, 'yes'];
		$this->config->method('getSystemValue')->willReturnCallback(
			static function (string $key, mixed $default) use (&$values): mixed {
				if ($key === 'twofactor_enforced') {
					return array_shift($values);
				}
				return $default;
			}
		);

		$read = [];
		for ($i = 0; $i < 6; $i++) {
			$read[] = $this->reader()->sessionPolicy()['secondFactorEnforced'];
		}

		$this->assertSame([1, 1, 1, 0, 0, 0], $read);
	}//end testTheSecondFactorFlagSpellings()

	/**
	 * Unreadable system config makes every session value unknown.
	 *
	 * @return void
	 */
	public function testUnreadableSystemConfigMakesTheSessionPolicyUnknown(): void {
		$this->config->method('getSystemValue')->willThrowException(new RuntimeException('x'));

		$this->assertSame(
			['lifetimeSeconds' => null, 'rememberLoginSeconds' => null, 'secondFactorEnforced' => null],
			$this->reader()->sessionPolicy()
		);
	}//end testUnreadableSystemConfigMakesTheSessionPolicyUnknown()

	/**
	 * The throttler is asked about the caller's address and reported as enabled.
	 *
	 * @return void
	 */
	public function testTheBruteForceStateOfTheCallersAddress(): void {
		$this->config->method('getSystemValue')->with('auth.bruteforce.protection.enabled', true)->willReturn(true);
		$this->request->method('getRemoteAddress')->willReturn('10.0.0.5');
		$this->throttler->expects($this->once())->method('getDelay')->with('10.0.0.5')->willReturn(800);
		$this->throttler->expects($this->once())->method('getAttempts')->with('10.0.0.5')->willReturn(4);
		$this->throttler->expects($this->once())->method('isBypassListed')->with('10.0.0.5')->willReturn(true);

		$this->assertSame(
			['throttlerEnabled' => 1, 'delayMilliseconds' => 800, 'attempts' => 4, 'bypassListed' => true],
			$this->reader()->bruteForceState()
		);
	}//end testTheBruteForceStateOfTheCallersAddress()

	/**
	 * A disabled throttler reads 0, and without an address the throttler is not asked.
	 *
	 * @return void
	 */
	public function testADisabledThrottlerWithoutAddress(): void {
		$this->config->method('getSystemValue')->willReturn('false');
		$this->request->method('getRemoteAddress')->willReturn('');
		$this->throttler->expects($this->never())->method('getDelay');

		$this->assertSame(
			['throttlerEnabled' => 0, 'delayMilliseconds' => 0, 'attempts' => 0, 'bypassListed' => false],
			$this->reader()->bruteForceState()
		);
	}//end testADisabledThrottlerWithoutAddress()

	/**
	 * Each truthy spelling of the protection flag reads enabled.
	 *
	 * @return void
	 */
	public function testTheProtectionFlagSpellings(): void {
		$values = ['true', 1, '1'];
		$this->config->method('getSystemValue')->willReturnCallback(static function () use (&$values): mixed {
			return array_shift($values);
		});
		$this->request->method('getRemoteAddress')->willReturn('');

		$read = [];
		for ($i = 0; $i < 3; $i++) {
			$read[] = $this->reader()->bruteForceState()['throttlerEnabled'];
		}

		$this->assertSame([1, 1, 1], $read);
	}//end testTheProtectionFlagSpellings()

	/**
	 * Unreadable config makes the flag unknown and a failing throttler resets to the safe zeros.
	 *
	 * @return void
	 */
	public function testFailuresReadAsUnknownAndZero(): void {
		$this->config->method('getSystemValue')->willThrowException(new RuntimeException('x'));
		$this->request->method('getRemoteAddress')->willReturn('10.0.0.5');
		$this->throttler->method('getDelay')->willReturn(500);
		$this->throttler->method('getAttempts')->willThrowException(new RuntimeException('cache down'));

		$this->assertSame(
			['throttlerEnabled' => null, 'delayMilliseconds' => 0, 'attempts' => 0, 'bypassListed' => false],
			$this->reader()->bruteForceState()
		);
	}//end testFailuresReadAsUnknownAndZero()
}//end class
