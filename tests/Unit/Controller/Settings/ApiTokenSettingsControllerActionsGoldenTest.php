<?php

/**
 * Golden test for the ApiTokenSettingsController actions.
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
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ApiTokenSettingsControllerFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

/**
 * Pins every collaborator call and the response of each API token action.
 *
 * Each case records the app config reads and writes, the masking calls, the
 * HTTP probes and the connection reports, plus the response status and data,
 * and compares the recording with fixtures/api-token-actions-golden.json.
 * A TypeError that escapes an action is recorded by class only.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\ApiTokenSettingsController
 * @uses \OCA\OpenRegister\Controller\Support\ApiTokenSettingsControllerServices
 */
class ApiTokenSettingsControllerActionsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/api-token-actions-golden.json';

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
		$saved = ['github_api_token' => 'ghp_saved', 'gitlab_api_token' => 'glpat_saved', 'gitlab_api_url' => 'https://git.example/api/v4/'];
		return [
			'get tokens' => ['getApiTokens', ['config' => $saved]],
			'get no tokens' => ['getApiTokens', []],
			'get throws' => ['getApiTokens', ['configThrow' => true]],
			'save all' => ['saveApiTokens', ['params' => ['github_token' => 'ghp_new', 'gitlab_token' => 'glpat_new', 'gitlab_url' => 'https://g/']]],
			'save masked' => ['saveApiTokens', ['params' => ['github_token' => 'ghp***ved', 'gitlab_token' => 'gl***ed', 'gitlab_url' => '']]],
			'save nothing' => ['saveApiTokens', ['params' => ['github_token' => null, 'other' => 'x']]],
			'save non-string token' => ['saveApiTokens', ['params' => ['gitlab_token' => 5]]],
			'save throws' => ['saveApiTokens', ['params' => ['github_token' => 'ghp_new'], 'configThrow' => true]],
			'github saved token' => ['testGitHubToken', ['config' => $saved]],
			'github given token' => ['testGitHubToken', ['config' => $saved, 'params' => ['token' => 'ghp_other']]],
			'github given equals saved' => ['testGitHubToken', ['config' => $saved, 'params' => ['token' => 'ghp_saved']]],
			'github no login' => ['testGitHubToken', ['config' => $saved, 'body' => '{}']],
			'github no token' => ['testGitHubToken', []],
			'github zero token' => ['testGitHubToken', ['params' => ['token' => '0']]],
			'github int token' => ['testGitHubToken', ['params' => ['token' => 42]]],
			'github refused saved' => ['testGitHubToken', ['config' => $saved, 'httpThrow' => true]],
			'github refused given' => ['testGitHubToken', ['config' => $saved, 'params' => ['token' => 'x'], 'httpThrow' => true]],
			'github config throws' => ['testGitHubToken', ['configThrow' => true]],
			'gitlab saved token' => ['testGitLabToken', ['config' => $saved]],
			'gitlab default url' => ['testGitLabToken', ['config' => ['gitlab_api_token' => 'glpat_saved']]],
			'gitlab given url' => ['testGitLabToken', ['config' => $saved, 'params' => ['url' => 'https://other/api/v4///']]],
			'gitlab given empty url' => ['testGitLabToken', ['config' => $saved, 'params' => ['url' => '/']]],
			'gitlab given zero url' => ['testGitLabToken', ['config' => $saved, 'params' => ['url' => '0']]],
			'gitlab given token' => ['testGitLabToken', ['config' => $saved, 'params' => ['token' => 'glpat_other']]],
			'gitlab no username' => ['testGitLabToken', ['config' => $saved, 'body' => 'null']],
			'gitlab no token' => ['testGitLabToken', []],
			'gitlab refused saved' => ['testGitLabToken', ['config' => $saved, 'httpThrow' => true]],
			'gitlab refused given' => ['testGitLabToken', ['config' => $saved, 'params' => ['token' => 'x'], 'httpThrow' => true]],
			'gitlab config throws' => ['testGitLabToken', ['configThrow' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testActionMatchesTheRecording(string $action, array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params'] ?? []);

		$controller = ApiTokenSettingsControllerFactory::create(
			'openregister',
			$request,
			$this->config($case),
			$this->settings(),
			$this->clients($case),
			$this->createMock(LoggerInterface::class),
			$this->reporter()
		);

		try {
			$response = $controller->$action();
			$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		} catch (TypeError $e) {
			// Only the class: the message wording belongs to the PHP build.
			$actual = ['calls' => $this->calls, 'threw' => get_class($e)];
		}

		$this->compare($this->dataName(), $actual);
	}

	private function config(array $case): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default) use ($case) {
			$this->calls[] = ['config.getValueString' => [$app, $key, $default]];
			if (($case['configThrow'] ?? false) === true) {
				throw new Exception('config unavailable');
			}

			return $case['config'][$key] ?? $default;
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use ($case) {
			$this->calls[] = ['config.setValueString' => [$app, $key, $value]];
			if (($case['configThrow'] ?? false) === true) {
				throw new Exception('config read-only');
			}

			return true;
		});
		return $config;
	}

	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('maskToken')->willReturnCallback(function (string $token) {
			$this->calls[] = ['settings.maskToken' => [$token]];
			return substr($token, 0, 3) . '***';
		});
		return $settings;
	}

	private function clients(array $case): IClientService {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($case['body'] ?? '{"login":"octo","username":"tanuki"}');
		$response->method('getHeader')->willReturnCallback(function (string $name) {
			$this->calls[] = ['response.getHeader' => [$name]];
			return 'repo, user';
		});
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $uri, array $options) use ($case, $response) {
			$this->calls[] = ['client.get' => [$uri, $options]];
			if (($case['httpThrow'] ?? false) === true) {
				throw new Exception('401 Bad credentials');
			}

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturnCallback(function () use ($client) {
			$this->calls[] = ['clients.newClient' => []];
			return $client;
		});
		return $clients;
	}

	private function reporter(): ConnectionReporter {
		$reporter = $this->createMock(ConnectionReporter::class);
		$reporter->method('report')->willReturnCallback(function (string $key, string $status, string $message = '') {
			$this->calls[] = ['reporter.report' => [$key, $status, $message]];
			return true;
		});
		$reporter->method('refreshFromSave')->willReturnCallback(function (array $savedKeys) {
			$this->calls[] = ['reporter.refreshFromSave' => [$savedKeys]];
			return [];
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
