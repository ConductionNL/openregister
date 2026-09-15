<?php

/**
 * The task-anchored notes leaf, and mostly its GUARD.
 *
 * The storage under this controller is the object leaf's storage, already
 * proven by its own tests: a note is a Nextcloud comment addressed by a
 * bare uuid, and nothing here re-tests writing one. What is NEW, and what
 * these assertions are about, is who may reach it.
 *
 * Two properties carry the security of the leaf and each has a test that
 * fails when it is broken:
 *
 * 1. A caller who may not READ the task gets nothing. Not the notes, not a
 *    403, not a different message — the same 404 body `task#show` answers,
 *    so the leaf cannot be used to confirm that a guessed uuid names a real
 *    task. Asserting the status alone would not catch that: a 404 reading
 *    'Task exists but is forbidden' leaks precisely what the status hides,
 *    so the BODY is asserted too.
 *
 * 2. The refusal happens BEFORE the service is touched. Every denial test
 *    also asserts `expects($this->never())` on NoteService, because a
 *    controller that reads the notes and then throws them away has already
 *    lost: the read is where the cost, the log line and the side effects
 *    are.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\TaskNotesController;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Authorization and HTTP translation for /api/flow-tasks/{uuid}/notes.
 *
 * @covers \OCA\OpenRegister\Controller\TaskNotesController
 */
class TaskNotesControllerTest extends TestCase {

	/**
	 * Resolves a task by uuid, mocked.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $tasks;

	/**
	 * Read visibility, mocked.
	 *
	 * @var TaskAuthorizationService&MockObject
	 */
	private TaskAuthorizationService&MockObject $authorization;

	/**
	 * The note storage, mocked — reached only when the guard allows it.
	 *
	 * @var NoteService&MockObject
	 */
	private NoteService&MockObject $notes;

