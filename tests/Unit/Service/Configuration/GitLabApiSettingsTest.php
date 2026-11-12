<?php

/**
 * Where the GitLab API lives (app config, then system config, then
 * gitlab.com) and the private token header sent when one is configured.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Configuration\GitLabApiSettings;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * GitLabApiSettings.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\GitLabApiSettings
 */
class GitLabApiSettingsTest extends TestCase {

	private function settings(array $appValues, ?string $systemUrl = null, ?IConfig $config = null): GitLabApiSettings {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($appValues): string {
				TestCase::assertSame('openregister', $app);
				TestCase::assertSame('', $default);
				return ($appValues[$key] ?? '');
			}
		);

		if ($config === null) {
			$config = $this->createMock(IConfig::class);
			$config->method('getSystemValue')->willReturnCallback(
				static fn (string $key, mixed $default): mixed => ($systemUrl ?? $default)
			);
		}

		return new GitLabApiSettings($appConfig, $config);
	}//end settings()

	public function testTheAppConfiguredUrlWins(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('getSystemValue');

		$this->assertSame(
			'https://git.example.nl/api/v4',
			$this->settings(['gitlab_api_url' => 'https://git.example.nl/api/v4'], config: $config)->apiBase()
		);
	}//end testTheAppConfiguredUrlWins()

	public function testTheSystemConfiguredUrlIsTheFallback(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('getSystemValue')
			->with('gitlab_api_url', 'https://gitlab.com/api/v4')
			->willReturn('https://gitlab.internal/api/v4');

		$this->assertSame('https://gitlab.internal/api/v4', $this->settings([], config: $config)->apiBase());
	}//end testTheSystemConfiguredUrlIsTheFallback()

	public function testGitLabComIsTheDefault(): void {
		$this->assertSame('https://gitlab.com/api/v4', $this->settings([])->apiBase());
	}//end testGitLabComIsTheDefault()

	public function testAConfiguredTokenIsSentAsThePrivateTokenHeader(): void {
		$this->assertSame(['PRIVATE-TOKEN' => 'glpat-123'], $this->settings(['gitlab_api_token' => 'glpat-123'])->headers());
	}//end testAConfiguredTokenIsSentAsThePrivateTokenHeader()

	public function testNoTokenSendsNoHeaders(): void {
		$this->assertSame([], $this->settings([])->headers());
	}//end testNoTokenSendsNoHeaders()
}//end class
