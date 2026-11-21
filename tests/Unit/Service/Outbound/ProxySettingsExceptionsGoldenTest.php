<?php

/**
 * Characterisation test pinning how ProxySettings reads its exception list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Outbound;

use OCA\OpenRegister\Service\Outbound\ProxySettings;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Blank entries are dropped from both the app's own list and the instance-wide one.
 */
class ProxySettingsExceptionsGoldenTest extends TestCase {

	/**
	 * Settings over the given app value and system value.
	 *
	 * @param string $noProxy The app's own exception list ('' for unset).
	 * @param mixed  $system  The `proxyexclude` system value, or an exception to throw.
	 *
	 * @return ProxySettings
	 */
	private function settings(string $noProxy, mixed $system): ProxySettings {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === ProxySettings::NO_PROXY_KEY ? $noProxy : $default)
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static function (string $key, mixed $default = '') use ($system): mixed {
				if ($system instanceof RuntimeException) {
					throw $system;
				}

				return $system;
			}
		);

		return new ProxySettings($appConfig, $config);
	}//end settings()

	/**
	 * The app's own list is split on commas, trimmed, and blank entries are dropped.
	 *
	 * @return void
	 */
	public function testTheOwnListDropsBlankEntries(): void {
		$this->assertSame(['a.test', 'b.test'], $this->settings(noProxy: ' a.test, ,,b.test , ', system: ['ignored'])->exceptions());
	}//end testTheOwnListDropsBlankEntries()

	/**
	 * The instance-wide list drops blank entries, and is empty when unreadable or not a list.
	 *
	 * @return void
	 */
	public function testTheSystemListDropsBlanksAndFailsEmpty(): void {
		$this->assertSame(['x.test', 'y.test'], $this->settings(noProxy: '', system: ['x.test', ' ', '', 'y.test'])->exceptions());
		$this->assertSame([], $this->settings(noProxy: '', system: 'x.test')->exceptions());
		$this->assertSame([], $this->settings(noProxy: '', system: new RuntimeException('unreadable'))->exceptions());
	}//end testTheSystemListDropsBlanksAndFailsEmpty()
}//end class
