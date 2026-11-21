<?php

/**
 * Golden test for the EdepotSettingsController actions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Exception;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EdepotSettingsControllerFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

/**
 * Pins every collaborator call and the response of each e-Depot settings action.
 *
 * Each case records the transfer service, app config, transport and
 * connection reporter calls, plus the response status and data, and compares
 * the recording with fixtures/edepot-settings-golden.json. A TypeError that
 * escapes an action is recorded by class only.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\EdepotSettingsController
 * @uses \OCA\OpenRegister\Controller\Support\ConnectionReporting
 * @uses \OCA\OpenRegister\Controller\Support\EdepotSettingsDomain
 * @uses \OCA\OpenRegister\Controller\Support\EdepotSettingsControllerServices
 */
class EdepotSettingsControllerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/edepot-settings-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$secrets = ['transport' => 'sftp', 'apiKey' => 'k', 'bearerToken' => 'b', 'password' => 'p', 'host' => 'h'];
		$blank = ['apiKey' => '', 'bearerToken' => '0', 'password' => null, 'host' => 'h'];
		$all = [
			'endpointUrl' => 'https://e', 'authenticationType' => 'api_key', 'apiKey' => '***', 'bearerToken' => 'new',
			'targetArchive' => 'ta', 'sipProfile' => 'default', 'transport' => 'sftp', 'host' => 'h', 'port' => 22,
			'username' => 'u', 'password' => '***', 'keyPath' => null, 'remotePath' => '/in', 'hostKeyFingerprint' => true,
			'sourceId' => 7, 'baseUrl' => 'https://oc', 'ignored' => 'x',
		];
		$openConnectorTest = ['transport' => 'openconnector', 'testConnection' => true];
		return [
			'get secrets masked' => ['getEdepotSettings', ['config' => $secrets]],
			'get blank secrets kept' => ['getEdepotSettings', ['config' => $blank]],
			'get throws' => ['getEdepotSettings', ['throw' => 'config']],
			'update invalid profile' => ['updateEdepotSettings', ['params' => ['sipProfile' => 'bad', 'host' => 'h']]],
			'update default profile' => ['updateEdepotSettings', ['params' => ['endpointUrl' => 'https://e']]],
			'update every field' => ['updateEdepotSettings', ['params' => $all]],
			'update test sftp' => ['updateEdepotSettings', ['params' => ['transport' => 'sftp', 'testConnection' => true]]],
			'update test openconnector fails' => ['updateEdepotSettings', ['params' => $openConnectorTest, 'passes' => false]],
			'update test default transport' => ['updateEdepotSettings', ['params' => ['testConnection' => true]]],
			'update test unknown transport' => ['updateEdepotSettings', ['params' => ['transport' => 'ftp', 'testConnection' => true]]],
			'update test flag as text' => ['updateEdepotSettings', ['params' => ['testConnection' => 'true']]],
			'update test flag false' => ['updateEdepotSettings', ['params' => ['testConnection' => false]]],
			'update test throws' => ['updateEdepotSettings', ['params' => ['testConnection' => true], 'throw' => 'transport']],
			'update write throws' => ['updateEdepotSettings', ['params' => ['host' => 'h'], 'throw' => 'write']],
			'update numeric transport' => ['updateEdepotSettings', ['params' => ['transport' => 5, 'testConnection' => true]]],
			'test rest api passes' => ['testEdepotConnection', ['config' => ['transport' => 'rest_api']]],
			'test sftp fails' => ['testEdepotConnection', ['config' => ['transport' => 'sftp'], 'passes' => false]],
			'test default transport' => ['testEdepotConnection', ['config' => []]],
			'test openconnector throws' => ['testEdepotConnection', ['config' => ['transport' => 'openconnector'], 'throw' => 'transport']],
			'test config throws' => ['testEdepotConnection', ['throw' => 'config']],
		];
	}

	#[DataProvider('cases')]
	public function testActionMatchesTheRecording(string $action, array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params'] ?? []);

		$controller = EdepotSettingsControllerFactory::create(
			'openregister',
			$request,
			$this->appConfig($case),
			$this->transfer($case),
			$this->transport(SftpTransport::class, 'sftp', $case),
			$this->transport(RestApiTransport::class, 'rest_api', $case),
			$this->transport(OpenConnectorTransport::class, 'openconnector', $case),
			$this->createMock(LoggerInterface::class),
			$this->reporter()
		);

		try {
			$response = $controller->$action();
			$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		} catch (TypeError $e) {
			$actual = ['calls' => $this->calls, 'threw' => get_class($e)];
		}

		$this->compare($this->dataName(), $actual);
	}

	private function transfer(array $case): EdepotTransferService {
		$transfer = $this->createMock(EdepotTransferService::class);
		$transfer->method('getTransportConfig')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['transfer.getTransportConfig' => []];
			if (($case['throw'] ?? null) === 'config') {
				throw new Exception('config unreadable');
			}

			return $case['config'] ?? ['transport' => 'rest_api', 'endpointUrl' => 'https://e'];
		});
		$transfer->method('getAvailableProfiles')->willReturnCallback(function () {
			$this->calls[] = ['transfer.getAvailableProfiles' => []];
			return ['default' => ['label' => 'Default'], 'tmlo' => ['label' => 'TMLO']];
		});
		$transfer->method('isValidProfile')->willReturnCallback(function (string $profile) {
			$this->calls[] = ['transfer.isValidProfile' => [$profile]];
			return in_array($profile, ['default', 'tmlo'], true);
		});
		return $transfer;
	}

	private function appConfig(array $case): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use ($case) {
			$this->calls[] = ['config.setValueString' => [$app, $key, $value]];
			if (($case['throw'] ?? null) === 'write') {
				throw new Exception('config read-only');
			}

			return true;
		});
		return $config;
	}

	/**
	 * A transport double that records its test and answers by the case.
	 *
	 * @param class-string $class The transport class.
	 * @param string $name The transport name.
	 * @param array<string, mixed> $case The case options.
	 *
	 * @return SftpTransport|RestApiTransport|OpenConnectorTransport The double.
	 */
	private function transport(string $class, string $name, array $case): object {
		$transport = $this->createMock($class);
		$transport->method('getName')->willReturnCallback(function () use ($name) {
			$this->calls[] = [$name . '.getName' => []];
			return $name;
		});
		$transport->method('testConnection')->willReturnCallback(function (array $config) use ($name, $case) {
			$this->calls[] = [$name . '.testConnection' => [$config]];
			if (($case['throw'] ?? null) === 'transport') {
				throw new Exception('host unreachable');
			}

			return $case['passes'] ?? true;
		});
		return $transport;
	}

	private function reporter(): ConnectionReporter {
		$reporter = $this->createMock(ConnectionReporter::class);
		$reporter->method('report')->willReturnCallback(function (string $key, string $status, string $message = '') {
			$this->calls[] = ['reporter.report' => [$key, $status, $message]];
			return true;
		});
		return $reporter;
	}

	/**
	 * Compare a recording with the fixture, or rewrite it under UPDATE_GOLDEN=1.
	 *
	 * @param string $key The case name.
	 * @param array<string, mixed> $actual The recording.
	 *
	 * @return void
	 */
	private function compare(string $key, array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
