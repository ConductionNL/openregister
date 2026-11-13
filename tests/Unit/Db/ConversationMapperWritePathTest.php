<?php

/**
 * Unit tests for ConversationMapper's single-row reads and its write path.
 *
 * Records every query-builder call and asserts the lookups by id and uuid,
 * the insert that stamps a uuid and timestamps, the update that reads the old
 * row first, the delete, soft delete and restore, and the event each write
 * dispatches.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Event\ConversationCreatedEvent;
use OCA\OpenRegister\Event\ConversationDeletedEvent;
use OCA\OpenRegister\Event\ConversationUpdatedEvent;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The conversation lookups and writes.
 *
 * @covers \OCA\OpenRegister\Mcp\Db\ConversationMapper
 * @uses   \OCA\OpenRegister\Mcp\Db\Conversation
 * @uses   \OCA\OpenRegister\Mcp\Db\ConversationColumns
 * @uses   \OCA\OpenRegister\Event\AbstractConversationEvent
 * @uses   \OCA\OpenRegister\Event\ConversationUpdatedEvent
 */
class ConversationMapperWritePathTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The events the mapper dispatched.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * The mapper over a recording connection that serves the given result sets in order.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $rows One row list per executeQuery().
	 *
	 * @return ConversationMapper
	 */
	private function mapper(array $rows = []): ConversationMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$this->dispatched = [];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->dispatched[] = $event;
			}
		);

		return new ConversationMapper($db, $events);
	}//end mapper()

	/**
	 * The statements of one query builder, by its tag.
	 *
	 * @param string $tag The builder tag, such as `#2`.
	 *
	 * @return array<int, string>
	 */
	private function statementsOf(string $tag): array {
		return array_values(array_filter($this->queryLog, static fn (string $line): bool => str_starts_with($line, $tag . ' ')));
	}//end statementsOf()

	public function testFindLooksUpByIdWithoutAnOrganisationFilter(): void {
		$conversation = $this->mapper(rows: [[['id' => 5, 'uuid' => 'c-5', 'title' => 'Hello', 'user_id' => 'alice']]])->find(id: 5);

		$this->assertSame('c-5', $conversation->getUuid());
		$this->assertSame('Hello', $conversation->getTitle());
		$this->assertSame(
			[
				'#1 select(*)',
				'#1 from(openregister_conversations, NULL)',
				'#1 param :p1 = 5, ' . IQueryBuilder::PARAM_INT,
				'#1 expr.eq(id, :p1, NULL)',
				'#1 where(eq(id, :p1, NULL))',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
	}//end testFindLooksUpByIdWithoutAnOrganisationFilter()

	public function testFindByUuidLooksUpByUuid(): void {
		$conversation = $this->mapper(rows: [[['id' => 5, 'uuid' => 'c-5']]])->findByUuid(uuid: 'c-5');

		$this->assertSame(5, $conversation->getId());
		$this->assertContains('#1 param :p1 = c-5, ' . IQueryBuilder::PARAM_STR, $this->queryLog);
		$this->assertContains('#1 where(eq(uuid, :p1, NULL))', $this->queryLog);
	}//end testFindByUuidLooksUpByUuid()

	public function testAMissingConversationIsDoesNotExist(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper(rows: [[]])->findByUuid(uuid: 'gone');
	}//end testAMissingConversationIsDoesNotExist()

	public function testInsertStampsUuidAndTimestampsAndAnnouncesTheConversation(): void {
		$conversation = new Conversation();
		$conversation->setUuid('  ');
		$conversation->setTitle('First');

		$saved = $this->mapper()->insert(entity: $conversation);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$saved->getUuid());
		$this->assertInstanceOf(DateTime::class, $saved->getCreated());
		$this->assertInstanceOf(DateTime::class, $saved->getUpdated());
		$this->assertSame('#1 insert(openregister_conversations)', $this->queryLog[0]);
		$this->assertSame('#1 executeStatement()', end($this->queryLog));
		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(ConversationCreatedEvent::class, $this->dispatched[0]);
		$this->assertSame($saved, $this->dispatched[0]->getConversation());
	}//end testInsertStampsUuidAndTimestampsAndAnnouncesTheConversation()

	public function testInsertKeepsAGivenUuidAndTimestamps(): void {
		$created = new DateTime('2026-01-02 03:04:05');
		$updated = new DateTime('2026-01-03 03:04:05');
		$conversation = new Conversation();
		$conversation->setUuid('given');
		$conversation->setCreated($created);
		$conversation->setUpdated($updated);

		$saved = $this->mapper()->insert(entity: $conversation);

		$this->assertSame('given', $saved->getUuid());
		$this->assertSame($created, $saved->getCreated());
		$this->assertSame($updated, $saved->getUpdated());
	}//end testInsertKeepsAGivenUuidAndTimestamps()

	public function testUpdateReadsTheOldRowFirstRestampsAndAnnouncesBoth(): void {
		$stale = new DateTime('2020-01-01 00:00:00');
		$conversation = new Conversation();
		$conversation->setId(5);
		$conversation->resetUpdatedFields();
		$conversation->setTitle('Renamed');
		$conversation->setUpdated($stale);

		$saved = $this->mapper(rows: [[['id' => 5, 'uuid' => 'c-5', 'title' => 'Old']]])->update(entity: $conversation);

		$this->assertSame('#1 select(*)', $this->queryLog[0]);
		$this->assertSame(
			[
				'#2 update(openregister_conversations, NULL)',
				'#2 param :p2 = Renamed, ' . IQueryBuilder::PARAM_STR,
				'#2 set(title, :p2)',
				'#2 param :p3 = DateTime, datetime',
				'#2 set(updated, :p3)',
				'#2 param :p4 = 5, ' . IQueryBuilder::PARAM_INT,
				'#2 expr.eq(id, :p4, NULL)',
				'#2 where(eq(id, :p4, NULL))',
				'#2 executeStatement()',
			],
			$this->statementsOf(tag: '#2')
		);
		$this->assertGreaterThan($stale, $saved->getUpdated());
		$this->assertInstanceOf(ConversationUpdatedEvent::class, $this->dispatched[0]);
		$this->assertSame($saved, $this->dispatched[0]->getNewConversation());
		$this->assertSame('Old', $this->dispatched[0]->getOldConversation()->getTitle());
	}//end testUpdateReadsTheOldRowFirstRestampsAndAnnouncesBoth()

	public function testDeleteRemovesTheRowAndAnnouncesIt(): void {
		$conversation = new Conversation();
		$conversation->setId(5);

		$deleted = $this->mapper()->delete(entity: $conversation);

		$this->assertSame($conversation, $deleted);
		$this->assertSame(
			[
				'#1 delete(openregister_conversations, NULL)',
				'#1 param :p1 = 5, ' . IQueryBuilder::PARAM_INT,
				'#1 expr.eq(id, :p1, NULL)',
				'#1 where(eq(id, :p1, NULL))',
				'#1 executeStatement()',
			],
			$this->queryLog
		);
		$this->assertInstanceOf(ConversationDeletedEvent::class, $this->dispatched[0]);
		$this->assertSame($conversation, $this->dispatched[0]->getConversation());
	}//end testDeleteRemovesTheRowAndAnnouncesIt()

	public function testSoftDeleteStampsDeletedAtAndRestoreClearsIt(): void {
		$row = ['id' => 5, 'uuid' => 'c-5', 'title' => 'T'];

		$deleted = $this->mapper(rows: [[$row], [$row]])->softDelete(id: 5);

		$this->assertInstanceOf(DateTime::class, $deleted->getDeletedAt());
		$this->assertSame(
			['#3 update(openregister_conversations, NULL)', '#3 param :p3 = DateTime, datetime', '#3 set(deleted_at, :p3)'],
			array_slice($this->statementsOf(tag: '#3'), 0, 3)
		);
		$this->assertInstanceOf(ConversationUpdatedEvent::class, $this->dispatched[0]);

		$restored = $this->mapper(rows: [[$row + ['deleted_at' => '2026-01-01 00:00:00']], [$row]])->restore(id: 5);

		$this->assertNull($restored->getDeletedAt());
		$this->assertSame(
			['#3 update(openregister_conversations, NULL)', '#3 param :p3 = NULL, datetime', '#3 set(deleted_at, :p3)'],
			array_slice($this->statementsOf(tag: '#3'), 0, 3)
		);
	}//end testSoftDeleteStampsDeletedAtAndRestoreClearsIt()
}//end class
