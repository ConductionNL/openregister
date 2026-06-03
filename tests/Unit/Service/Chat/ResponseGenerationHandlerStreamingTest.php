<?php

declare(strict_types=1);

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
}//end class
