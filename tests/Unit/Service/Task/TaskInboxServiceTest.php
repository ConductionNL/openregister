<?php

/**
 * The inbox's datastore discipline and read-side synthesis.
 *
 * The property under test is design D-9 made mechanical: the page and the
 * total come from the SAME criteria object handed to the datastore — the
 * service never filters rows in PHP and never counts a page to fake a
 * total. Plus the read-side synthesis rules: a titleless task presents a
 * display title WITHOUT its stored title changing, and the temporal
 * projection is attached to rows only, never written.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\AbstractObjectMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pagination, totals, visibility plumbing and display-title synthesis.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskInboxService
 * @covers \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 * @covers \OCA\OpenRegister\Db\TaskInboxCriteria
 * @covers \OCA\OpenRegister\Db\Task
 */
class TaskInboxServiceTest extends TestCase {

	/**
	 * The datastore, mocked.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $tasks;

	/**
	 * The service under test.
	 *
	 * @var TaskInboxService
	 */
	private TaskInboxService $inbox;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->inbox = new TaskInboxService(
			tasks: $this->tasks,
			temporal: new TaskTemporalProjection(),
			logger: new NullLogger(),
			objects: null
		);
	}//end setUp()

	/**
	 * A page of tasks.
	 *
	 * @param int $count How many.
	 *
	 * @return array<int, Task> The tasks.
	 */
	private function tasksPage(int $count): array {
		$page = [];
		for ($i = 0; $i < $count; $i++) {
			$task = new Task();
			$task->setId($i + 1);
			$task->setUuid(sprintf('t-%d', $i + 1));
			$task->setState(Task::STATE_ENABLED);
			$task->setPerformerType(Task::PERFORMER_USER);
			$page[] = $task;
		}

		return $page;
	}//end tasksPage()

	/**
	 * 25 OF 120: the page holds what the datastore returned and the total is
	 * what the datastore counted — over the SAME criteria object, so the two
	 * cannot run different predicates.
	 *
	 * @return void
	 */
	public function testPaginationReportsTheDatastoreTotalOverTheSameCriteria(): void {
		$criteria = new TaskInboxCriteria(uid: 'alice');
		$seenByFind = null;
		$seenByCount = null;

		$this->tasks->expects($this->once())->method('findInbox')->willReturnCallback(
			function (TaskInboxCriteria $c, int $limit, int $offset) use (&$seenByFind): array {
				$seenByFind = $c;
				$this->assertSame(25, $limit);
				$this->assertSame(0, $offset);

				return $this->tasksPage(count: 25);
			}
		);
		$this->tasks->expects($this->once())->method('countInbox')->willReturnCallback(
			static function (TaskInboxCriteria $c) use (&$seenByCount): int {
				$seenByCount = $c;

				return 120;
			}
		);

		$result = $this->inbox->inbox(criteria: $criteria, limit: 25, offset: 0);

		$this->assertCount(25, $result['results']);
		$this->assertSame(120, $result['total']);
		$this->assertSame($criteria, $seenByFind);
		$this->assertSame($criteria, $seenByCount, 'The total must run over the SAME predicates as the page.');
	}//end testPaginationReportsTheDatastoreTotalOverTheSameCriteria()

	/**
	 * No identity, no inbox: an empty uid returns nothing and asks the
	 * datastore nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyUidReturnsNothing(): void {
		$this->tasks->expects($this->never())->method('findInbox');
		$this->tasks->expects($this->never())->method('countInbox');

		$result = $this->inbox->inbox(criteria: new TaskInboxCriteria(uid: ' '));

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testAnEmptyUidReturnsNothing()

	/**
	 * The page size is clamped so a badge query cannot become a table scan.
	 *
	 * @return void
	 */
	public function testTheLimitIsClamped(): void {
		$this->tasks->expects($this->once())->method('findInbox')->willReturnCallback(
			function (TaskInboxCriteria $c, int $limit, int $offset): array {
				$this->assertSame(500, $limit);

				return [];
			}
		);
		$this->tasks->method('countInbox')->willReturn(0);

		$this->inbox->inbox(criteria: new TaskInboxCriteria(uid: 'alice'), limit: 99999);
	}//end testTheLimitIsClamped()

	/**
	 * A TITLELESS TASK STILL DISPLAYS: the row carries a synthesized,
	 * non-empty display title from action and subject — and the STORED title
	 * is still null, because a stored synthesized title goes stale.
	 *
	 * @return void
	 */
	public function testATitlelessTaskGetsADisplayTitleAndStaysTitleless(): void {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setLastAction('claim');
		$task->setObjectUuid('obj-1');

		$subjects = [
			'obj-1' => [
				'uuid' => 'obj-1',
				'register' => 'aanvragen',
				'schema' => 'bouwtekening',
				'title' => 'Bouwtekening Dorpsstraat 12',
			],
		];

		$row = $this->inbox->row(task: $task, subjects: $subjects, now: new DateTime('2026-08-31T12:00:00+00:00'));

		$this->assertSame('Claim: Bouwtekening Dorpsstraat 12', $row['displayTitle']);
		$this->assertNull($row['title'], 'The stored title must STAY null.');
		$this->assertNull($task->getTitle());
		$this->assertSame('bouwtekening', $row['subject']['schema']);
	}//end testATitlelessTaskGetsADisplayTitleAndStaysTitleless()

	/**
	 * Subject context is resolved in ONE batch for the page and attached to
	 * each row by object uuid; a task whose object is unknown gets null.
	 *
	 * @return void
	 */
	public function testSubjectContextIsBatchedForThePage(): void {
		$objects = $this->createMock(originalClassName: AbstractObjectMapper::class);
		$objects->expects($this->once())->method('findMultiple')->willReturnCallback(
			function (array $ids): array {
				$this->assertEqualsCanonicalizing(['obj-1', 'obj-2'], $ids);
				$one = new class () implements \JsonSerializable {
					public function jsonSerialize(): array {
						return ['uuid' => 'obj-1', 'register' => 'zaken', 'schema' => 'zaak', 'name' => 'Zaak 42'];
					}
				};
				$noUuid = new class () implements \JsonSerializable {
					public function jsonSerialize(): array {
						return ['name' => 'orphan'];
					}
				};

				return [$one, $noUuid];
			}
		);
		$inbox = new TaskInboxService(tasks: $this->tasks, temporal: new TaskTemporalProjection(), logger: new NullLogger(), objects: $objects);

		$first = new Task();
		$first->setUuid('t-1');
		$first->setObjectUuid('obj-1');
		$second = new Task();
		$second->setUuid('t-2');
		$second->setObjectUuid('obj-2');
		$third = new Task();
		$third->setUuid('t-3');
		$this->tasks->method('findInbox')->willReturn([$first, $second, $third]);
		$this->tasks->method('countInbox')->willReturn(3);

		$result = $inbox->inbox(criteria: new TaskInboxCriteria(uid: 'alice'));

		$this->assertSame('Zaak 42', $result['results'][0]['subject']['title']);
		$this->assertSame('zaak', $result['results'][0]['subject']['schema']);
		$this->assertNull($result['results'][1]['subject']);
		$this->assertNull($result['results'][2]['subject']);
		$this->assertSame('Task: Zaak 42', $result['results'][0]['displayTitle']);
		$this->assertSame('Task: obj-2', $result['results'][1]['displayTitle']);
		$this->assertSame('Task', $result['results'][2]['displayTitle']);
	}//end testSubjectContextIsBatchedForThePage()

	/**
	 * A failing object store never fails the inbox: context reads null.
	 *
	 * @return void
	 */
	public function testAFailingObjectStoreLeavesContextNull(): void {
		$objects = $this->createMock(originalClassName: AbstractObjectMapper::class);
		$objects->method('findMultiple')->willThrowException(new \RuntimeException('store down'));
		$inbox = new TaskInboxService(tasks: $this->tasks, temporal: new TaskTemporalProjection(), logger: new NullLogger(), objects: $objects);

		$task = new Task();
		$task->setUuid('t-1');
		$task->setObjectUuid('obj-1');
		$this->tasks->method('findInbox')->willReturn([$task]);
		$this->tasks->method('countInbox')->willReturn(1);

		$result = $inbox->inbox(criteria: new TaskInboxCriteria(uid: 'alice'));

		$this->assertSame(1, $result['total']);
		$this->assertNull($result['results'][0]['subject']);
	}//end testAFailingObjectStoreLeavesContextNull()

	/**
	 * Without an object store, no lookup is attempted and the offset is clamped.
	 *
	 * @return void
	 */
	public function testWithoutAnObjectStoreNoLookupIsAttempted(): void {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setObjectUuid('obj-1');
		$this->tasks->method('findInbox')->willReturnCallback(
			function (TaskInboxCriteria $c, int $limit, int $offset) use ($task): array {
				$this->assertSame(0, $offset, 'a negative offset is clamped to zero');

				return [$task];
			}
		);
		$this->tasks->method('countInbox')->willReturn(1);

		$result = $this->inbox->inbox(criteria: new TaskInboxCriteria(uid: 'alice'), limit: 0, offset: -5);

		$this->assertSame(1, $result['limit']);
		$this->assertNull($result['results'][0]['subject']);
	}//end testWithoutAnObjectStoreNoLookupIsAttempted()

	/**
	 * A stored title wins over synthesis, verbatim.
	 *
	 * @return void
	 */
	public function testAStoredTitleWins(): void {
		$task = new Task();
		$task->setTitle('Keur de offerte goed');

		$row = $this->inbox->row(task: $task, subjects: [], now: new DateTime());

		$this->assertSame('Keur de offerte goed', $row['displayTitle']);
	}//end testAStoredTitleWins()

	/**
	 * The row carries the DERIVED projection; the serialised task does not.
	 *
	 * @return void
	 */
	public function testTheProjectionLivesOnTheRowOnly(): void {
		$task = new Task();
		$task->setDueAt(new DateTime('2026-08-01T00:00:00+00:00'));

		$row = $this->inbox->row(task: $task, subjects: [], now: new DateTime('2026-08-31T00:00:00+00:00'));

		$this->assertTrue($row['overdue']);
		$this->assertSame(30, $row['daysOverdue']);
		$this->assertArrayNotHasKey('overdue', $task->jsonSerialize());
	}//end testTheProjectionLivesOnTheRowOnly()
}//end class
