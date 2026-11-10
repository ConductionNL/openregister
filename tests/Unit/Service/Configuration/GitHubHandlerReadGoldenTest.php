<?php

/**
 * Golden characterization test for the read calls of GitHubHandler.
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
use OCA\OpenRegister\Service\Configuration\AttributionFormatter;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs getBranches(), getFileContent(), listConfigurationFiles(),
 * getRepositories(), getRepositoryInfo() and enrichConfigurationDetails() over
 * a fixed corpus and compares the result (or the thrown class and message) and
 * every HTTP, config and log call with `fixtures/github-read-golden.json`.
 * Log context is recorded without `file` and `line`. Set `OR_GOLDEN_WRITE=1`
 * to rewrite the fixture.
 */
class GitHubHandlerReadGoldenTest extends TestCase {

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
		return __DIR__.'/fixtures/github-read-golden.json';
	}//end goldenPath()

	/**
	 * Case name => [method, arguments, response kind, token].
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: string, 3: string}>
	 */
	private static function corpus(): array {
		return [
			'branches' => ['getBranches', ['org', 'app'], 'ok', 'tok'],
			'branches-no-token' => ['getBranches', ['org', 'app'], 'ok', ''],
			'branches-404' => ['getBranches', ['org', 'app'], '404', 'tok'],
			'branches-connect' => ['getBranches', ['org', 'app'], 'connect', 'tok'],
			'file' => ['getFileContent', ['org', 'app', 'good.json', 'dev'], 'ok', 'tok'],
			'file-default-branch' => ['getFileContent', ['org', 'app', 'plain.json'], 'ok', 'tok'],
			'file-invalid-json' => ['getFileContent', ['org', 'app', 'bad.json'], 'ok', 'tok'],
			'file-no-content' => ['getFileContent', ['org', 'app', 'empty.json'], 'ok', 'tok'],
			'file-500' => ['getFileContent', ['org', 'app', 'good.json'], '500', 'tok'],
			'list' => ['listConfigurationFiles', ['org', 'app', 'dev', 'lib'], 'ok', 'tok'],
			'list-defaults' => ['listConfigurationFiles', ['org', 'app'], 'ok', ''],
			'list-no-items' => ['listConfigurationFiles', ['org', 'app'], 'bare', 'tok'],
			'list-403' => ['listConfigurationFiles', ['org', 'app'], '403', 'tok'],
			'repos-no-token' => ['getRepositories', [], 'ok', ''],
			'repos' => ['getRepositories', [2, 250], 'ok', 'tok'],
			'repos-401' => ['getRepositories', [], '401', 'tok'],
			'repos-500' => ['getRepositories', [], '500', 'tok'],
			'repos-connect' => ['getRepositories', [], 'connect', 'tok'],
			'info' => ['getRepositoryInfo', ['org', 'app'], 'ok', 'tok'],
			'info-sparse' => ['getRepositoryInfo', ['me', 'tool'], 'bare', 'tok'],
			'info-404' => ['getRepositoryInfo', ['org', 'app'], '404', 'tok'],
			'enrich' => ['enrichConfigurationDetails', ['org', 'app', 'dir/conf.json', 'dev'], 'ok', 'tok'],
			'enrich-bare' => ['enrichConfigurationDetails', ['org', 'app', 'dir/conf.json'], 'bare', 'tok'],
			'enrich-invalid' => ['enrichConfigurationDetails', ['org', 'app', 'bad.json'], 'ok', 'tok'],
			'enrich-fails' => ['enrichConfigurationDetails', ['org', 'app', 'conf.json'], '500', 'tok'],
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
	public function testReadCallMatchesGolden(string $name): void {
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
	}//end testReadCallMatchesGolden()

	/**
	 * Run one case against a handler with recording doubles.
	 *
	 * @param array{0: string, 1: array<int, mixed>, 2: string, 3: string} $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		[$method, $arguments, $kind, $token] = $case;

		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(
			function (string $verb, string $url, array $options = []) use ($kind): IResponse {
				$this->calls[] = ['client.request', $verb, $url, $options];
				return $this->response(kind: $kind, url: $url);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($token): string {
				$this->calls[] = ['appConfig.getValueString', $app, $key];
				return $token;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->calls[] = ['log.'.$level, (string) $message, $context];
				}
			);
		}

		$handler = new GitHubHandler(
			client: $client,
			appConfig: $appConfig,
			config: $this->createMock(IConfig::class),
			cacheFactory: $cacheFactory,
			attributionFormatter: $this->createMock(AttributionFormatter::class),
			logger: $logger
		);

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
		$response->method('getBody')->willReturn(self::body(kind: $kind, url: $url));
		return $response;
	}//end response()

	/**
	 * The response body for one URL.
	 *
	 * @param string $kind The case's response kind.
	 * @param string $url  The requested URL.
	 *
	 * @return string
	 */
	private static function body(string $kind, string $url): string {
		if (str_contains($url, 'raw.githubusercontent.com') === true) {
			return self::rawBody(kind: $kind, url: $url);
		}

		if (str_contains($url, '/contents/') === true) {
			return (string) json_encode(self::contents(url: $url));
		}

		if ($kind === 'bare') {
			return '{}';
		}

		return (string) json_encode(self::apiBody(url: $url));
	}//end body()

	/**
	 * A raw.githubusercontent.com body.
	 *
	 * @param string $kind The case's response kind.
	 * @param string $url  The requested URL.
	 *
	 * @return string
	 */
	private static function rawBody(string $kind, string $url): string {
		if (str_contains($url, 'bad.json') === true) {
			return 'not json';
		}

		if ($kind === 'bare') {
			return '"just a string"';
		}

		return (string) json_encode(
			[
				'info' => ['title' => 'Conf', 'description' => 'A conf', 'version' => '3.1.0'],
				'x-openregister' => ['app' => 'app', 'type' => 'app', 'openregister' => '^2.0'],
			]
		);
	}//end rawBody()

	/**
	 * A Contents API body for the file named in the URL.
	 *
	 * @param string $url The requested URL.
	 *
	 * @return array<string, mixed>
	 */
	private static function contents(string $url): array {
		$files = [
			'good.json' => (string) json_encode(
				[
					'openapi' => '3.0.0',
					'info' => ['title' => 'Good', 'version' => '1.2.0'],
					'x-openregister' => ['app' => 'app', 'type' => 'app', 'description' => 'From x'],
				]
			),
			'xonly.openregister.json' => (string) json_encode(
				['openapi' => '3.0.0', 'x-openregister' => ['title' => 'X title', 'version' => '9.0.0']]
			),
			'plain.json' => (string) json_encode(['title' => 'not a configuration']),
			'bad.json' => '{broken',
		];
		foreach ($files as $name => $content) {
			if (str_contains($url, '/'.$name) === true) {
				return ['sha' => 'sha-'.$name, 'content' => base64_encode($content)];
			}
		}

		return ['sha' => 'sha-empty'];
	}//end contents()

	/**
	 * An API body for branches, code search, repositories or one repository.
	 *
	 * @param string $url The requested URL.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function apiBody(string $url): array {
		if (str_ends_with($url, '/branches') === true) {
			return [
				['name' => 'main', 'commit' => ['sha' => 'abc'], 'protected' => true],
				['name' => 'feature'],
			];
		}

		if (str_contains($url, '/search/code') === true) {
			return [
				'items' => [
					['path' => 'good.json', 'sha' => 's1', 'html_url' => 'https://github.com/org/app/blob/main/good.json'],
					['path' => 'lib/xonly.openregister.json'],
					['path' => 'plain.json', 'sha' => 's3'],
					['path' => 'bad.json'],
				],
			];
		}

		if (str_ends_with($url, '/user/repos') === true) {
			return [
				[
					'id' => 1, 'name' => 'app', 'full_name' => 'org/app', 'owner' => ['login' => 'org', 'type' => 'Organization'],
					'private' => false, 'description' => 'An app', 'default_branch' => 'dev',
					'html_url' => 'https://github.com/org/app', 'url' => 'https://api.github.com/repos/org/app',
				],
				[
					'id' => 2, 'name' => 'tool', 'full_name' => 'me/tool', 'owner' => ['login' => 'me', 'type' => 'User'],
					'private' => true, 'html_url' => 'https://github.com/me/tool', 'url' => 'https://api.github.com/repos/me/tool',
				],
			];
		}

		return [
			'id' => 7, 'name' => 'app', 'full_name' => 'org/app', 'owner' => ['login' => 'org'], 'private' => true,
			'description' => 'An app', 'default_branch' => 'dev', 'html_url' => 'https://github.com/org/app',
		];
	}//end apiBody()
}//end class
