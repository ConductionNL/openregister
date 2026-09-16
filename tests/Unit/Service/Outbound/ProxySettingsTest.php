<?php

/**
 * ProxySettingsTest — one setting, and never the password.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Outbound
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Outbound;

use OCA\OpenRegister\Service\Outbound\ProxySettings;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Outbound\ProxySettings
 */
class ProxySettingsTest extends TestCase {

	/**
	 * Build the settings over app and system configuration.
	 *
	 * @param array<string, string> $app This app's own values.
	 * @param array<string, mixed> $system The instance-wide values.
	 *
	 * @return ProxySettings The settings.
	 */
	private function settings(array $app = [], array $system = []): ProxySettings {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $a, string $key, string $default = ''): string => ($app[$key] ?? $default)
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = '') => ($system[$key] ?? $default)
		);

		return new ProxySettings($appConfig, $config);
	}//end settings()

	public function testAnInstanceWithNoProxyAddsNoOptions(): void {
		$settings = $this->settings();

		$this->assertNull($settings->proxy());
		$this->assertFalse($settings->isConfigured());
		$this->assertSame([], $settings->requestOptions());
	}//end testAnInstanceWithNoProxyAddsNoOptions()

	public function testAnAdministeredProxyBecomesARequestOption(): void {
		$settings = $this->settings(app: [ProxySettings::PROXY_KEY => 'http://proxy.gemeente.test:3128']);

		$this->assertSame(['proxy' => 'http://proxy.gemeente.test:3128'], $settings->requestOptions());
	}//end testAnAdministeredProxyBecomesARequestOption()

	public function testTheInstanceWideProxyIsTheFallback(): void {
		$settings = $this->settings(system: ['proxy' => 'http://proxy.gemeente.test:3128']);

		$this->assertSame(
			'http://proxy.gemeente.test:3128',
			$settings->proxy(),
			'An administrator who already configured the server proxy has said what they want.'
		);
	}//end testTheInstanceWideProxyIsTheFallback()

	public function testThisAppsOwnSettingWinsOverTheInstanceWideOne(): void {
		$settings = $this->settings(
			app: [ProxySettings::PROXY_KEY => 'http://app-proxy.test:3128'],
			system: ['proxy' => 'http://system-proxy.test:3128'],
		);

		$this->assertSame('http://app-proxy.test:3128', $settings->proxy());
	}//end testThisAppsOwnSettingWinsOverTheInstanceWideOne()

	public function testExceptionsProduceTheMapFormSoInternalHostsStayDirect(): void {
		$settings = $this->settings(
			app: [
				ProxySettings::PROXY_KEY => 'http://proxy.test:3128',
				ProxySettings::NO_PROXY_KEY => 'localhost, .gemeente.local , 10.0.0.5',
			]
		);

		$this->assertSame(
			[
				'proxy' => [
					'http' => 'http://proxy.test:3128',
					'https' => 'http://proxy.test:3128',
					'no' => ['localhost', '.gemeente.local', '10.0.0.5'],
				],
			],
			$settings->requestOptions(),
			'The string form cannot express exceptions, and an instance that cannot reach its own neighbours stops federating.'
		);
	}//end testExceptionsProduceTheMapFormSoInternalHostsStayDirect()

	public function testTheInstanceWideExclusionListIsTheFallback(): void {
		$settings = $this->settings(
			app: [ProxySettings::PROXY_KEY => 'http://proxy.test:3128'],
			system: ['proxyexclude' => ['localhost', 'internal.test']],
		);

		$this->assertSame(['localhost', 'internal.test'], $settings->exceptions());
	}//end testTheInstanceWideExclusionListIsTheFallback()

	public function testTheDescriptionNeverCarriesThePassword(): void {
		$settings = $this->settings(
			app: [ProxySettings::PROXY_KEY => 'http://beheerder:hunter2@proxy.gemeente.test:3128']
		);

		$described = $settings->describe();

		$this->assertTrue($described['configured']);
		$this->assertSame('http://proxy.gemeente.test:3128', $described['endpoint']);
		$this->assertStringNotContainsString('hunter2', json_encode($described));
		$this->assertStringNotContainsString('beheerder', json_encode($described));
	}//end testTheDescriptionNeverCarriesThePassword()

	public function testAnUnparseableProxyCARRYINGCredentialsIsNotRepeated(): void {
		// The invariant is "never publish a password", not "never publish an odd
		// value". A value with no user-information delimiter has no credentials
		// in it, so it is shown as administered; one that does have them and
		// cannot be parsed is reduced to the bare fact that something is set.
		$settings = $this->settings(app: [ProxySettings::PROXY_KEY => 'http://beheerder:hunter2@host:notaport']);

		$described = $settings->describe();

		$this->assertSame('(set)', $described['endpoint']);
		$this->assertStringNotContainsString('hunter2', json_encode($described));
	}//end testAnUnparseableProxyCARRYINGCredentialsIsNotRepeated()

	public function testAValueWithNoCredentialsIsShownAsAdministered(): void {
		$settings = $this->settings(app: [ProxySettings::PROXY_KEY => 'proxy.gemeente.test:3128']);

		$this->assertSame(
			'proxy.gemeente.test:3128',
			$settings->describe()['endpoint'],
			'A scheme-less host is a plausible administered value, and reducing it to "(set)" helps nobody.'
		);
	}//end testAValueWithNoCredentialsIsShownAsAdministered()

	public function testAnUnconfiguredProxyIsDescribedAsAbsent(): void {
		$this->assertSame(['configured' => false], $this->settings()->describe());
	}//end testAnUnconfiguredProxyIsDescribedAsAbsent()

	/**
	 * @dataProvider provideCredentialUrls
	 */
	public function testCredentialsAreStripped(string $url, string $expected): void {
		$this->assertSame($expected, ProxySettings::withoutCredentials(url: $url));
	}//end testCredentialsAreStripped()

	public static function provideCredentialUrls(): array {
		return [
			'user and password' => ['http://u:p@proxy.test:3128', 'http://proxy.test:3128'],
			'user only' => ['http://u@proxy.test:3128', 'http://proxy.test:3128'],
			'no credentials' => ['http://proxy.test:3128', 'http://proxy.test:3128'],
			'no port' => ['https://proxy.test', 'https://proxy.test'],
			'host only' => ['proxy.test', 'proxy.test'],
		];
	}//end provideCredentialUrls()
}//end class
