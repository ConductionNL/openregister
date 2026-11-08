<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\Service\Chat;

use LLPhant\Chat\Message as LLPhantMessage;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\Chat\MessageHistoryHandler;
use OCA\OpenRegister\Mcp\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Mcp\Service\ToolRegistry;
use OCA\OpenRegister\Mcp\Tool\ToolInterface;
use OCA\OpenRegister\Notifications\Db\Message;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins message-history building, message storage and agent tool loading.
 *
 * @covers \OCA\OpenRegister\Mcp\Service\Chat\MessageHistoryHandler
 * @covers \OCA\OpenRegister\Mcp\Service\Chat\ToolManagementHandler
 */
class ChatHistoryAndToolsCharacterisationTest extends TestCase
{

    /** @var array<int, array{0: string, 1: string, 2: array}> */
    private array $logged = [];

    private function logger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback(
                function ($message, array $context = []) use ($level): void {
                    $this->logged[] = [$level, (string) $message, $context];
                }
            );
        }
        return $logger;
    }

    private function message(?string $role, ?string $content): Message
    {
        $m = new Message();
        if ($role !== null) {
            $m->setRole($role);
        }
        if ($content !== null) {
            $m->setContent($content);
        }
        return $m;
    }

    public function testHistoryKeepsKnownRolesAndLogsSkips(): void
    {
        $mapper = $this->createMock(MessageMapperInterface::class);
        $mapper->expects($this->once())->method('findRecentByConversation')->with(7, 10)->willReturn(
            [
                $this->message('user', 'hi'),
                $this->message('assistant', 'hello'),
                $this->message('system', 'sys'),
                $this->message('tool', 'x'),
                $this->message('user', ''),
                $this->message(null, 'orphan'),
                $this->message('user', '0'),
            ]
        );
        $handler = new MessageHistoryHandler($mapper, $this->createMock(ConversationMapper::class), $this->logger());

        $history = $handler->buildMessageHistory(7);

        $this->assertCount(3, $history);
        $this->assertSame(['hi', 'hello', 'sys'], array_map(fn (LLPhantMessage $m) => $m->content, $history));
        $this->assertSame(
            [
                ['debug', '[MessageHistoryHandler] Building message history'],
                ['debug', '[MessageHistoryHandler] Adding message to history'],
                ['debug', '[MessageHistoryHandler] Adding message to history'],
                ['debug', '[MessageHistoryHandler] Adding message to history'],
                ['debug', '[MessageHistoryHandler] Adding message to history'],
                ['warning', '[MessageHistoryHandler] Unknown message role'],
                ['debug', '[MessageHistoryHandler] Adding message to history'],
                ['warning', '[MessageHistoryHandler] Skipping message with missing role or content'],
                ['debug', '[MessageHistoryHandler] Adding message to history'],
                ['warning', '[MessageHistoryHandler] Skipping message with missing role or content'],
                ['debug', '[MessageHistoryHandler] Adding message to history'],
                ['warning', '[MessageHistoryHandler] Skipping message with missing role or content'],
                ['info', '[MessageHistoryHandler] Message history built'],
            ],
            array_map(fn ($l) => [$l[0], $l[1]], $this->logged)
        );
        $this->assertSame(3, $this->logged[12][2]['historyCount']);
        $this->assertSame(['hasRole' => true, 'hasContent' => false], array_intersect_key($this->logged[7][2], ['hasRole' => 1, 'hasContent' => 1]));
    }

    /** @return array<string, array{0: ?array, 1: ?array}> */
    public static function storeProvider(): array
    {
        return [
            'nothing'      => [null, null],
            'empty arrays' => [[], []],
            'context only' => [null, ['c' => 1]],
        ];
    }

    #[DataProvider('storeProvider')]
    public function testStoreMessage(?array $sources, ?array $context): void
    {
        $mapper = $this->createMock(MessageMapperInterface::class);
        $mapper->expects($this->once())->method('insert')->willReturnArgument(0);
        $handler = new MessageHistoryHandler($mapper, $this->createMock(ConversationMapper::class), $this->logger());

        $message = $handler->storeMessage(5, 'user', 'body', $sources, $context);

        $this->assertSame(5, $message->getConversationId());
        $this->assertSame('user', $message->getRole());
        $this->assertSame('body', $message->getContent());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $message->getUuid());
        $this->assertNull($message->getSources());
        if ($context === [] || $context === null) {
            $this->assertArrayNotHasKey('context', $message->getUpdatedFields());
        } else {
            $this->assertSame($context, $message->getContext());
        }
        $this->assertFalse($this->logged[0][2]['hasSources']);
    }

    /**
     * Pins the current outcome: a non-empty sources list is written through a
     * setter the Message entity does not have, so nothing is inserted.
     */
    public function testStoreMessageWithSourcesThrowsBeforeInsert(): void
    {
        $mapper = $this->createMock(MessageMapperInterface::class);
        $mapper->expects($this->never())->method('insert');
        $handler = new MessageHistoryHandler($mapper, $this->createMock(ConversationMapper::class), $this->logger());

        $this->expectException(\BadFunctionCallException::class);
        $handler->storeMessage(5, 'assistant', 'body', ['s'], ['c' => 1]);
    }

    private function tool(): ToolInterface
    {
        return $this->createMock(ToolInterface::class);
    }

    private function toolHandler(array $registry): ToolManagementHandler
    {
        $reg = $this->createMock(ToolRegistry::class);
        $reg->method('getTool')->willReturnCallback(fn (string $id) => $registry[$id] ?? null);
        return new ToolManagementHandler($this->createMock(AgentMapper::class), $reg, $this->logger());
    }

    public function testNoAgentOrNoToolsGivesNothing(): void
    {
        $handler = $this->toolHandler(['a' => $this->tool()]);
        $this->assertSame([], $handler->getAgentTools(null, ['a']));
        $agent = new Agent();
        $this->assertSame([], $handler->getAgentTools($agent));
        $agent->setTools([]);
        $this->assertSame([], $handler->getAgentTools($agent));
        $this->assertSame([], $this->logged);
    }

    public function testLoadsRawThenPrefixedIdsAndLogsMissing(): void
    {
        $raw      = $this->tool();
        $prefixed = $this->tool();
        $dotted   = $this->tool();
        $agent    = new Agent();
        $agent->setTools(['openbuild', 'objects', 'x.y', 'ghost', 'other.missing']);
        $raw->expects($this->once())->method('setAgent')->with($agent);
        $prefixed->expects($this->once())->method('setAgent')->with($agent);
        $handler = $this->toolHandler(
            [
                'openbuild'            => $raw,
                'openregister.objects' => $prefixed,
                'openregister.x.y'     => $dotted,
            ]
        );

        $this->assertSame([$raw, $prefixed], $handler->getAgentTools($agent));
        $this->assertSame(
            [
                ['debug', '[ToolManagementHandler] Loaded tool', 'openbuild'],
                ['debug', '[ToolManagementHandler] Loaded tool', 'openregister.objects'],
                ['warning', '[ToolManagementHandler] Tool not found', 'x.y'],
                ['warning', '[ToolManagementHandler] Tool not found', 'ghost'],
                ['warning', '[ToolManagementHandler] Tool not found', 'other.missing'],
            ],
            array_map(fn ($l) => [$l[0], $l[1], $l[2]['id']], $this->logged)
        );
    }

    public function testSelectedToolsFilterAndLog(): void
    {
        $a     = $this->tool();
        $b     = $this->tool();
        $agent = new Agent();
        $agent->setTools(['a', 'b', 'c']);
        $handler = $this->toolHandler(['a' => $a, 'b' => $b]);

        $this->assertSame([$b], $handler->getAgentTools($agent, ['b', 'z']));
        $this->assertSame('[ToolManagementHandler] Filtering tools', $this->logged[0][1]);
        $this->assertSame(3, $this->logged[0][2]['agentTools']);
        $this->assertSame(2, $this->logged[0][2]['selectedTools']);
        $this->assertSame(1, $this->logged[0][2]['filteredTools']);
        $this->assertCount(2, $this->logged);
    }
}
