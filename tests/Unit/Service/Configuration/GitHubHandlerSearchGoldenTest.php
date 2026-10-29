<?php

/**
 * Golden characterization test for GitHubHandler::searchConfigurations().
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
 * Runs searchConfigurations() over a fixed corpus and compares the result
 * (or the thrown message) and every HTTP, cache and log call with
 * `fixtures/github-search-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubHandler
 */
class GitHubHandlerSearchGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/github-search-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [search, page, perPage, search response kind, token].
	 *
	 * @return array<string, array{0: string, 1: int, 2: int, 3: string, 4: string}>
	 */
	private static function corpus(): array {
		return [
			'no-terms' => ['', 1, 30, 'items', 'tok'],
			'with-terms' => ['repo:org/app', 2, 10, 'items', 'tok'],
			'per-page-capped' => ['x', 1, 250, 'items', ''],
			'zero-terms' => ['0', 1, 30, 'empty', 'tok'],
			'no-items-key' => ['', 1, 30, 'bare', 'tok'],
			'forbidden-rate-no-token' => ['', 1, 30, '403-rate', ''],
			'forbidden-rate-token' => ['', 1, 30, '403-rate', 'tok'],
			'forbidden' => ['', 1, 30, '403', 'tok'],
			'unauthorized' => ['', 1, 30, '401', 'tok'],
			'not-found' => ['', 1, 30, '404', 'tok'],
			'unprocessable' => ['', 1, 30, '422', 'tok'],
			'server-error' => ['', 1, 30, '500', 'tok'],
			'unavailable' => ['', 1, 30, '503', 'tok'],
			'teapot-rate' => ['', 1, 30, '418-rate', 'tok'],
			'teapot' => ['', 1, 30, '418', 'tok'],
			'connect-failed' => ['', 1, 30, 'connect', 'tok'],
		];
	}//end corpus()

	/**
	 * Case names for the data provider.
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
	 * Each case returns or throws as recorded and makes the recorded calls.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testSearchConfigurationsMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testSearchConfigurationsMatchesGolden()

	/**
	 * The code search response body for a kind.
	 *
	 * @param string $kind The response kind.
	 *
	 * @return array<string, mixed>
	 */
	private static function searchBody(string $kind): array {
		if ($kind === 'empty') {
			return ['total_count' => 0, 'items' => [], 'incomplete_results' => true];
		}

		if ($kind === 'bare') {
			return [];
		}

		$full = [
			'path' => 'lib/Settings/full.json',
			'sha' => 'sha-cached',
			'html_url' => 'https://github.com/org/app/blob/main/lib/Settings/full.json',
			'repository' => [
				'full_name' => 'org/app',
				'name' => 'app',
				'default_branch' => 'develop',
				'stargazers_count' => 12,
				'description' => 'An app',
				'owner' => ['login' => 'org', 'avatar_url' => 'https://a/1', 'type' => 'Organization', 'html_url' => 'https://github.com/org'],
			],
		];
		$minimal = [
			'path' => 'config.json',
			'html_url' => 'https://github.com/me/tool/blob/main/config.json',
			'repository' => ['full_name' => 'me/tool', 'name' => 'tool', 'owner' => ['login' => 'me']],
		];
		$fresh = [
			'path' => 'dir/fresh.json',
			'sha' => 'sha-fresh',
			'html_url' => 'https://github.com/me/tool/blob/main/dir/fresh.json',
			'repository' => ['full_name' => 'me/tool', 'name' => 'tool', 'owner' => ['login' => 'me']],
		];
		$broken = [
			'path' => 'broken.json',
			'sha' => 'sha-broken',
			'html_url' => 'https://github.com/me/tool/blob/main/broken.json',
			'repository' => ['full_name' => 'me/tool', 'name' => 'tool', 'owner' => ['login' => 'me']],
		];

		return ['total_count' => 4, 'items' => [$full, $minimal, $fresh, $broken]];
	}//end searchBody()

	/**
	 * Run one case and return what it did.
	 *
	 * @param array{0: string, 1: int, 2: int, 3: string, 4: string} $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		[$search, $page, $perPage, $kind, $token] = $case;

		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options = []) use ($kind): IResponse {
				$this->calls[] = ['client.request', $method, $url, $options];
				if (str_contains($url, '/search/code') === true) {
					return $this->searchResponse(kind: $kind, url: $url);
				}

				return $this->rawResponse(url: $url);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($token): string {
				$this->calls[] = ['appConfig.getValueString', $app, $key];
				return $token;
			}
		);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key): mixed {
				$this->calls[] = ['cache.get', $key];
				if (str_contains($key, 'sha-cached') === true) {
					return ['title' => 'Cached', 'type' => 'app'];
				}

				return null;
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->calls[] = ['cache.set', $key, $value, $ttl];
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->calls[] = ['log.'.$level, (string)$message, $context];
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
			$out = ['returned' => $handler->searchConfigurations($search, $page, $perPage)];
		} catch (\Throwable $e) {
			$out = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return json_decode((string)json_encode($out + ['calls' => $this->calls]), true);
	}//end runCase()

	/**
	 * The code search response, or the failure it throws.
	 *
	 * @param string $kind The response kind.
	 * @param string $url  The requested URL.
	 *
	 * @return IResponse
	 */
	private function searchResponse(string $kind, string $url): IResponse {
		if ($kind === 'connect') {
			throw new ConnectException('could not connect', new Request('GET', $url));
		}

		if (preg_match('/^(\d{3})(-rate)?$/', $kind, $match) === 1) {
			$message = 'HTTP '.$match[1];
			if (($match[2] ?? '') !== '') {
				$message .= ': API rate limit exceeded';
			}

			throw new RequestException($message, new Request('GET', $url), new Response((int)$match[1]));
		}

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn((string)json_encode(self::searchBody(kind: $kind)));
		return $response;
	}//end searchResponse()

	/**
	 * The raw file response for an enrichment fetch.
	 *
	 * @param string $url The raw URL.
	 *
	 * @return IResponse
	 */
	private function rawResponse(string $url): IResponse {
		if (str_contains($url, 'broken') === true) {
			throw new \RuntimeException('raw fetch failed');
		}

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(
			(string)json_encode(['info' => ['title' => 'Fresh', 'version' => '2.0.0'], 'x-openregister' => ['app' => 'tool', 'type' => 'app']])
		);
		return $response;
	}//end rawResponse()
}//end class
