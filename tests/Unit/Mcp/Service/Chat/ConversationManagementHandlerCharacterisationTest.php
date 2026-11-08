<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\Service\Chat;

use DateTime;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\Chat\ConversationManagementHandler;
use OCA\OpenRegister\Mcp\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Notifications\Db\Message;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Mcp\Service\Chat\ConversationStores;

/**
 * Pins the observable outcomes of title generation, unique-title numbering
 * and the summarisation trigger without reaching a real LLM endpoint.
 *
 * @covers \OCA\OpenRegister\Mcp\Service\Chat\ConversationManagementHandler
 */
class ConversationManagementHandlerCharacterisationTest extends TestCase
{

    /** @var ConversationMapper&MockObject */
    private ConversationMapper $conversationMapper;

    /** @var MessageMapperInterface&MockObject */
    private MessageMapperInterface $messageMapper;

    /** @var SettingsServiceInterface&MockObject */
    private SettingsServiceInterface $settings;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var array<int, array{0: string, 1: string, 2: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->conversationMapper = $this->createMock(ConversationMapper::class);
        $this->messageMapper      = $this->createMock(MessageMapperInterface::class);
        $this->settings           = $this->createMock(SettingsServiceInterface::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->logged             = [];
        foreach (['info', 'warning', 'error'] as $level) {
            $this->logger->method($level)->willReturnCallback(
                function ($message, array $context = []) use ($level): void {
                    $this->logged[] = [$level, (string) $message, $context];
                }
            );
        }
    }

    private function handler(array $llm): ConversationManagementHandler
    {
        $this->settings->method('getLLMSettingsOnly')->willReturn($llm);
        return new ConversationManagementHandler(
        	new ConversationStores(
        		$this->conversationMapper,
        		$this->messageMapper,
        	),
        	$this->settings,
        	$this->createMock(ResponseGenerationHandler::class),
        	$this->logger,
        );
    }

    /** @return list<string> */
    private function levels(string $level): array
    {
        return array_values(array_map(fn ($l) => $l[1], array_filter($this->logged, fn ($l) => $l[0] === $level)));
    }

    /** @return list<string> */
    private function errorTexts(string $level): array
    {
        return array_values(
            array_map(
                fn ($l) => (string) ($l[2]['error'] ?? ''),
                array_filter($this->logged, fn ($l) => $l[0] === $level)
            )
        );
    }

    /** @return array<string, array{0: array}> */
    public static function fallbackWithoutCallProvider(): array
    {
        return [
            'no provider'        => [[]],
            'empty provider'     => [['chatProvider' => '']],
            'unknown provider'   => [['chatProvider' => 'anthropic', 'openaiConfig' => ['apiKey' => 'k']]],
            'ollama without url' => [['chatProvider' => 'ollama', 'ollamaConfig' => ['chatModel' => 'm']]],
            'openai without key' => [['chatProvider' => 'openai', 'openaiConfig' => []]],
            'fireworks no key'   => [['chatProvider' => 'fireworks', 'fireworksConfig' => ['baseUrl' => 'http://x']]],
        ];
    }

    #[DataProvider('fallbackWithoutCallProvider')]
    public function testTitleFallsBackWithoutWarning(array $llm): void
    {
        $handler = $this->handler($llm);
        $this->assertSame('Short message', $handler->generateConversationTitle('Short message'));
        $this->assertSame([], $this->levels('warning'));
    }

    public function testFallbackTitleCutsAtLastSpaceAfterThirtyChars(): void
    {
        $message = 'This is a fairly long first message that keeps going well past sixty characters';
        $this->assertSame(
            'This is a fairly long first message that keeps going well...',
            $this->handler([])->generateConversationTitle($message)
        );
    }

    public function testFallbackTitleHardCutsWhenNoLateSpace(): void
    {
        $message = 'short '.str_repeat('x', 80);
        $this->assertSame(substr($message, 0, 60).'...', $this->handler([])->generateConversationTitle($message));
    }

    public function testFallbackTitleKeepsExactlySixtyChars(): void
    {
        $message = str_repeat('y', 60);
        $this->assertSame($message, $this->handler([])->generateConversationTitle($message));
    }

    /** @return array<string, array{0: array}> */
    public static function failingCallProvider(): array
    {
        return [
            'ollama unreachable'    => [['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => 'http://127.0.0.1:1/']]],
            'fireworks unreachable' => [['chatProvider' => 'fireworks', 'fireworksConfig' => ['apiKey' => 'k', 'baseUrl' => 'http://127.0.0.1:1']]],
        ];
    }

    #[DataProvider('failingCallProvider')]
    public function testTitleFallsBackWithWarningWhenCallFails(array $llm): void
    {
        $handler = $this->handler($llm);
        $this->assertSame('Hello there', $handler->generateConversationTitle('Hello there'));
        $this->assertSame(['[ConversationManagementHandler] Failed to generate title, using fallback'], $this->levels('warning'));
    }

    public function testUniqueTitleReturnsBaseWhenNoMatches(): void
    {
        $this->conversationMapper->expects($this->once())->method('findTitlesByUserAgent')
            ->with('u1', 3, 'Title%')->willReturn([]);
        $this->assertSame('Title', $this->handler([])->ensureUniqueTitle('Title', 'u1', 3));
    }

    public function testUniqueTitleReturnsBaseWhenOnlyVariantsExist(): void
    {
        $this->conversationMapper->method('findTitlesByUserAgent')->willReturn(['Title (2)', 'Titles']);
        $this->assertSame('Title', $this->handler([])->ensureUniqueTitle('Title', 'u1', 3));
    }

    public function testUniqueTitleStartsAtTwo(): void
    {
        $this->conversationMapper->method('findTitlesByUserAgent')->willReturn(['Title']);
        $this->assertSame('Title (2)', $this->handler([])->ensureUniqueTitle('Title', 'u1', 3));
    }

    public function testUniqueTitleUsesHighestNumberedSuffix(): void
    {
        $this->conversationMapper->method('findTitlesByUserAgent')->willReturn(
            ['a.b (c)', 'a.b (c) (5)', 'a.b (c) (3)', 'axb (c) (9)', 'a.b (c) (x)', 'a.b (c) (7) more']
        );
        $this->assertSame('a.b (c) (6)', $this->handler([])->ensureUniqueTitle('a.b (c)', 'u1', 3));
    }

    private function conversation(array $metadata): Conversation
    {
        $conversation = new Conversation();
        $conversation->setId(42);
        $conversation->setMetadata($metadata);
        return $conversation;
    }

    /** @return list<Message> */
    private function messages(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $m = new Message();
            $m->setRole($i % 2 === 0 ? Message::ROLE_USER : 'assistant');
            $m->setContent('m'.$i);
            $out[] = $m;
        }
        return $out;
    }

    public function testSummariseSkipsBelowTokenThreshold(): void
    {
        $this->messageMapper->expects($this->never())->method('findByConversation');
        $this->handler([])->checkAndSummarize($this->conversation(['token_count' => 3999]));
        $this->handler([])->checkAndSummarize($this->conversation([]));
        $this->assertSame([], $this->logged);
    }

    public function testSummariseSkipsWhenSummarisedWithinTheHour(): void
    {
        $this->messageMapper->expects($this->never())->method('findByConversation');
        $recent = (new DateTime('-10 minutes'))->format('c');
        $this->handler([])->checkAndSummarize($this->conversation(['token_count' => 5000, 'last_summary_at' => $recent]));
        $this->assertSame([], $this->logged);
    }

    public function testSummariseStopsWhenOnlyRecentMessagesExist(): void
    {
        $old = (new DateTime('-2 hours'))->format('c');
        $this->messageMapper->expects($this->once())->method('findByConversation')->with(42)->willReturn($this->messages(10));
        $this->conversationMapper->expects($this->never())->method('update');
        $this->handler(['chatProvider' => 'openai'])->checkAndSummarize(
            $this->conversation(['token_count' => 4000, 'last_summary_at' => $old])
        );
        $this->assertSame(['[ConversationManagementHandler] Triggering conversation summarization'], $this->levels('info'));
        $this->assertSame([], $this->levels('error'));
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function summaryFailureProvider(): array
    {
        return [
            'no provider'    => [[], 'Chat provider not configured'],
            'ollama no url'  => [['chatProvider' => 'ollama'], 'Ollama URL not configured'],
            'openai no key'  => [['chatProvider' => 'openai', 'openaiConfig' => ['apiKey' => '']], 'OpenAI API key not configured'],
            'fireworks no key' => [['chatProvider' => 'fireworks'], 'Fireworks AI API key not configured'],
            'fireworks call' => [['chatProvider' => 'fireworks', 'fireworksConfig' => ['apiKey' => 'k']], 'callFireworksChatAPI'],
            'ollama call'    => [['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => 'http://127.0.0.1:1']], ''],
        ];
    }

    #[DataProvider('summaryFailureProvider')]
    public function testSummaryFailureIsLoggedAndNothingStored(array $llm, string $error): void
    {
        $this->messageMapper->method('findByConversation')->willReturn($this->messages(12));
        $this->conversationMapper->expects($this->never())->method('update');
        $this->handler($llm)->checkAndSummarize($this->conversation(['token_count' => 9000]));
        $this->assertSame(['[ConversationManagementHandler] Failed to summarize conversation'], $this->levels('error'));
        $this->assertStringContainsString($error, $this->errorTexts('error')[0]);
    }
}
