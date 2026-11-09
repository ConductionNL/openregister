<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\Service\Chat;

use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\Chat\MessageHistoryHandler;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An assistant message with RAG sources is stored, with the sources on the
 * entity's `sources` column.
 *
 * @covers \OCA\OpenRegister\Mcp\Service\Chat\MessageHistoryHandler
 */
class MessageHistoryHandlerSourcesTest extends TestCase
{

    public function testMessageWithSourcesIsStoredWithItsSources(): void
    {
        $sources = [['type' => 'object', 'uuid' => 'u-1', 'name' => 'Doc']];
        $mapper  = $this->createMock(MessageMapperInterface::class);
        $mapper->expects($this->once())->method('insert')->willReturnArgument(0);
        $handler = new MessageHistoryHandler($mapper, $this->createMock(ConversationMapper::class), $this->createMock(LoggerInterface::class));

        $message = $handler->storeAssistantMessage(5, 'answer', $sources);

        $this->assertSame($sources, $message->getSources());
        $this->assertSame($sources, $message->jsonSerialize()['sources']);
    }

    public function testMessageWithoutSourcesLeavesTheColumnUntouched(): void
    {
        $mapper = $this->createMock(MessageMapperInterface::class);
        $mapper->method('insert')->willReturnArgument(0);
        $handler = new MessageHistoryHandler($mapper, $this->createMock(ConversationMapper::class), $this->createMock(LoggerInterface::class));

        $message = $handler->storeAssistantMessage(5, 'answer', []);

        $this->assertNull($message->getSources());
        $this->assertArrayNotHasKey('sources', $message->getUpdatedFields());
    }
}
