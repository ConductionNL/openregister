<?php

/**
 * Golden outcomes of the task notes leaf, pinned before a refactor.
 *
 * Every verb is driven through the three guard outcomes (absent, invisible,
 * readable) and the note-ownership refusal, and the exact status plus body
 * is asserted, so a restructuring that moves or weakens a check goes red.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\TaskNotesController;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Tests\Unit\Controller\Support\TaskNotesControllerFactory;

/**
 * Pins allowed and refused outcomes of TaskNotesController.
 */
class TaskNotesControllerA24GoldenTest extends TestCase {

	/**
	 * Task resolver.
	 *
	 * @var TaskManagerInterface&MockObject
	 */
	private TaskManagerInterface&MockObject $tasks;

	/**
	 * Visibility decisions.
	 *
	 * @var TaskAuthorizationService&MockObject
	 */
	private TaskAuthorizationService&MockObject $authorization;

	/**
	 * Note storage.
	 *
	 * @var NoteService&MockObject
	 */
	private NoteService&MockObject $notes;

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskManagerInterface::class);
		$this->authorization = $this->createMock(TaskAuthorizationService::class);
		$this->notes = $this->createMock(NoteService::class);
	}//end setUp()

	/**
	 * The controller with these request params and session user.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param string|null          $uid    The session uid, or null for none.
	 *
	 * @return TaskNotesController
	 */
	private function controller(array $params = [], ?string $uid = 'alice'): TaskNotesController {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return TaskNotesControllerFactory::create(
			appName: 'openregister',
			request: $request,
			tasks: $this->tasks,
			authorization: $this->authorization,
			notes: $this->notes,
			userSession: $session
		);
	}//end controller()

	/**
	 * A task whose uuid differs from the path uuid, to pin which one is used.
	 *
	 * @return Task
	 */
	private function task(): Task {
		$task = new Task();
		$task->setUuid('real-uuid');

		return $task;
	}//end task()

	/**
	 * Every verb, as a closure over a controller.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function verbs(): array {
		return [
			'index' => [fn (TaskNotesController $c) => $c->index('t-1')],
			'create' => [fn (TaskNotesController $c) => $c->create('t-1')],
			'update' => [fn (TaskNotesController $c) => $c->update('t-1', '7')],
			'destroy' => [fn (TaskNotesController $c) => $c->destroy('t-1', '7')],
		];
	}//end verbs()

	/**
	 * An absent task (or one the mapper cannot resolve) is 404 with the shared body.
	 *
	 * @param callable $verb The verb.
	 *
	 * @dataProvider verbs
	 *
	 * @return void
	 */
	public function testAbsentTaskIsRefused(callable $verb): void {
		$this->tasks->method('get')->willThrowException(new DoesNotExistException('gone'));
		$this->authorization->expects($this->never())->method('mayRead');
		$this->expectNoNoteTouched();

		$response = $verb($this->controller(params: ['message' => 'hi']));

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testAbsentTaskIsRefused()

	/**
	 * A mapper failure of any kind is answered as absent.
	 *
	 * @param callable $verb The verb.
	 *
	 * @dataProvider verbs
	 *
	 * @return void
	 */
	public function testMapperErrorIsRefused(callable $verb): void {
		$this->tasks->method('get')->willThrowException(new \Error('db down'));
		$this->expectNoNoteTouched();

		$response = $verb($this->controller(params: ['message' => 'hi']));

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testMapperErrorIsRefused()

	/**
	 * An invisible task is the same 404, and the caller's uid is what was asked about.
	 *
	 * @param callable $verb The verb.
	 *
	 * @dataProvider verbs
	 *
	 * @return void
	 */
	public function testInvisibleTaskIsRefused(callable $verb): void {
		$task = $this->task();
		$this->tasks->method('get')->with('t-1')->willReturn($task);
		$this->authorization->expects($this->once())->method('mayRead')->with($task, 'alice')->willReturn(false);
		$this->expectNoNoteTouched();

		$response = $verb($this->controller(params: ['message' => 'hi']));

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testInvisibleTaskIsRefused()

	/**
	 * Without a session the visibility question is asked for a null uid.
	 *
	 * @return void
	 */
	public function testNoSessionAsksForNullUid(): void {
		$task = $this->task();
		$this->tasks->method('get')->willReturn($task);
		$this->authorization->expects($this->once())->method('mayRead')->with($task, null)->willReturn(false);

		$response = $this->controller(params: [], uid: null)->index('t-1');

		$this->assertSame(404, $response->getStatus());
	}//end testNoSessionAsksForNullUid()

	/**
	 * Readable: index lists the task's own notes with the paging params.
	 *
	 * @return void
	 */
	public function testIndexAllowed(): void {
		$this->givenReadable();
		$this->notes->expects($this->once())->method('getNotesForObject')->with('real-uuid', 5, 10)->willReturn([['id' => 1]]);

		$response = $this->controller(params: ['limit' => '5', 'offset' => '10'])->index('t-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [['id' => 1]], 'total' => 1], $response->getData());
	}//end testIndexAllowed()

	/**
	 * Readable: index defaults to 50/0.
	 *
	 * @return void
	 */
	public function testIndexDefaults(): void {
		$this->givenReadable();
		$this->notes->expects($this->once())->method('getNotesForObject')->with('real-uuid', 50, 0)->willReturn([]);

		$response = $this->controller()->index('t-1');

		$this->assertSame(['results' => [], 'total' => 0], $response->getData());
	}//end testIndexDefaults()

	/**
	 * Readable: create writes a trimmed note on the task's uuid.
	 *
	 * @return void
	 */
	public function testCreateAllowed(): void {
		$this->givenReadable();
		$this->notes->expects($this->once())->method('createNote')->with('real-uuid', 'hello')->willReturn(['id' => 3]);

		$response = $this->controller(params: ['message' => '  hello  '])->create('t-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 3], $response->getData());
	}//end testCreateAllowed()

	/**
	 * Readable but blank message: 400.
	 *
	 * @return void
	 */
	public function testCreateBlankMessage(): void {
		$this->givenReadable();
		$this->notes->expects($this->never())->method('createNote');

		$response = $this->controller(params: ['message' => '   '])->create('t-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Note message is required'], $response->getData());
	}//end testCreateBlankMessage()

	/**
	 * Readable: update checks ownership on the PATH uuid, then rewrites.
	 *
	 * @return void
	 */
	public function testUpdateAllowed(): void {
		$this->givenReadable();
		$this->notes->expects($this->once())->method('requireNoteOf')->with('t-1', 7);
		$this->notes->expects($this->once())->method('updateNote')->with(7, 'new')->willReturn(['id' => 7]);

		$response = $this->controller(params: ['message' => 'new'])->update('t-1', '7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 7], $response->getData());
	}//end testUpdateAllowed()

	/**
	 * Update checks the message before ownership.
	 *
	 * @return void
	 */
	public function testUpdateBlankMessageBeforeOwnership(): void {
		$this->givenReadable();
		$this->notes->expects($this->never())->method('requireNoteOf');
		$this->notes->expects($this->never())->method('updateNote');

		$response = $this->controller(params: ['message' => ''])->update('t-1', '7');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Note message is required'], $response->getData());
	}//end testUpdateBlankMessageBeforeOwnership()

	/**
	 * A note of another task is 404 on update and destroy.
	 *
	 * @return void
	 */
	public function testForeignNoteIsRefused(): void {
		$this->givenReadable();
		$this->notes->method('requireNoteOf')->willThrowException(new Exception('not yours'));
		$this->notes->expects($this->never())->method('updateNote');
		$this->notes->expects($this->never())->method('deleteNote');

		$update = $this->controller(params: ['message' => 'x'])->update('t-1', '7');
		$destroy = $this->controller()->destroy('t-1', '7');

		$this->assertSame([404, ['error' => 'No such task']], [$update->getStatus(), $update->getData()]);
		$this->assertSame([404, ['error' => 'No such task']], [$destroy->getStatus(), $destroy->getData()]);
	}//end testForeignNoteIsRefused()

	/**
	 * Readable and owned: destroy deletes.
	 *
	 * @return void
	 */
	public function testDestroyAllowed(): void {
		$this->givenReadable();
		$this->notes->expects($this->once())->method('requireNoteOf')->with('t-1', 7);
		$this->notes->expects($this->once())->method('deleteNote')->with(7);

		$response = $this->controller()->destroy('t-1', '7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}//end testDestroyAllowed()

	/**
	 * The task resolves and is readable for alice.
	 *
	 * @return void
	 */
	private function givenReadable(): void {
		$task = $this->task();
		$this->tasks->method('get')->with('t-1')->willReturn($task);
		$this->authorization->method('mayRead')->with($task, 'alice')->willReturn(true);
	}//end givenReadable()

	/**
	 * The note service is never reached.
	 *
	 * @return void
	 */
	private function expectNoNoteTouched(): void {
		foreach (['getNotesForObject', 'createNote', 'updateNote', 'deleteNote', 'requireNoteOf'] as $method) {
			$this->notes->expects($this->never())->method($method);
		}
	}//end expectNoNoteTouched()
}//end class
