<?php

/**
 * Unit test for TaskLeafGuard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\TaskLeafGuard;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A task leaf route acts only on a task that exists and the caller may read; anything else is one 404.
 *
 * @covers \OCA\OpenRegister\Controller\Support\TaskLeafGuard
 */
class TaskLeafGuardTest extends TestCase {
	/**
	 * The tasks under test or its double.
	 *
	 * @var TaskManagerInterface&MockObject
	 */
	private TaskManagerInterface&MockObject $tasks;

	/**
	 * The authorization under test or its double.
	 *
	 * @var TaskAuthorizationService&MockObject
	 */
	private TaskAuthorizationService&MockObject $authorization;

	/**
	 * The session under test or its double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The guard under test or its double.
	 *
	 * @var TaskLeafGuard
	 */
	private TaskLeafGuard $guard;

	protected function setUp(): void {
		$this->tasks         = $this->createMock(TaskManagerInterface::class);
		$this->authorization = $this->createMock(TaskAuthorizationService::class);
		$this->session       = $this->createMock(IUserSession::class);
		$this->guard         = new TaskLeafGuard(tasks: $this->tasks, authorization: $this->authorization, userSession: $this->session);
	}

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}

	public function testAReadableTaskReachesTheAction(): void {
		$task = $this->createMock(TaskInterface::class);
		$this->signIn('ann');
		$this->tasks->method('get')->with('t-1')->willReturn($task);
		$this->authorization->expects($this->once())->method('mayRead')->with($task, 'ann')->willReturn(true);

		$response = $this->guard->withReadableTask(
			't-1',
			static fn (TaskInterface $given): JSONResponse => new JSONResponse(['same' => $given === $task], 200)
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['same' => true], $response->getData());
	}

	public function testAnUnreadableTaskIsTheSame404AsAMissingOne(): void {
		$task = $this->createMock(TaskInterface::class);
		$this->signIn('ann');
		$this->tasks->method('get')->willReturn($task);
		$this->authorization->method('mayRead')->willReturn(false);

		$response = $this->guard->withReadableTask('t-1', fn (): JSONResponse => $this->fail('action must not run'));

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(TaskLeafGuard::NOT_FOUND, $response->getData());
	}

	public function testALookupFailureIsA404(): void {
		$this->tasks->method('get')->willThrowException(new RuntimeException('no row'));
		$this->authorization->expects($this->never())->method('mayRead');

		$this->assertNull($this->guard->readableTask('t-x'));
		$this->assertSame(404, $this->guard->withReadableTask('t-x', fn (): JSONResponse => $this->fail('no'))->getStatus());
	}

	public function testAnAnonymousCallerIsAskedAboutWithANullUid(): void {
		$task = $this->createMock(TaskInterface::class);
		$this->session->method('getUser')->willReturn(null);
		$this->tasks->method('get')->willReturn($task);
		$this->authorization->expects($this->once())->method('mayRead')->with($task, null)->willReturn(false);

		$this->assertNull($this->guard->readableTask('t-1'));
	}

	public function testNotFoundIsA404WithTheFixedBody(): void {
		$response = TaskLeafGuard::notFound();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}
}
