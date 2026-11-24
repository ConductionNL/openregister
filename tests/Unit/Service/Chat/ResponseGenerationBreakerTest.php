<?php

/**
 * The chat call goes through the `llm` breaker: counted while closed, refused
 * without a request to the provider while open, and the refusal is not wrapped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Chat;

use Exception;
use OCA\OpenRegister\Mcp\Service\Chat\ReplyGrounding;
use OCA\OpenRegister\Mcp\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Mcp\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Kernel\Contracts\Value\ChatMessageRequest;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\BreakerThresholds;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Unit\Service\Resilience\FakeMemcache;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Resilience/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Mcp\Service\Chat\ResponseGenerationHandler
 * @uses   \OCA\OpenRegister\Mcp\Service\Chat\ReplyGrounding
 * @uses   \OCA\OpenRegister\Mcp\Service\Chat\ResponseRun
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ChatMessageRequest
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerRegistry
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 * @uses   \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 */
class ResponseGenerationBreakerTest extends TestCase {

	/** @var resource|null The stub server process. */
	private static $server = null;

	private static string $base = '';

	private static string $requestLog = '';

	private int $now = 1_000_000;

	private CircuitBreaker $breaker;

	public static function setUpBeforeClass(): void {
		$probe = stream_socket_server('tcp://127.0.0.1:0');
		$port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
		fclose($probe);

		self::$requestLog = (string) tempnam(sys_get_temp_dir(), 'llm-breaker');
		self::$base       = 'http://127.0.0.1:' . $port;
		self::$server     = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/llm-stub-router.php'],
			[['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
			$pipes,
			null,
			array_merge(getenv(), ['LLM_STUB_LOG' => self::$requestLog])
		);
		for ($i = 0; $i < 100; $i++) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
			if ($socket !== false) {
				fclose($socket);
				break;
			}

			usleep(50000);
		}
	}

	public static function tearDownAfterClass(): void {
		if (is_resource(self::$server) === true) {
			proc_terminate(self::$server);
			proc_close(self::$server);
		}

		@unlink(self::$requestLog);
	}

	protected function setUp(): void {
		parent::setUp();
		file_put_contents(self::$requestLog, '');
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->breaker = new CircuitBreaker(cache: new FakeMemcache(), thresholds: new BreakerThresholds(), clock: $clock, logger: new NullLogger(), shared: true);
	}

	/**
	 * A handler for Ollama on the stub, with the given model, guarded by the test's breaker.
	 *
	 * @param string $model The chat model; `model-error` makes the stub answer 500.
	 *
	 * @return ResponseGenerationHandler
	 */
	private function handler(string $model = 'llama-test'): ResponseGenerationHandler {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLLMSettingsOnly')->willReturn(['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => self::$base . '/', 'chatModel' => $model]]);

		$tools = $this->createMock(ToolManagementHandler::class);
		$tools->method('getAgentTools')->willReturn([]);

		$registry = $this->createMock(BreakerRegistry::class);
		$registry->method('breaker')->willReturn($this->breaker);

		return new ResponseGenerationHandler(settingsService: $settings, toolHandler: $tools, logger: new NullLogger(), breakers: new DependencyBreaker($registry));
	}

	private function ask(ResponseGenerationHandler $handler): string {
		return $handler->generateResponse(new ChatMessageRequest(0, 'user', 'Hello'), new ReplyGrounding(['text' => '', 'sources' => []], [], null));
	}

	private function requestsSent(): int {
		return count(array_filter(explode("\n", (string) file_get_contents(self::$requestLog))));
	}

	public function testAClosedBreakerLetsTheChatThroughAndCountsIt(): void {
		$response = $this->ask($this->handler());

		$this->assertSame('Answer from /api/chat', $response);
		$this->assertSame(1, $this->requestsSent());
		$state = $this->breaker->state(BreakerRegistry::llmKey());
		$this->assertSame('closed', $state['state']);
		$this->assertSame(1, $state['calls']);
		$this->assertSame(0, $state['failures']);
	}

	public function testFailingChatsOpenTheBreakerAndThenNoRequestIsSent(): void {
		$handler = $this->handler(model: 'model-error');
		for ($i = 0; $i < 10; $i++) {
			try {
				$this->ask($handler);
				$this->fail('The stub answers 500 for model-error.');
			} catch (DependencyUnavailableException $refused) {
				$this->fail('The breaker opened before ten calls: ' . $refused->getMessage());
			} catch (Exception $e) {
				$this->assertStringStartsWith('Failed to generate response', $e->getMessage());
			}
		}

		$this->assertSame(10, $this->requestsSent());
		$this->assertSame('open', $this->breaker->state(BreakerRegistry::llmKey())['state']);

		try {
			$this->ask($this->handler());
			$this->fail('An open llm breaker must refuse.');
		} catch (DependencyUnavailableException $refused) {
			$this->assertSame('llm', $refused->dependency());
			$this->assertSame(30, $refused->retryAfter());
		}

		$this->assertSame(10, $this->requestsSent(), 'No request reaches the provider while the breaker is open.');
	}

	public function testAnOpenBreakerRefusesBeforeTheChatClientIsBuilt(): void {
		for ($i = 0; $i < 10; $i++) {
			$this->breaker->recordFailure(BreakerRegistry::llmKey());
		}

		$this->expectException(DependencyUnavailableException::class);
		try {
			$this->ask($this->handler());
		} finally {
			$this->assertSame(0, $this->requestsSent());
		}
	}

	public function testAGoodProbeAfterTheCoolDownClosesTheBreaker(): void {
		for ($i = 0; $i < 10; $i++) {
			$this->breaker->recordFailure(BreakerRegistry::llmKey());
		}

		$this->now += 31;
		$this->assertSame('Answer from /api/chat', $this->ask($this->handler()));
		$this->assertSame('closed', $this->breaker->state(BreakerRegistry::llmKey())['state']);
	}
}
