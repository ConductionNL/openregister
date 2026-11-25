<?php

/**
 * CodeRunnerClient: the flow-code-runner ExApp reached through a fake AppAPI.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\CodeRun;
use OCA\OpenRegister\Service\Flow\CodeRunAnswer;
use OCA\OpenRegister\Service\Flow\CodeRunFailedException;
use OCA\OpenRegister\Service\Flow\CodeRunnerClient;
use OCP\App\IAppManager;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for AppAPI's PublicFunctions that records the calls and answers what it is told.
 */
class FakeCodeRunnerPublicFunctions {

	/**
	 * The calls, as [appId, route, method, params].
	 *
	 * @var array<int, array>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param mixed $answer What exAppRequest returns, or a Throwable it throws.
	 */
	public function __construct(public mixed $answer) {
	}

	/**
	 * Record one call and answer it.
	 *
	 * @param string      $appId  The ExApp.
	 * @param string      $route  The route.
	 * @param string|null $userId The user.
	 * @param string      $method The method.
	 * @param array       $params The parameters.
	 *
	 * @return mixed The configured answer.
	 */
	public function exAppRequest(string $appId, string $route, ?string $userId, string $method, array $params): mixed {
		$this->calls[] = [$appId, $route, $method, $params];
		if ($this->answer instanceof \Throwable) {
			throw $this->answer;
		}

		return $this->answer;
	}
}

/**
 * @covers \OCA\OpenRegister\Service\Flow\CodeRunnerClient
 * @covers \OCA\OpenRegister\Service\Flow\CodeRunFailedException
 * @uses \OCA\OpenRegister\Service\Flow\CodeRun
 * @uses \OCA\OpenRegister\Service\Flow\CodeRunAnswer
 */
final class CodeRunnerClientTest extends TestCase {

	/**
	 * Which apps are enabled.
	 *
	 * @var array<string, bool>
	 */
	private array $enabled = ['app_api' => true, 'flow-code-runner' => true];

	/**
	 * The cache's contents.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * Make AppAPI's class name resolvable, as it is where AppAPI is installed.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		if (class_exists('OCA\\AppAPI\\PublicFunctions') === false) {
			class_alias(FakeCodeRunnerPublicFunctions::class, 'OCA\\AppAPI\\PublicFunctions');
		}
	}

	/**
	 * A response with a status and a body.
	 *
	 * @param int   $status The status.
	 * @param mixed $body   The body; an array is JSON-encoded.
	 *
	 * @return IResponse
	 */
	private function response(int $status, mixed $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(is_array($body) === true ? json_encode($body) : $body);

		return $response;
	}

