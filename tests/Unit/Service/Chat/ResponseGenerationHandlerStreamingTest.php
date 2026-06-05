<?php

declare(strict_types=1);

<<<<<<< HEAD
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
class TestableResponseGenerationHandler extends ResponseGenerationHandler
{

    public function invokeChatPublic(
        OllamaChat $chat,
        array $messageHistory,
        ?StreamYieldChannel $channel,
        string $provider
    ): string {
        $reflection = new ReflectionClass(parent::class);
        $method     = $reflection->getMethod('invokeChat');
        $method->setAccessible(true);

        return (string) $method->invoke($this, $chat, $messageHistory, $channel, $provider);
    }//end invokeChatPublic()
}//end class

class ResponseGenerationHandlerStreamingTest extends TestCase
{

    private function makeHandler(): TestableResponseGenerationHandler
    {
        $settings    = $this->createMock(SettingsService::class);
        $toolHandler = $this->createMock(ToolManagementHandler::class);
        $logger      = $this->createMock(LoggerInterface::class);

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
    public function testStreamingChatEmitsTokensAndAssemblesFullText(): void
    {
        $deltas = ['Hel', 'lo', ' ', 'wor', 'ld'];

        // Build a stream stub whose read() returns each delta on a separate
        // call (mimicking the per-chunk read pattern the production code
        // sees against a real HTTP-streamed response).
        $stream = new class($deltas) implements StreamInterface
        {

            private int $index = 0;

            public function __construct(private array $chunks)
            {
            }

            public function read($length): string
            {
                if ($this->index >= count($this->chunks)) {
                    return '';
                }

                return $this->chunks[$this->index++];
            }

            public function eof(): bool
            {
                return $this->index >= count($this->chunks);
            }

            // Unused PSR-7 surface — keep the implementation minimal.
            public function __toString(): string
            {
                return implode('', $this->chunks);
            }
            public function close(): void
            {
            }
            public function detach()
            {
                return null;
            }
            public function getSize(): ?int
            {
                return null;
            }
            public function tell(): int
            {
                return $this->index;
            }
            public function isSeekable(): bool
            {
                return false;
            }
            public function seek($offset, $whence=SEEK_SET): void
            {
            }
            public function rewind(): void
            {
                $this->index = 0;
            }
            public function isWritable(): bool
            {
                return false;
            }
            public function write($string): int
            {
                return 0;
            }
            public function isReadable(): bool
            {
                return true;
            }
            public function getContents(): string
            {
                return implode('', array_slice($this->chunks, $this->index));
            }
            public function getMetadata($key=null)
            {
                return null;
            }
        };

        $chat = $this->createMock(OllamaChat::class);
        $chat->method('generateChatStream')->willReturn($stream);

        $channel  = new StreamYieldChannel();
        $captured = [];
        $channel->onToken(static function (string $delta) use (&$captured): void {
            $captured[] = $delta;
        });

        $handler   = $this->makeHandler();
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
    public function testMissingFeatureExceptionDegradesToBlockingCall(): void
    {
        $chat = $this->createMock(OllamaChat::class);
        $chat->method('generateChatStream')
            ->willThrowException(new MissingFeatureException('streaming not enabled'));
        $chat->method('generateChat')->willReturn('blocking answer');

        $channel  = new StreamYieldChannel();
        $captured = [];
        $channel->onToken(static function (string $delta) use (&$captured): void {
            $captured[] = $delta;
        });

        $handler = $this->makeHandler();
        $result  = $handler->invokeChatPublic(
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
    public function testNullChannelAlwaysUsesBlockingCall(): void
    {
        $chat = $this->createMock(OllamaChat::class);
        $chat->expects($this->never())->method('generateChatStream');
        $chat->method('generateChat')->willReturn('blocking answer');

        $handler = $this->makeHandler();
        $result  = $handler->invokeChatPublic(
            chat: $chat,
            messageHistory: [],
            channel: null,
            provider: 'ollama'
        );

        $this->assertSame('blocking answer', $result);
    }//end testNullChannelAlwaysUsesBlockingCall()
=======
namespace Unit\Service\Chat;

use GuzzleHttp\Psr7\Utils;
use OCA\OpenRegister\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Chat\ToolManagementHandler;
use LLPhant\Chat\OllamaChat;
use LLPhant\OllamaConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

// phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint

/**
 * Tests the streaming path of ResponseGenerationHandler.
 *
 * Uses a testable subclass that injects a mock chat whose generateChatStream()
 * returns a PSR-7 stream emitting 5 token deltas.
 *
 * @covers \OCA\OpenRegister\Service\Chat\ResponseGenerationHandler
 */
class ResponseGenerationHandlerStreamingTest extends TestCase
{

    private SettingsService&MockObject $settingsService;

    private ToolManagementHandler&MockObject $toolHandler;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->toolHandler     = $this->createMock(ToolManagementHandler::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

    }//end setUp()

    /**
     * Build an Ollama-provider handler whose OllamaChat is replaced by a mock.
     *
     * @param OllamaChat&MockObject $mockChat Mock OllamaChat instance.
     */
    private function buildHandlerWithMockChat(OllamaChat $mockChat): ResponseGenerationHandler
    {
        $handler = new class (
            $this->settingsService,
            $this->toolHandler,
            $this->logger,
            $mockChat
        ) extends ResponseGenerationHandler {

            /**
             * @var OllamaChat&MockObject
             */
            private OllamaChat $injectedChat;

            public function __construct(
                SettingsService $settingsService,
                ToolManagementHandler $toolHandler,
                LoggerInterface $logger,
                OllamaChat $chat
            ) {
                parent::__construct(
                    settingsService: $settingsService,
                    toolHandler: $toolHandler,
                    logger: $logger
                );
                $this->injectedChat = $chat;
            }//end __construct()

            protected function createOllamaChat(OllamaConfig $config): OllamaChat
            {
                return $this->injectedChat;

            }//end createOllamaChat()
        };

        return $handler;

    }//end buildHandlerWithMockChat()

    /**
     * Build a PSR-7 stream that emits $deltas one per read().
     *
     * @param string[] $deltas
     */
    private function buildTokenStream(array $deltas): StreamInterface
    {
        $index     = 0;
        $generator = (function () use ($deltas): \Generator {
            foreach ($deltas as $delta) {
                yield $delta;
            }
        })();

        return Utils::streamFor($generator);

    }//end buildTokenStream()

    /**
     * Ollama LLM config returned by the mock SettingsService.
     */
    private function ollamaLlmConfig(): array
    {
        return [
            'chatProvider' => 'ollama',
            'ollamaConfig' => [
                'url'       => 'http://localhost:11434',
                'chatModel' => 'llama3',
            ],
        ];

    }//end ollamaLlmConfig()

    public function testStreamingPathEmitsTokensViaChannel(): void
    {
        $deltas = ['Hel', 'lo', ' ', 'wor', 'ld'];

        // Mock OllamaChat — PHPUnit mocks concrete classes without calling the constructor.
        $mockChat = $this->createMock(OllamaChat::class);
        $mockChat->method('generateChatStream')->willReturn($this->buildTokenStream($deltas));

        $this->settingsService->method('getLLMSettingsOnly')->willReturn($this->ollamaLlmConfig());
        $this->toolHandler->method('getAgentTools')->willReturn([]);

        $handler = $this->buildHandlerWithMockChat($mockChat);

        $captured = [];
        $channel  = new StreamYieldChannel();
        $channel->onToken(
                function (string $delta) use (&$captured): void {
                    $captured[] = $delta;
                }
                );

        $result = $handler->generateResponse(
            userMessage: 'Say hello',
            context: ['text' => '', 'sources' => []],
            messageHistory: [],
            agent: null,
            selectedTools: [],
            channel: $channel
        );

        // The PumpStream may batch multiple generator yields in one read() call,
        // so assert content equality rather than emission count.
        self::assertNotEmpty($captured, 'At least one token emission is expected.');
        self::assertSame('Hello world', implode('', $captured), 'All token deltas must be forwarded via the channel.');
        self::assertSame('Hello world', $result, 'Full text must equal the concatenated deltas.');

    }//end testStreamingPathEmitsTokensViaChannel()

    public function testNullChannelAlwaysUsesBlockingCall(): void
    {
        $mockChat = $this->createMock(OllamaChat::class);
        $mockChat->expects(self::once())
            ->method('generateChat')
            ->willReturn('blocking response');
        $mockChat->expects(self::never())
            ->method('generateChatStream');

        $this->settingsService->method('getLLMSettingsOnly')->willReturn($this->ollamaLlmConfig());
        $this->toolHandler->method('getAgentTools')->willReturn([]);

        $handler = $this->buildHandlerWithMockChat($mockChat);

        $result = $handler->generateResponse(
            userMessage: 'test',
            context: ['text' => '', 'sources' => []],
            messageHistory: [],
            agent: null
        );

        self::assertSame('blocking response', $result);

    }//end testNullChannelAlwaysUsesBlockingCall()

    public function testDegradesToBlockingOnMissingFeatureException(): void
    {
        $mockChat = $this->createMock(OllamaChat::class);
        $mockChat->method('generateChatStream')
            ->willThrowException(new \LLPhant\Exception\MissingFeatureException('streaming not supported'));
        $mockChat->expects(self::once())
            ->method('generateChat')
            ->willReturn('fallback response');

        $this->settingsService->method('getLLMSettingsOnly')->willReturn($this->ollamaLlmConfig());
        $this->toolHandler->method('getAgentTools')->willReturn([]);
        $this->logger->expects(self::atLeastOnce())->method('info');

        $handler = $this->buildHandlerWithMockChat($mockChat);

        $channel = new StreamYieldChannel();
        $result  = $handler->generateResponse(
            userMessage: 'test',
            context: ['text' => '', 'sources' => []],
            messageHistory: [],
            agent: null,
            selectedTools: [],
            channel: $channel
        );

        self::assertSame('fallback response', $result);

    }//end testDegradesToBlockingOnMissingFeatureException()
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
}//end class
