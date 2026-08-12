<?php

declare(strict_types=1);

/*
 * Tests for ResponseGenerationHandler streaming path.
 *
 * Drives the private `streamChat()` indirectly via a TestableResponseGenerationHandler
 * subclass that exposes a public driver. The LLPhant chat instance is a
 * PHPUnit-mocked OllamaChat (both OllamaChat and OpenAIChat expose the
 * `generateChatStream(array $messages): StreamInterface` surface; mocking
 * either satisfies the handler's `OpenAIChat|OllamaChat` union).
 *
 * Tasks covered: §2.2 (streaming branch invoked when channel + capability
 * present), §2.3 (MissingFeatureException degrades to blocking), §2.6
 * (5 token deltas → 5 channel emits).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Chat
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Chat;

use LLPhant\Chat\OllamaChat;
use LLPhant\Exception\MissingFeatureException;
use OCA\OpenRegister\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
use OCA\OpenRegister\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Exposes the private streamChat()/invokeChat() helpers so tests can call
 * them without spinning up the full generateResponse() pipeline (which
 * requires SettingsService config wiring + LLPhant instantiation).
 */
class TestableResponseGenerationHandler extends ResponseGenerationHandler {

	public function invokeChatPublic(
		OllamaChat $chat,
		array $messageHistory,
		?StreamYieldChannel $channel,
		string $provider,
	): string {
		$reflection = new ReflectionClass(parent::class);
		$method = $reflection->getMethod('invokeChat');
		$method->setAccessible(true);

		return (string)$method->invoke($this, $chat, $messageHistory, $channel, $provider);
	}//end invokeChatPublic()
}//end class

class ResponseGenerationHandlerStreamingTest extends TestCase {

	private function makeHandler(): TestableResponseGenerationHandler {
		$settings = $this->createMock(SettingsService::class);
		$toolHandler = $this->createMock(ToolManagementHandler::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new TestableResponseGenerationHandler(
			settingsService: $settings,
			toolHandler: $toolHandler,
			logger: $logger
		);
	}//end makeHandler()

	/**
	 * §2.2 + §2.6 — five token deltas arrive via the PSR-7 stream, the
	 * channel fires onToken once per network chunk, and the assembled
	 * return string concatenates them all.
	 *
	 * Note: PSR-7's byte-oriented `read()` MAY batch multiple LLPhant
	 * generator yields into a single read (Guzzle's PumpStream pumps
	 * until the requested length is satisfied). In production each HTTP
	 * chunk usually maps 1:1 to a `read()` call. The contract guarantee
	 * is "one token frame per network chunk", not "one frame per token";
	 * the widget concatenates either way. This test asserts on
	 * assembled-text correctness + at-least-one chunk emit.
	 */
	public function testStreamingChatEmitsTokensAndAssemblesFullText(): void {
		$deltas = ['Hel', 'lo', ' ', 'wor', 'ld'];

		// Build a stream stub whose read() returns each delta on a separate
		// call (mimicking the per-chunk read pattern the production code
		// sees against a real HTTP-streamed response).
		$stream = new class($deltas) implements StreamInterface {

			private int $index = 0;

			public function __construct(
				private array $chunks,
			) {
			}

			public function read($length): string {
				if ($this->index >= count($this->chunks)) {
					return '';
				}

				return $this->chunks[$this->index++];
			}

			public function eof(): bool {
				return $this->index >= count($this->chunks);
			}

			// Unused PSR-7 surface — keep the implementation minimal.
			public function __toString(): string {
				return implode('', $this->chunks);
			}
			public function close(): void {
			}
			public function detach() {
				return null;
			}
			public function getSize(): ?int {
				return null;
			}
			public function tell(): int {
				return $this->index;
			}
			public function isSeekable(): bool {
				return false;
			}
			public function seek($offset, $whence = SEEK_SET): void {
			}
			public function rewind(): void {
				$this->index = 0;
			}
			public function isWritable(): bool {
				return false;
			}
			public function write($string): int {
				return 0;
			}
			public function isReadable(): bool {
				return true;
			}
			public function getContents(): string {
				return implode('', array_slice($this->chunks, $this->index));
			}
			public function getMetadata($key = null) {
				return null;
			}
		};

		$chat = $this->createMock(OllamaChat::class);
		$chat->method('generateChatStream')->willReturn($stream);

		$channel = new StreamYieldChannel();
		$captured = [];
		$channel->onToken(static function (string $delta) use (&$captured): void {
			$captured[] = $delta;
		});

		$handler = $this->makeHandler();
		$assembled = $handler->invokeChatPublic(
			chat: $chat,
			messageHistory: [],
			channel: $channel,
			provider: 'ollama'
		);

		$this->assertSame($deltas, $captured, 'channel onToken fires once per network chunk');
		$this->assertSame('Hello world', $assembled, 'assembled string concatenates all chunks');
	}//end testStreamingChatEmitsTokensAndAssemblesFullText()

	/**
	 * §2.3 — MissingFeatureException on the streaming surface degrades to
	 * the blocking generateChat() call. Zero tokens are emitted.
	 */
	public function testMissingFeatureExceptionDegradesToBlockingCall(): void {
		$chat = $this->createMock(OllamaChat::class);
		$chat->method('generateChatStream')
			->willThrowException(new MissingFeatureException('streaming not enabled'));
		$chat->method('generateChat')->willReturn('blocking answer');

		$channel = new StreamYieldChannel();
		$captured = [];
		$channel->onToken(static function (string $delta) use (&$captured): void {
			$captured[] = $delta;
		});

		$handler = $this->makeHandler();
		$result = $handler->invokeChatPublic(
			chat: $chat,
			messageHistory: [],
			channel: $channel,
			provider: 'ollama'
		);

		$this->assertSame('blocking answer', $result, 'degrades to the blocking generateChat() call');
		$this->assertCount(0, $captured, 'no token frames must be emitted on the degraded path');
	}//end testMissingFeatureExceptionDegradesToBlockingCall()

	/**
	 * §2.5 — null channel always uses the blocking call (load-bearing for
	 * POST /api/chat/send).
	 */
	public function testNullChannelAlwaysUsesBlockingCall(): void {
		$chat = $this->createMock(OllamaChat::class);
		$chat->expects($this->never())->method('generateChatStream');
		$chat->method('generateChat')->willReturn('blocking answer');

		$handler = $this->makeHandler();
		$result = $handler->invokeChatPublic(
			chat: $chat,
			messageHistory: [],
			channel: null,
			provider: 'ollama'
		);

		$this->assertSame('blocking answer', $result);
	}//end testNullChannelAlwaysUsesBlockingCall()
}//end class
