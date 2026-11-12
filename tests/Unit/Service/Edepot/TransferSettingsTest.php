<?php

/**
 * Tests for TransferSettings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\TransferSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferSettings
 */
class TransferSettingsTest extends TestCase {

	/**
	 * The warnings logged, in order.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Settings over a fixed set of values.
	 *
	 * @param array<string, string> $values The stored values.
	 *
	 * @return TransferSettings
	 */
	private function settings(array $values): TransferSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new TransferSettings($config, $logger);
	}//end settings()

	public function testAnUnconfiguredInstanceReturnsEmptyValuesInTheSettingsOrder(): void {
		$config = $this->settings(values: [])->getTransportConfig();

		$this->assertSame(
			[
				'endpointUrl', 'authenticationType', 'apiKey', 'bearerToken', 'targetArchive', 'sipProfile', 'transport',
				'host', 'port', 'username', 'password', 'keyPath', 'remotePath', 'hostKeyFingerprint', 'sourceId', 'baseUrl',
			],
			array_keys($config)
		);
		$this->assertSame('', $config['authenticationType']);
		$this->assertSame('rest_api', $config['transport']);
		$this->assertSame('default', $config['sipProfile']);
		$this->assertSame('22', $config['port']);
		$this->assertSame('/', $config['remotePath']);
		$this->assertCount(2, $this->warnings);
		$this->assertStringContainsString('edepot_auth_type is not configured', $this->warnings[0]);
		$this->assertStringContainsString('neither edepot_sftp_key_path nor edepot_sftp_password', $this->warnings[1]);
	}

	public function testASelectedAuthTypeWithoutItsSecretIsLogged(): void {
		$this->settings(values: ['edepot_auth_type' => 'api_key', 'edepot_sftp_password' => 'p'])->getTransportConfig();
		$this->settings(values: ['edepot_auth_type' => 'oauth2', 'edepot_sftp_key_path' => '/k'])->getTransportConfig();

		$this->assertCount(2, $this->warnings);
		$this->assertStringContainsString('api_key but edepot_api_key is empty', $this->warnings[0]);
		$this->assertStringContainsString('oauth2 but edepot_bearer_token is empty', $this->warnings[1]);
	}

	public function testAFullyConfiguredInstanceLogsNothing(): void {
		$config = $this->settings(
			values: [
				'edepot_auth_type' => 'api_key',
				'edepot_api_key' => 'secret',
				'edepot_sftp_key_path' => '/key',
				'edepot_target_archive' => 'archive',
			]
		);

		$this->assertSame('secret', $config->getTransportConfig()['apiKey']);
		$this->assertSame('archive', $config->targetArchive());
		$this->assertSame([], $this->warnings);
	}
}//end class
