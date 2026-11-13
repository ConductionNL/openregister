<?php

/**
 * The queries MessageMapper sends for one conversation's messages.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Notifications\Db\Message;
use OCA\OpenRegister\Notifications\Db\MessageMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\MessageMapper
 * @uses \OCA\OpenRegister\Notifications\Db\Message
 */
final class MessageMapperQueriesTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper over a recording database.
	 *
	 * @return MessageMapper
	 */
	private function mapper(): MessageMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return new MessageMapper($db);
	}//end mapper()

	/**
	 * The recorded query, one call per line.
	 *
	 * @return string
	 */
	private function log(): string {
		return implode("\n", $this->queryLog);
	}//end log()

	/**
	 * A message row as the database returns it.
	 *
	 * @param int    $id      The row id.
	 * @param string $content The message text.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(int $id, string $content): array {
		return ['id' => $id, 'uuid' => 'u-' . $id, 'conversation_id' => 7, 'role' => 'user', 'content' => $content, 'sources' => null, 'context' => null, 'created' => '2026-01-01 10:00:00'];
	}//end row()

	public function testFindSelectsOneMessageById(): void {
		$this->queuedRows = [[self::row(3, 'hello')]];

		$message = $this->mapper()->find(3);

		$this->assertInstanceOf(Message::class, $message);
		$this->assertSame('hello', $message->getContent());
		$this->assertSame(7, $message->getConversationId());
		$log = $this->log();
		$this->assertStringContainsString('#1 from(openregister_messages, NULL)', $log);
		$this->assertStringContainsString('#1 param :p1 = 3, 1', $log);
		$this->assertStringContainsString('where(eq(id, :p1, NULL))', $log);
	}//end testFindSelectsOneMessageById()

	public function testFindWithoutARowThrows(): void {
		$this->queuedRows = [[]];

		$this->expectException(DoesNotExistException::class);
		$this->mapper()->find(99);
	}//end testFindWithoutARowThrows()

	public function testRecentMessagesAreAskedNewestFirstAndReturnedOldestFirst(): void {
		$this->queuedRows = [[self::row(3, 'third'), self::row(2, 'second'), self::row(1, 'first')]];

		$messages = $this->mapper()->findRecentByConversation(7, 3);

		$this->assertSame(['first', 'second', 'third'], array_map(static fn (Message $m): ?string => $m->getContent(), $messages));
		$log = $this->log();
		$this->assertStringContainsString('where(eq(conversation_id, :p1, NULL))', $log);
		$this->assertStringContainsString('#1 param :p1 = 7, 1', $log);
		$this->assertStringContainsString('orderBy(created, DESC)', $log);
		$this->assertStringContainsString('setMaxResults(3)', $log);
	}//end testRecentMessagesAreAskedNewestFirstAndReturnedOldestFirst()

	public function testRecentMessagesDefaultToTen(): void {
		$this->queuedRows = [[]];

		$this->assertSame([], $this->mapper()->findRecentByConversation(7));
		$this->assertStringContainsString('setMaxResults(10)', $this->log());
	}//end testRecentMessagesDefaultToTen()

	public function testCountReadsTheCountOfTheConversation(): void {
		$this->queuedRows = [[['count' => '12']]];

		$this->assertSame(12, $this->mapper()->countByConversation(7));
		$log = $this->log();
		$this->assertStringContainsString('func.count(*, count)', $log);
		$this->assertStringContainsString('where(eq(conversation_id, :p1, NULL))', $log);
		$this->assertStringContainsString('#1 param :p1 = 7, 1', $log);
	}//end testCountReadsTheCountOfTheConversation()

	public function testDeleteRemovesOnlyThatConversation(): void {
		$this->assertSame(1, $this->mapper()->deleteByConversation(7));
		$log = $this->log();
		$this->assertStringContainsString('#1 delete(openregister_messages, NULL)', $log);
		$this->assertStringContainsString('where(eq(conversation_id, :p1, NULL))', $log);
		$this->assertStringContainsString('#1 executeStatement()', $log);
	}//end testDeleteRemovesOnlyThatConversation()
}//end class
