<?php

/**
 * Golden test for the credential, audit and file-SHA calls of GitHubHandler.
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
use OCA\OpenRegister\Kernel\Contracts\Value\GitHubFileLocation;
use OCA\OpenRegister\Kernel\Contracts\Value\GitHubIssueSubmission;
use OCA\OpenRegister\Kernel\Contracts\Value\GitHubIssueText;

/**
 * Runs createIssue() (which token is used, refused when there is none, the
 * attribution and the server-PAT audit entries), validateToken(),
 * getFileSha() and the user-token accessors over a fixed corpus. Each case
 * records the outcome and every HTTP, config and log call; the audit
 * timestamp is recorded only as present, and log `file`/`line` are dropped.
 * Compared with `fixtures/github-credentials-golden.json`; set
 * `OR_GOLDEN_WRITE=1` to rewrite it.
 */
class GitHubHandlerCredentialsGoldenTest extends TestCase {

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
		return __DIR__.'/fixtures/github-credentials-golden.json';
	}//end goldenPath()

	/**
	 * Case name => [method, arguments, response kind, user token, app token].
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: string, 3: string, 4: string}>
	 */
	private static function corpus(): array {
		$issue = ['Con duction', 'open/register', 'A title', 'A body text', null, 'alice'];
		$withSpec = ['o', 'r', 'A title', 'A body text', 'object-search', 'alice'];
		$blankSpec = ['o', 'r', 'A title', 'A body text', '', 'alice'];
		return [
			'issue-user-pat' => ['createIssue', $issue, 'created', 'user-pat', 'app-pat'],
			'issue-user-pat-spec' => ['createIssue', $withSpec, 'created', 'user-pat', ''],
			'issue-server-pat' => ['createIssue', $issue, 'created', '', 'app-pat'],
			'issue-server-pat-spec' => ['createIssue', $withSpec, 'created', '', 'app-pat'],
			'issue-server-pat-blank-spec' => ['createIssue', $blankSpec, 'sparse', '', 'app-pat'],
			'issue-no-pat' => ['createIssue', $issue, 'created', '', ''],
			'issue-user-pat-422' => ['createIssue', $issue, '422', 'user-pat', 'app-pat'],
			'issue-server-pat-422' => ['createIssue', $withSpec, '422', '', 'app-pat'],
			'issue-server-pat-connect' => ['createIssue', $issue, 'connect', '', 'app-pat'],
			'issue-server-pat-not-json' => ['createIssue', $issue, 'text', '', 'app-pat'],
			'validate-app-token' => ['validateToken', [], 'created', '', 'app-pat'],
			'validate-app-token-ok' => ['validateToken', [], 'ok', '', 'app-pat'],
			'validate-no-app-token' => ['validateToken', [], 'ok', 'user-pat', ''],
			'validate-user-token' => ['validateToken', ['alice'], 'ok', 'user-pat', 'app-pat'],
			'validate-no-user-token' => ['validateToken', ['alice'], 'ok', '', 'app-pat'],
			'validate-401' => ['validateToken', ['alice'], '401', 'user-pat', ''],
			'sha' => ['getFileSha', ['org', 'app', 'dir with space/a#b.json', 'dev'], 'ok', '', 'app-pat'],
			'sha-default-branch' => ['getFileSha', ['org', 'app', 'a.json'], 'sparse', '', ''],
			'sha-404' => ['getFileSha', ['org', 'app', 'a.json'], '404', '', 'app-pat'],
			'sha-500' => ['getFileSha', ['org', 'app', 'a.json'], '500', '', 'app-pat'],
			'sha-connect' => ['getFileSha', ['org', 'app', 'a.json'], 'connect', '', 'app-pat'],
			'user-token' => ['getUserToken', ['alice'], 'ok', 'user-pat', ''],
			'user-token-none' => ['getUserToken', ['alice'], 'ok', '', ''],
			'set-user-token' => ['setUserToken', ['new-pat', 'alice'], 'ok', '', ''],
			'clear-user-token' => ['setUserToken', [null, 'alice'], 'ok', '', ''],
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
		[$method, $arguments, $kind, $userToken, $appToken] = $case;

		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(
			function (string $verb, string $url, array $options = []) use ($kind): IResponse {
				$this->calls[] = ['client.request', $verb, $url, $options];
				return $this->response(kind: $kind, url: $url);
			}
		);

		$handler = new GitHubHandler(
			client: $client,
			appConfig: $this->appConfig(token: $appToken),
			config: $this->config(token: $userToken),
			cacheFactory: $this->cacheFactory(),
			attributionFormatter: $this->attribution(),
			logger: $this->logger()
		);

		try {
			$out = ['returned' => $handler->$method(...self::contractArguments(method: $method, arguments: $arguments))];
		} catch (\Throwable $e) {
			$out = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return json_decode((string) json_encode($out + ['calls' => $this->calls]), true);
	}//end runCase()

	/**
	 * The app config double, answering the app-level token.
	 *
	 * @param string $token The app-level token.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $token): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($token): string {
				$this->calls[] = ['appConfig.getValueString', $app, $key, $default];
				return $token;
			}
		);
		return $appConfig;
	}//end appConfig()

	/**
	 * The user config double, answering the per-user token.
	 *
	 * @param string $token The per-user token.
	 *
	 * @return IConfig
	 */
	private function config(string $token): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, mixed $default = '') use ($token): string {
				$this->calls[] = ['config.getUserValue', $userId, $app, $key, $default];
				return $token;
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, mixed $value): void {
				$this->calls[] = ['config.setUserValue', $userId, $app, $key, $value];
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key): void {
				$this->calls[] = ['config.deleteUserValue', $userId, $app, $key];
			}
		);
		return $config;
	}//end config()

	/**
	 * A cache factory with an unused cache.
	 *
	 * @return ICacheFactory
	 */
	private function cacheFactory(): ICacheFactory {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		return $cacheFactory;
	}//end cacheFactory()

	/**
	 * The attribution formatter double.
	 *
	 * @return AttributionFormatter
	 */
	private function attribution(): AttributionFormatter {
		$attribution = $this->createMock(AttributionFormatter::class);
		$attribution->method('format')->willReturnCallback(
			function (string $userId): string {
				$this->calls[] = ['attribution.format', $userId];
				return "> Submitted by {$userId}\n\n";
			}
		);
		return $attribution;
	}//end attribution()

	/**
	 * The logger double; drops `file`/`line` and reduces a timestamp to its presence.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					if (array_key_exists('timestamp', $context) === true) {
						$context['timestamp'] = (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $context['timestamp']) === 1);
					}

					$this->calls[] = ['log.'.$level, (string) $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()

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

		$bodies = [
			'created' => [201, '{"number":42,"html_url":"https://github.com/o/r/issues/42","state":"open","sha":"s1"}'],
			'ok' => [200, '{"sha":"s2","login":"me"}'],
			'sparse' => [201, '{}'],
			'text' => [201, 'not json'],
		];
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($bodies[$kind][0]);
		$response->method('getBody')->willReturn($bodies[$kind][1]);
		return $response;
	}//end response()

	/**
	 * The recorded positional arguments in the shape the client contract takes.
	 *
	 * @param string            $method    The handler method.
	 * @param array<int, mixed> $arguments The recorded arguments.
	 *
	 * @return array<int, mixed>
	 */
	private static function contractArguments(string $method, array $arguments): array {
		return match ($method) {
			'createIssue' => [
				new GitHubIssueSubmission(owner: $arguments[0], repo: $arguments[1], userId: $arguments[5], specRef: $arguments[4]),
				new GitHubIssueText(title: $arguments[2], body: $arguments[3]),
			],
			'getFileSha' => [
				new GitHubFileLocation(owner: $arguments[0], repo: $arguments[1], path: $arguments[2], branch: ($arguments[3] ?? 'main')),
			],
			default => $arguments,
		};
	}//end contractArguments()
}//end class
