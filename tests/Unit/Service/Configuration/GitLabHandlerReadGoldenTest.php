<?php

/**
 * Golden characterization test for the calls of GitLabHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs every public GitLabHandler call over a fixed corpus and compares the
 * result (or the thrown class and message) and every HTTP, config and log
 * call with `fixtures/gitlab-read-golden.json`. Log context is recorded
 * without `file` and `line`. Set `OR_GOLDEN_WRITE=1` to rewrite the fixture.
 */
class GitLabHandlerReadGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The fixture path.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/gitlab-read-golden.json';
	}//end goldenPath()

	/**
	 * Case name => [method, arguments, response kind, token, app-level API URL].
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: string, 3: string, 4: string}>
	 */
	private static function corpus(): array {
		return [
			'api-base-system' => ['getApiBase', [], 'ok', '', ''],
			'api-base-app' => ['getApiBase', [], 'ok', '', 'https://git.example.org/api/v4'],
			'search' => ['searchConfigurations', [], 'ok', 'tok', ''],
			'search-terms' => ['searchConfigurations', ['zaak', 2, 10], 'ok', '', 'https://git.example.org/api/v4'],
			'search-zero' => ['searchConfigurations', ['0'], 'ok', 'tok', ''],
			'search-500' => ['searchConfigurations', ['zaak'], '500', 'tok', ''],
			'branches' => ['getBranches', [12], 'ok', 'tok', ''],
			'branches-404' => ['getBranches', [12], '404', 'tok', ''],
			'file' => ['getFileContent', [12, 'dir/good.openregister.json', 'dev'], 'ok', 'tok', ''],
			'file-invalid' => ['getFileContent', [12, 'bad.openregister.json'], 'ok', '', ''],
			'file-connect' => ['getFileContent', [12, 'good.json'], 'connect', 'tok', ''],
			'list' => ['listConfigurationFiles', [12, 'dev', 'lib'], 'ok', 'tok', ''],
			'list-defaults' => ['listConfigurationFiles', [12], 'ok', '', ''],
			'list-403' => ['listConfigurationFiles', [12], '403', 'tok', ''],
			'project' => ['getProjectByPath', ['my group', 'app'], 'ok', 'tok', ''],
			'project-404' => ['getProjectByPath', ['org', 'app'], '404', 'tok', ''],
		];
	}//end corpus()

	/**
	 * One data set per corpus entry.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::corpus()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * The case's outcome and calls equal the recorded golden.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testCallMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testCallMatchesGolden()

	/**
	 * Run one case against a handler with recording doubles.
	 *
	 * @param array{0: string, 1: array<int, mixed>, 2: string, 3: string, 4: string} $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		[$method, $arguments, $kind, $token, $apiUrl] = $case;

		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(
			function (string $verb, string $url, array $options = []) use ($kind): IResponse {
				$this->calls[] = ['client.request', $verb, $url, $options];
				return $this->response(kind: $kind, url: $url);
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($token, $apiUrl): string {
				$this->calls[] = ['appConfig.getValueString', $app, $key];
				if ($key === 'gitlab_api_url') {
					return $apiUrl;
				}

				return $token;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			function (string $key, mixed $default = ''): mixed {
				$this->calls[] = ['config.getSystemValue', $key, $default];
				return $default;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->calls[] = ['log.'.$level, (string) $message, $context];
				}
			);
		}

		$handler = new GitLabHandler(clientService: $clientService, appConfig: $appConfig, config: $config, logger: $logger);

		try {
			$out = ['returned' => $handler->$method(...$arguments)];
		} catch (\Throwable $e) {
			$out = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return json_decode((string) json_encode($out + ['calls' => $this->calls]), true);
	}//end runCase()

	/**
	 * The response, or the failure, for one request.
	 *
	 * @param string $kind The case's response kind.
	 * @param string $url  The requested URL.
	 *
	 * @return IResponse
	 */
	private function response(string $kind, string $url): IResponse {
		if ($kind === 'connect') {
			throw new ConnectException('could not connect', new Request('GET', $url));
		}

		if (preg_match('/^\d{3}$/', $kind) === 1) {
			throw new RequestException('HTTP '.$kind, new Request('GET', $url), new Response((int) $kind));
		}

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(self::body(url: $url));
		return $response;
	}//end response()

	/**
	 * The response body for one URL.
	 *
	 * @param string $url The requested URL.
	 *
	 * @return string
	 */
	private static function body(string $url): string {
		if (str_contains($url, '/raw') === true) {
			return self::rawFile(url: $url);
		}

		if (str_ends_with($url, '/search') === true) {
			return (string) json_encode(
				[
					['project_id' => 3, 'path' => 'lib/app.openregister.json', 'ref' => 'dev', 'data' => '{"x-openregister":{}}'],
					['project_id' => 4, 'path' => 'conf.json'],
					['path' => 'no-project.json'],
					['project_id' => 5],
				]
			);
		}

		if (str_ends_with($url, '/repository/branches') === true) {
			return (string) json_encode(
				[
					['name' => 'main', 'commit' => ['id' => 'c1'], 'protected' => true, 'default' => true],
					['name' => 'feature'],
				]
			);
		}

		if (str_ends_with($url, '/repository/tree') === true) {
			return (string) json_encode(
				[
					['type' => 'blob', 'path' => 'openregister.json', 'id' => 'b1'],
					['type' => 'blob', 'path' => 'lib/xonly.openregister.json'],
					['type' => 'blob', 'path' => 'plain.openregister.json', 'id' => 'b3'],
					['type' => 'blob', 'path' => 'bad.openregister.json'],
					['type' => 'blob', 'path' => 'readme.md'],
					['type' => 'tree', 'path' => 'dir.openregister.json'],
				]
			);
		}

		return (string) json_encode(['id' => 12, 'path_with_namespace' => 'org/app', 'default_branch' => 'dev']);
	}//end body()

	/**
	 * A raw file body for the file named in the URL.
	 *
	 * @param string $url The requested URL.
	 *
	 * @return string
	 */
	private static function rawFile(string $url): string {
		if (str_contains($url, 'bad.openregister.json') === true) {
			return '{broken';
		}

		if (str_contains($url, 'plain.openregister.json') === true) {
			return (string) json_encode(['title' => 'not a configuration']);
		}

		if (str_contains($url, 'xonly') === true) {
			return (string) json_encode(['openapi' => '3.0.0', 'x-openregister' => ['title' => 'X', 'version' => '9.0.0', 'description' => 'xd']]);
		}

		return (string) json_encode(
			[
				'openapi' => '3.0.0',
				'info' => ['title' => 'Good', 'version' => '1.2.0'],
				'x-openregister' => ['app' => 'app', 'type' => 'app'],
			]
		);
	}//end rawFile()
}//end class
