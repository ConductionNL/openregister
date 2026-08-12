<?php

/**
 * ObjectRelationCleanupServiceTest
 *
 * Unit tests for the one shared relation cleanup that both the inline
 * (kill-switch) listener path and the deferred ObjectCleanupJob call.
 *
 * The tests assert the six entity services are really invoked, with the
 * deleted object's UUID, and that a failure in one of them does not swallow
 * the others — a silently fail-soft implementation that catches everything
 * and does nothing would pass a "did not throw" test and fails these.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCA\OpenRegister\Service\TaskService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Six independent, fail-soft cleanups keyed by the deleted object's UUID.
 */
class ObjectRelationCleanupServiceTest extends TestCase {

	/**
	 * Note (comment) cleanup double.
	 *
	 * @var NoteService&MockObject
	 */
	private NoteService&MockObject $noteService;

	/**
	 * CalDAV task cleanup double.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $taskService;

	/**
	 * Email-link cleanup double.
	 *
	 * @var EmailService&MockObject
	 */
	private EmailService&MockObject $emailService;

	/**
	 * Calendar-event unlinking double.
	 *
	 * @var CalendarEventService&MockObject
	 */
	private CalendarEventService&MockObject $calendarEventService;

	/**
	 * Contact-link cleanup double.
	 *
	 * @var ContactService&MockObject
	 */
	private ContactService&MockObject $contactService;

	/**
	 * Deck-card-link cleanup double.
	 *
	 * @var DeckCardService&MockObject
	 */
	private DeckCardService&MockObject $deckCardService;

	/**
	 * PSR logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * System under test.
	 *
	 * @var ObjectRelationCleanupService
	 */
	private ObjectRelationCleanupService $service;

	/**
	 * Wire the service with a double for each of the six entity services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->noteService = $this->createMock(originalClassName: NoteService::class);
		$this->taskService = $this->createMock(originalClassName: TaskService::class);
		$this->emailService = $this->createMock(originalClassName: EmailService::class);
		$this->calendarEventService = $this->createMock(originalClassName: CalendarEventService::class);
		$this->contactService = $this->createMock(originalClassName: ContactService::class);
		$this->deckCardService = $this->createMock(originalClassName: DeckCardService::class);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new ObjectRelationCleanupService(
			noteService: $this->noteService,
			taskService: $this->taskService,
			emailService: $this->emailService,
			calendarEventService: $this->calendarEventService,
			contactService: $this->contactService,
			deckCardService: $this->deckCardService,
			logger: $this->logger
		);

	}//end setUp()

	/**
	 * POSITIVE CONTROL — all six cleanups run, each with the object UUID.
	 *
	 * @return void
	 */
	public function testCleanupInvokesAllSixEntityCleanupsWithTheObjectUuid(): void {
		$this->noteService->expects($this->once())->method('deleteNotesForObject')->with('obj-1');
		$this->taskService->expects($this->once())->method('getTasksForObject')->with('obj-1')->willReturn([]);
		$this->emailService->expects($this->once())->method('deleteLinksForObject')->with('obj-1')->willReturn(2);
		$this->calendarEventService->expects($this->once())->method('unlinkEventsForObject')->with('obj-1');
		$this->contactService->expects($this->once())->method('deleteLinksForObject')->with('obj-1');
		$this->deckCardService->expects($this->once())->method('deleteLinksForObject')->with('obj-1')->willReturn(1);

		$this->service->cleanup(objectUuid: 'obj-1');

	}//end testCleanupInvokesAllSixEntityCleanupsWithTheObjectUuid()

	/**
	 * POSITIVE CONTROL — every listed task is deleted on its own calendar.
	 *
	 * @return void
	 */
	public function testEveryListedTaskIsDeletedOnItsOwnCalendar(): void {
		$this->taskService->method('getTasksForObject')->with('obj-1')->willReturn(
			[
				['id' => 'task-1', 'calendarId' => 'cal-a'],
				['id' => 'task-2', 'calendarId' => 'cal-b'],
			]
		);

		$deleted = [];
		$this->taskService->expects($this->exactly(count: 2))->method('deleteTask')
			->willReturnCallback(
				static function (string $calendarId, string $taskUri) use (&$deleted): void {
					$deleted[] = [$calendarId, $taskUri];
				}
			);

		$this->service->cleanup(objectUuid: 'obj-1');

		$this->assertSame(
			expected: [['cal-a', 'task-1'], ['cal-b', 'task-2']],
			actual: $deleted
		);

	}//end testEveryListedTaskIsDeletedOnItsOwnCalendar()

