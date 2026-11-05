<?php

/**
 * Golden characterization test for GitHubHandler::publishConfiguration().
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
 * Runs publishConfiguration() over a fixed corpus and compares the result
 * (or the thrown message) and every HTTP and log call with
 * `fixtures/github-publish-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubHandler
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubFileLocation
 */
class GitHubHandlerPublishGoldenTest extends TestCase {

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
		return __DIR__.'/fixtures/github-publish-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [path, file sha, response kind].
	 *
	 * @return array<string, array{0: string, 1: ?string, 2: string}>
	 */
	private static function corpus(): array {
		return [
			'create' => ['lib/Settings/config.json', null, 'ok'],
			'update' => ['lib/Settings/config.json', 'abc123', 'ok'],
			'odd-path' => ['dir with space/ünï#?.json', null, 'ok'],
			'sparse-response' => ['c.json', null, 'sparse'],
			'not-found' => ['c.json', null, '404'],
			'forbidden' => ['c.json', 'abc', '403'],
			'invalid' => ['c.json', null, '422'],
			'server-error' => ['c.json', null, '500'],
			'not-found-no-message' => ['c.json', null, '404-bare'],
			'not-json' => ['c.json', null, '409-text'],
			'connect-failed' => ['c.json', null, 'connect'],
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
	public function testPublishConfigurationMatchesGolden(string $name): void {
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
	}//end testPublishConfigurationMatchesGolden()

	/**
	 * Run one case and return what it did.
	 *
	 * @param array{0: string, 1: ?string, 2: string} $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		[$path, $fileSha, $kind] = $case;

		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options = []) use ($kind): IResponse {
				$this->calls[] = ['client.request', $method, $url, $options];
				return $this->publishResponse(kind: $kind, url: $url);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('tok');

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

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
			$out = ['returned' => $handler->publishConfiguration('org', 'app', $path, 'dev', '{"a":1}', 'Publish it', $fileSha)];
		} catch (\Throwable $e) {
			$out = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return json_decode((string)json_encode($out + ['calls' => $this->calls]), true);
	}//end runCase()

	/**
	 * The Contents API response, or the failure it throws.
	 *
	 * @param string $kind The response kind.
	 * @param string $url  The requested URL.
	 *
	 * @return IResponse
	 */
	private function publishResponse(string $kind, string $url): IResponse {
		if ($kind === 'connect') {
			throw new ConnectException('could not connect', new Request('PUT', $url));
		}

		if (preg_match('/^(\d{3})(-bare|-text)?$/', $kind, $match) === 1) {
			$body = (string)json_encode(['message' => 'GitHub says '.$match[1]]);
			if (($match[2] ?? '') === '-bare') {
				$body = (string)json_encode(['documentation_url' => 'https://docs']);
			}

			if (($match[2] ?? '') === '-text') {
				$body = 'conflict, not json';
			}

			throw new RequestException('HTTP '.$match[1], new Request('PUT', $url), new Response((int)$match[1], [], $body));
		}

		$result = ['commit' => ['sha' => 'c1', 'html_url' => 'https://c'], 'content' => ['sha' => 'f1', 'html_url' => 'https://f']];
		if ($kind === 'sparse') {
			$result = ['commit' => ['sha' => 'c1']];
		}

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn((string)json_encode($result));
		return $response;
	}//end publishResponse()
}//end class