	/**
	 * A client over a fake AppAPI.
	 *
	 * @param FakeCodeRunnerPublicFunctions $appApi The fake.
	 * @param bool                          $cache  Whether a cache backend is there.
	 *
	 * @return CodeRunnerClient
	 */
	private function client(FakeCodeRunnerPublicFunctions $appApi, bool $cache=true): CodeRunnerClient {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => ($this->enabled[$app] ?? false));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\AppAPI\\PublicFunctions')->willReturn($appApi);
		$factory = $this->createMock(ICacheFactory::class);
		if ($cache === true) {
			$store = $this->createMock(ICache::class);
			$store->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cached[$key] ?? null));
			$store->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
				$this->cached[$key] = $value;
				return true;
			});
			$factory->method('createDistributed')->willReturn($store);
		} else {
			$factory->method('createDistributed')->willThrowException(new RuntimeException('no cache'));
		}

		return new CodeRunnerClient($apps, $container, $factory, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A small run.
	 *
	 * @return CodeRun
	 */
	private static function call(): CodeRun {
		return new CodeRun(source: 'return item;', mode: 'perItem', items: [['json' => ['naam' => 'a']]], timeoutMs: 1000, memoryMb: 32, egress: ['api.example.org']);
	}

	public function testAHealthyRunnerIsAvailableAndTheAnswerIsCached(): void {
		$appApi = new FakeCodeRunnerPublicFunctions($this->response(200, ['status' => 'ok']));
		$client = $this->client($appApi);

		self::assertTrue($client->isAvailable());
		self::assertTrue($client->isAvailable());
		self::assertSame([['flow-code-runner', '/health', 'GET', []]], $appApi->calls);
		self::assertSame('1', $this->cached['health']);
	}

	public function testARunnerThatAnswersAnErrorIsNotAvailable(): void {
		self::assertFalse($this->client(new FakeCodeRunnerPublicFunctions($this->response(503, '')))->isAvailable());
		self::assertSame('0', $this->cached['health']);

		$this->cached = [];
		self::assertFalse($this->client(new FakeCodeRunnerPublicFunctions(['error' => 'ExApp not found']))->isAvailable());
		self::assertFalse($this->client(new FakeCodeRunnerPublicFunctions(new RuntimeException('down')), cache: false)->isAvailable());
	}

	public function testWithoutAppApiOrTheRunnerNothingIsAsked(): void {
		$appApi = new FakeCodeRunnerPublicFunctions($this->response(200, []));
		$this->enabled['flow-code-runner'] = false;
		self::assertFalse($this->client($appApi)->isAvailable());

		$this->cached = [];
		$this->enabled = ['app_api' => false, 'flow-code-runner' => true];
		self::assertFalse($this->client($appApi)->isAvailable());
		self::assertSame([], $appApi->calls);
	}

	public function testARunPostsTheSourceItemsAndLimitsAndReadsTheItemsBack(): void {
		$appApi = new FakeCodeRunnerPublicFunctions($this->response(200, ['items' => [['json' => ['naam' => 'A']]], 'logs' => ['ran', 2], 'durationMs' => 12]));

		$answer = $this->client($appApi)->run(self::call());

		self::assertInstanceOf(CodeRunAnswer::class, $answer);
		self::assertSame([['json' => ['naam' => 'A']]], $answer->items);
		self::assertSame(['ran', '2'], $answer->logs);
		self::assertSame(12, $answer->durationMs);
		self::assertSame(
			[['flow-code-runner', '/run', 'POST', [
				'source' => 'return item;',
				'mode'   => 'perItem',
				'items'  => [['json' => ['naam' => 'a']]],
				'limits' => ['timeoutMs' => 1000, 'memoryMb' => 32, 'egress' => ['api.example.org']],
			]]],
			$appApi->calls
		);
	}

	public function testTheCodesOwnFailureIsThrownWithTheRunnersLogLines(): void {
		$appApi = new FakeCodeRunnerPublicFunctions($this->response(422, ['error' => 'Script execution timed out.', 'logs' => ['start']]));

		try {
			$this->client($appApi)->run(self::call());
			self::fail('A failed code call returned items.');
		} catch (CodeRunFailedException $e) {
			self::assertSame('The code step failed: Script execution timed out.', $e->getMessage());
			self::assertSame(['start'], $e->getLogs());
		}
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function unusableAnswers(): array {
		return [
			'no body'          => [[500, 'oops'], 'The code runner answered HTTP 500 without a readable body.'],
			'no items'         => [[200, ['logs' => []]], 'The code runner answered HTTP 200 without a list of items.'],
			'items not a list' => [[200, ['items' => ['a' => []]]], 'The code runner answered HTTP 200 without a list of items.'],
			'error status'     => [[502, ['items' => []]], 'The code runner answered HTTP 502 without a list of items.'],
			'scalar item'      => [[200, ['items' => ['x']]], 'The code returned an item that is not an object.'],
		];
	}

	/**
	 * @dataProvider unusableAnswers
	 */
	public function testAnAnswerTheRunnerDoesNotGiveIsRefused(array $answer, string $message): void {
		$this->expectException(CodeRunFailedException::class);
		$this->expectExceptionMessage($message);

		$this->client(new FakeCodeRunnerPublicFunctions($this->response($answer[0], $answer[1])))->run(self::call());
	}

	public function testARunWithoutTheRunnerNamesIt(): void {
		$this->enabled['flow-code-runner'] = false;

		$this->expectException(CodeRunFailedException::class);
		$this->expectExceptionMessage('The code step needs the flow-code-runner ExApp, which is not installed, not enabled or not answering.');

		$this->client(new FakeCodeRunnerPublicFunctions($this->response(200, ['items' => []])))->run(self::call());
	}
}
