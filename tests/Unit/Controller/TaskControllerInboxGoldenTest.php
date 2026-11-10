<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\TaskController;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskFormCompletion;
use OCA\OpenRegister\Service\Task\TaskFormResolver;
use OCA\OpenRegister\Service\Task\TaskInboxPage;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCA\OpenRegister\Tests\Unit\Controller\Support\TaskControllerFactory;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the inbox criteria and page the task listing builds from every
 * query parameter at once, and the refusal without a session; the clock
 * instant of `overdue` is only checked to be set.
 */
final class TaskControllerInboxGoldenTest extends TestCase {

	private TaskInboxService&MockObject $inbox;

	private function controller(?string $uid = 'alice'): TaskController {
		$this->inbox = $this->createMock(TaskInboxService::class);
		$session     = $this->createMock(IUserSession::class);
		$user        = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['reviewers']);
		$groups->method('isAdmin')->willReturn(true);

		return TaskControllerFactory::create(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			tasks: $this->createMock(TaskService::class),
			inbox: $this->inbox,
			authorization: $this->createMock(TaskAuthorizationService::class),
			temporal: new TaskTemporalProjection(),
			userSession: $session,
			forms: $this->createMock(TaskFormResolver::class),
			completion: $this->createMock(TaskFormCompletion::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $groups
		);
	}

	public function testEveryParameterReachesTheCriteriaAndThePage(): void {
		$controller = $this->controller();
		$seen       = [];
		$this->inbox->expects($this->once())->method('inbox')->willReturnCallback(
			function (TaskInboxCriteria $criteria, TaskInboxPage $page) use (&$seen): array {
				$seen = [
					'uid' => $criteria->uid, 'groupIds' => $criteria->groupIds, 'isAdmin' => $criteria->isAdmin, 'scope' => $criteria->scope,
					'states' => $criteria->states, 'isTerminal' => $criteria->isTerminal, 'priority' => $criteria->priority, 'kind' => $criteria->kind,
					'overdue' => $criteria->overdueAt !== null, 'dueAfter' => $criteria->dueAfter?->format('c'), 'dueBefore' => $criteria->dueBefore?->format('c'),
					'objectUuid' => $criteria->objectUuid, 'runUuid' => $criteria->runUuid, 'includeBlocked' => $criteria->includeBlocked,
					'sort' => $criteria->sort, 'descending' => $criteria->sortDescending, 'openFirst' => $criteria->openFirst,
					'limit' => $page->limit, 'offset' => $page->offset,
				];

				return ['results' => [], 'total' => 0, 'limit' => $page->limit, 'offset' => $page->offset];
			}
		);

		$response = $controller->index(
			TaskInboxCriteria::SCOPE_POOLED,
			'active,,enabled',
			'true',
			'low',
			'  approval ',
			'obj-1',
			' run-1 ',
			'1',
			'2026-09-01T00:00:00+00:00',
			'2026-09-08T00:00:00+00:00',
			'-priority',
			7,
			14,
			'yes',
			'on'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'uid' => 'alice', 'groupIds' => ['reviewers'], 'isAdmin' => true, 'scope' => TaskInboxCriteria::SCOPE_POOLED,
				'states' => ['active', 'enabled'], 'isTerminal' => true, 'priority' => 'low', 'kind' => 'approval',
				'overdue' => true, 'dueAfter' => '2026-09-01T00:00:00+00:00', 'dueBefore' => '2026-09-08T00:00:00+00:00',
				'objectUuid' => 'obj-1', 'runUuid' => 'run-1', 'includeBlocked' => true,
				'sort' => 'priority', 'descending' => true, 'openFirst' => true,
				'limit' => 7, 'offset' => 14,
			],
			$seen
		);
	}

	public function testBlankKindAndRunAreNoFilter(): void {
		$controller = $this->controller();
		$seen       = [];
		$this->inbox->method('inbox')->willReturnCallback(
			function (TaskInboxCriteria $criteria, TaskInboxPage $page) use (&$seen): array {
				$seen = [$criteria->kind, $criteria->runUuid, $criteria->states, $criteria->sort, $criteria->sortDescending];
				return ['results' => [], 'total' => 0, 'limit' => $page->limit, 'offset' => $page->offset];
			}
		);

		$controller->index(state: ' ', kind: '  ', runUuid: '');

		$this->assertSame([null, null, [], TaskInboxCriteria::SORT_DUE, false], $seen);
	}

	public function testWithoutASessionTheListingIsRefused(): void {
		$controller = $this->controller(uid: null);
		$this->inbox->expects($this->never())->method('inbox');

		$response = $controller->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'No session'], $response->getData());
	}
}
