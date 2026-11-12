<?php

/**
 * What the administered proxy reads as when its settings cannot be read:
 * an unreadable app value falls through to the system value, an unreadable
 * system value reads as no proxy and no exceptions, a non-list system
 * exception value reads as none, and a credential-bearing URL with no host
 * is published only as "(set)".
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Outbound
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Outbound;

use OCA\OpenRegister\Service\Outbound\ProxySettings;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Failure-path tests for {@see ProxySettings}.
 */
class ProxySettingsFailureTest extends TestCase {

	/**
	 * An app config that cannot be read.
	 *
	 * @return IAppConfig The config.
	 */
	private function unreadableAppConfig(): IAppConfig {
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueString')->willThrowException(new RuntimeException('config table locked'));

		return $app;
	}//end unreadableAppConfig()

	/**
	 * An unreadable app value falls through to the system proxy and exclusions.
	 *
	 * @return void
	 */
	public function testAnUnreadableAppValueFallsThroughToTheSystemValue(): void {
		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValue')->willReturnCallback(
			static fn (string $key, $default = '') => match ($key) {
				'proxy' => ' http://sys.proxy:3128 ',
				'proxyexclude' => ['intranet', ' '],
				default => $default,
			}
		);
		$settings = new ProxySettings(appConfig: $this->unreadableAppConfig(), systemConfig: $system);

		$this->assertSame('http://sys.proxy:3128', $settings->proxy());
		$this->assertSame(['intranet'], $settings->exceptions());
	}//end testAnUnreadableAppValueFallsThroughToTheSystemValue()

	/**
	 * An unreadable system config reads as no proxy and no exceptions, so
	 * requests go out unproxied.
	 *
	 * @return void
	 */
	public function testAnUnreadableSystemConfigReadsAsNoProxy(): void {
		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValue')->willThrowException(new RuntimeException('config.php unreadable'));
		$settings = new ProxySettings(appConfig: $this->unreadableAppConfig(), systemConfig: $system);

		$this->assertNull($settings->proxy());
		$this->assertSame([], $settings->exceptions());
		$this->assertSame([], $settings->requestOptions());
		$this->assertFalse($settings->isConfigured());
	}//end testAnUnreadableSystemConfigReadsAsNoProxy()

	/**
	 * A system exclusion value that is not a list reads as no exceptions.
	 *
	 * @return void
	 */
	public function testANonListSystemExclusionReadsAsNone(): void {
		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValue')->willReturnCallback(
			static fn (string $key, $default = '') => match ($key) {
				'proxy' => 'http://sys.proxy:3128',
				'proxyexclude' => 'intranet',
				default => $default,
			}
		);
		$settings = new ProxySettings(appConfig: $this->unreadableAppConfig(), systemConfig: $system);

		$this->assertSame([], $settings->exceptions());
		$this->assertSame(['proxy' => 'http://sys.proxy:3128'], $settings->requestOptions());
	}//end testANonListSystemExclusionReadsAsNone()

	/**
	 * A credential-bearing value that parses to no scheme, host or port is
	 * published only as "(set)"; one with a host keeps the host, not the password.
	 *
	 * @return void
	 */
	public function testACredentialBearingValueWithoutAHostIsPublishedAsSet(): void {
		$this->assertSame('(set)', ProxySettings::withoutCredentials(url: 'secret@proxy.test'));
		$this->assertSame('(set)', ProxySettings::withoutCredentials(url: '//@'));
		$this->assertSame('http://proxy.test:3128', ProxySettings::withoutCredentials(url: 'http://user:secret@proxy.test:3128'));
	}//end testACredentialBearingValueWithoutAHostIsPublishedAsSet()
}//end class
