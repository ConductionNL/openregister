<?php

/**
 * AccentFoldingSettingTest: the administrator's accent folding choice.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\AccentFoldingSetting;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Enabled unless an administrator stored "false"; stored as "true" or "false".
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\AccentFoldingSetting
 */
class AccentFoldingSettingTest extends TestCase {

	/**
	 * A setting over an app config that answers $stored for the key.
	 *
	 * @param string|null $stored The stored value; null for none.
	 *
	 * @return AccentFoldingSetting
	 */
	private function setting(?string $stored): AccentFoldingSetting {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($stored): string {
				$this->assertSame(['openregister', AccentFoldingSetting::KEY], [$app, $key]);
				return ($stored ?? $default);
			}
		);

		return new AccentFoldingSetting(appConfig: $appConfig);
	}//end setting()

	/**
	 * Nothing stored, "true" or anything but "false" reads as enabled.
	 *
	 * @return void
	 */
	public function testEnabledUnlessSwitchedOff(): void {
		$this->assertTrue($this->setting(stored: null)->enabled());
		$this->assertTrue($this->setting(stored: 'true')->enabled());
		$this->assertTrue($this->setting(stored: '')->enabled());
		$this->assertFalse($this->setting(stored: 'false')->enabled());
	}//end testEnabledUnlessSwitchedOff()

	/**
	 * The choice is stored under the key as "true" or "false".
	 *
	 * @return void
	 */
	public function testStoreWritesTrueOrFalse(): void {
		$writes = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->exactly(2))->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$writes): bool {
				$writes[] = [$app, $key, $value];
				return true;
			}
		);

		$setting = new AccentFoldingSetting(appConfig: $appConfig);
		$setting->store(enabled: false);
		$setting->store(enabled: true);

		$this->assertSame(
			[['openregister', AccentFoldingSetting::KEY, 'false'], ['openregister', AccentFoldingSetting::KEY, 'true']],
			$writes
		);
	}//end testStoreWritesTrueOrFalse()
}//end class