	/**
	 * NEGATIVE CONTROL — no tasks means no task deletion, rest still runs.
	 *
	 * Paired with testEveryListedTaskIsDeletedOnItsOwnCalendar(); the note
	 * cleanup expectation in the same test proves the run was not a no-op.
	 *
	 * @return void
	 */
	public function testNoListedTasksMeansNoTaskIsDeleted(): void {
		$this->taskService->method('getTasksForObject')->willReturn([]);
		$this->taskService->expects($this->never())->method('deleteTask');

		// Positive control in the same test: the run really happened.
		$this->noteService->expects($this->once())->method('deleteNotesForObject')->with('obj-1');

		$this->service->cleanup(objectUuid: 'obj-1');

	}//end testNoListedTasksMeansNoTaskIsDeleted()

	/**
	 * One failing entity cleanup does not block the other five.
	 *
	 * @return void
	 */
	public function testOneFailingCleanupDoesNotBlockTheOthers(): void {
		$this->noteService->method('deleteNotesForObject')
			->willThrowException(new \RuntimeException('notes table gone'));

		$this->taskService->expects($this->once())->method('getTasksForObject')->with('obj-1')->willReturn([]);
		$this->emailService->expects($this->once())->method('deleteLinksForObject')->with('obj-1')->willReturn(0);
		$this->calendarEventService->expects($this->once())->method('unlinkEventsForObject')->with('obj-1');
		$this->contactService->expects($this->once())->method('deleteLinksForObject')->with('obj-1');
		$this->deckCardService->expects($this->once())->method('deleteLinksForObject')->with('obj-1')->willReturn(0);
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->service->cleanup(objectUuid: 'obj-1');

	}//end testOneFailingCleanupDoesNotBlockTheOthers()

	/**
	 * A task that refuses to delete does not strand the remaining tasks.
	 *
	 * @return void
	 */
	public function testFailingTaskDeletionDoesNotStrandTheRemainingTasks(): void {
		$this->taskService->method('getTasksForObject')->willReturn(
			[
				['id' => 'task-1', 'calendarId' => 'cal-a'],
				['id' => 'task-2', 'calendarId' => 'cal-b'],
			]
		);

		$deleted = [];
		$this->taskService->expects($this->exactly(count: 2))->method('deleteTask')
			->willReturnCallback(
				static function (string $calendarId, string $taskUri) use (&$deleted): void {
					if ($taskUri === 'task-1') {
						throw new \RuntimeException('CalDAV said no');
					}

					$deleted[] = [$calendarId, $taskUri];
				}
			);
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->service->cleanup(objectUuid: 'obj-1');

		$this->assertSame(expected: [['cal-b', 'task-2']], actual: $deleted);

	}//end testFailingTaskDeletionDoesNotStrandTheRemainingTasks()

	/**
	 * A failing task LISTING does not cancel the four later cleanups.
	 *
	 * @return void
	 */
	public function testFailingTaskListingDoesNotCancelTheLaterCleanups(): void {
		$this->taskService->method('getTasksForObject')
			->willThrowException(new \RuntimeException('calendar backend down'));
		$this->taskService->expects($this->never())->method('deleteTask');

		$this->emailService->expects($this->once())->method('deleteLinksForObject')->with('obj-1')->willReturn(0);
		$this->calendarEventService->expects($this->once())->method('unlinkEventsForObject')->with('obj-1');
		$this->contactService->expects($this->once())->method('deleteLinksForObject')->with('obj-1');
		$this->deckCardService->expects($this->once())->method('deleteLinksForObject')->with('obj-1')->willReturn(0);

		$this->service->cleanup(objectUuid: 'obj-1');

	}//end testFailingTaskListingDoesNotCancelTheLaterCleanups()

	/**
	 * At-least-once delivery: a repeat run repeats the same six calls.
	 *
	 * The deferred job may be delivered twice; the cleanup must stay a plain
	 * "delete rows matching this UUID" both times rather than short-circuit
	 * on some remembered state.
	 *
	 * @return void
	 */
	public function testRepeatDeliveryRepeatsTheSameCleanupCalls(): void {
		$this->noteService->expects($this->exactly(count: 2))->method('deleteNotesForObject')->with('obj-1');
		$this->taskService->expects($this->exactly(count: 2))->method('getTasksForObject')->with('obj-1')->willReturn([]);
		$this->emailService->expects($this->exactly(count: 2))->method('deleteLinksForObject')->with('obj-1')->willReturn(0);
		$this->calendarEventService->expects($this->exactly(count: 2))->method('unlinkEventsForObject')->with('obj-1');
		$this->contactService->expects($this->exactly(count: 2))->method('deleteLinksForObject')->with('obj-1');
		$this->deckCardService->expects($this->exactly(count: 2))->method('deleteLinksForObject')->with('obj-1')->willReturn(0);

		$this->service->cleanup(objectUuid: 'obj-1');
		$this->service->cleanup(objectUuid: 'obj-1');

	}//end testRepeatDeliveryRepeatsTheSameCleanupCalls()
}//end class