	/**
	 * Every collaborator mocked, and a session for `alice`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(originalClassName: TaskService::class);
		$this->authorization = $this->createMock(originalClassName: TaskAuthorizationService::class);
		$this->notes = $this->createMock(originalClassName: NoteService::class);
	}//end setUp()

	/**
	 * A controller whose request carries these parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return TaskNotesController The controller under test.
	 */
	private function controller(array $params = []): TaskNotesController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new TaskNotesController(
			appName: 'openregister',
			request: $request,
			tasks: $this->tasks,
			authorization: $this->authorization,
			notes: $this->notes,
			userSession: $session
		);
	}//end controller()

	/**
	 * A task as the service returns it.
	 *
	 * @return Task The task.
	 */
	private function task(): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);

		return $task;
	}//end task()

	/**
	 * The task resolves and the caller may read it.
	 *
	 * @return void
	 */
	private function givenReadable(): void {
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->method('mayRead')->willReturn(true);
	}//end givenReadable()

	/**
	 * The task resolves and the caller may NOT read it.
	 *
	 * @return void
	 */
	private function givenUnreadable(): void {
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->method('mayRead')->willReturn(false);
	}//end givenUnreadable()

	/**
	 * 🔴 THE test: a caller who may not read the task may not read its notes,
	 * and the storage is never asked.
	 *
	 * @return void
	 */
	public function testIndexIs404AndReadsNothingForACallerWhoMayNotReadTheTask(): void {
		$this->givenUnreadable();
		$this->notes->expects($this->never())->method('getNotesForObject');

		$response = $this->controller()->index(uuid: 't-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testIndexIs404AndReadsNothingForACallerWhoMayNotReadTheTask()

	/**
	 * The unreadable task and the absent one are indistinguishable on the
	 * wire: same status, same body. A difference between them is an
	 * existence oracle.
	 *
	 * @return void
	 */
	public function testAnAbsentTaskAnswersExactlyAsAnUnreadableOne(): void {
		$this->notes->expects($this->never())->method('getNotesForObject');

		$this->tasks->method('get')->willThrowException(new DoesNotExistException('gone'));
		$absent = $this->controller()->index(uuid: 't-1');

		$this->tasks = $this->createMock(originalClassName: TaskService::class);
		$this->givenUnreadable();
		$hidden = $this->controller()->index(uuid: 't-1');

		$this->assertSame($hidden->getStatus(), $absent->getStatus());
		$this->assertSame($hidden->getData(), $absent->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $absent->getStatus());
	}//end testAnAbsentTaskAnswersExactlyAsAnUnreadableOne()

	/**
	 * The visibility question is asked about the RESOLVED task and the
	 * SESSION's uid, not about the uuid off the URL.
	 *
	 * @return void
	 */
	public function testVisibilityIsAskedAboutTheResolvedTaskAndTheSessionUid(): void {
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->expects($this->once())->method('mayRead')->willReturnCallback(
			function (Task $task, ?string $uid): bool {
				$this->assertSame('t-1', $task->getUuid());
				$this->assertSame('alice', $uid);

				return true;
			}
		);
		$this->notes->method('getNotesForObject')->willReturn([]);

		$this->controller()->index(uuid: 't-1');
	}//end testVisibilityIsAskedAboutTheResolvedTaskAndTheSessionUid()

	/**
	 * A readable task reads its notes by the TASK's uuid, paged from the
	 * query, and answers the same envelope the object leaf answers.
	 *
	 * @return void
	 */
	public function testIndexReadsTheNotesOfTheTaskUuid(): void {
		$this->givenReadable();
		$this->notes->expects($this->once())->method('getNotesForObject')
			->with('t-1', 5, 10)
			->willReturn([['id' => 1, 'message' => 'hello']]);

		$response = $this->controller(['limit' => '5', 'offset' => '10'])->index(uuid: 't-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame('hello', $response->getData()['results'][0]['message']);
	}//end testIndexReadsTheNotesOfTheTaskUuid()

	/**
	 * A caller who may not read the task may not WRITE a note on it either,
	 * and nothing is stored.
	 *
	 * @return void
	 */
	public function testCreateIs404AndStoresNothingForACallerWhoMayNotReadTheTask(): void {
		$this->givenUnreadable();
		$this->notes->expects($this->never())->method('createNote');

		$response = $this->controller(['message' => 'sneaky'])->create(uuid: 't-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testCreateIs404AndStoresNothingForACallerWhoMayNotReadTheTask()

	/**
	 * A readable task stores the note against the TASK's uuid, 201.
	 *
	 * @return void
	 */
	public function testCreateStoresTheNoteAgainstTheTaskUuid(): void {
		$this->givenReadable();
		$this->notes->expects($this->once())->method('createNote')
			->with('t-1', 'looks good')
			->willReturn(['id' => 7, 'message' => 'looks good']);

		$response = $this->controller(['message' => '  looks good  '])->create(uuid: 't-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(7, $response->getData()['id']);
	}//end testCreateStoresTheNoteAgainstTheTaskUuid()

	/**
	 * A blank message is a 400 and stores nothing: a whitespace-only note
	 * renders as a note the page failed to show.
	 *
	 * @return void
	 */
	public function testABlankMessageIs400AndStoresNothing(): void {
		$this->givenReadable();
		$this->notes->expects($this->never())->method('createNote');

		$response = $this->controller(['message' => '   '])->create(uuid: 't-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Note message is required'], $response->getData());
	}//end testABlankMessageIs400AndStoresNothing()

	/**
	 * A caller who may not read the task may not rewrite one of its notes.
	 *
	 * @return void
	 */
	public function testUpdateIs404AndRewritesNothingForACallerWhoMayNotReadTheTask(): void {
		$this->givenUnreadable();
		$this->notes->expects($this->never())->method('updateNote');

		$response = $this->controller(['message' => 'rewritten'])->update(uuid: 't-1', noteId: '7');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testUpdateIs404AndRewritesNothingForACallerWhoMayNotReadTheTask()

	/**
	 * A readable task rewrites the named note, 200.
	 *
	 * @return void
	 */
	public function testUpdateRewritesTheNamedNote(): void {
		$this->givenReadable();
		$this->notes->expects($this->once())->method('updateNote')
			->with(7, 'rewritten')
			->willReturn(['id' => 7, 'message' => 'rewritten']);

		$response = $this->controller(['message' => 'rewritten'])->update(uuid: 't-1', noteId: '7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('rewritten', $response->getData()['message']);
	}//end testUpdateRewritesTheNamedNote()

	/**
	 * A caller who may not read the task may not DELETE one of its notes.
	 * The one denial where the cost of getting it wrong is not recoverable.
	 *
	 * @return void
	 */
	public function testDestroyIs404AndDeletesNothingForACallerWhoMayNotReadTheTask(): void {
		$this->givenUnreadable();
		$this->notes->expects($this->never())->method('deleteNote');

		$response = $this->controller()->destroy(uuid: 't-1', noteId: '7');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testDestroyIs404AndDeletesNothingForACallerWhoMayNotReadTheTask()

	/**
	 * A readable task deletes the named note, 200.
	 *
	 * @return void
	 */
	public function testDestroyDeletesTheNamedNote(): void {
		$this->givenReadable();
		$this->notes->expects($this->once())->method('deleteNote')->with(7);

		$response = $this->controller()->destroy(uuid: 't-1', noteId: '7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}//end testDestroyDeletesTheNamedNote()
}//end class
