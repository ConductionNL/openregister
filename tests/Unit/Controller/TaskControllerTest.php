<?php

/**
 * The task REST surface's HTTP translation — and the contract test for
 * every /api/flow-tasks endpoint (hydra gate-25).
 *
 * The translation is where two review findings lived: a 403 or a 409 for a
 * caller with no relationship to the task confirms the uuid exists (and the
 * 409 named its state), and a caught Throwable echoed the exception text,
 * which for a database failure carries SQL and bound parameters. So the
 * assertions here are about what the WIRE says: 404 for the invisible, 403
 * only for a caller who may read, 409 with the state only then, and a
 * generic 500 whose detail went to the log instead of the response.
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

use OCA\OpenRegister\Controller\TaskController;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskFormRefusedException;
use OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskFormCompletion;
use OCA\OpenRegister\Service\Task\TaskFormResolver;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * HTTP status and body translation for every task route.
 *
 * @covers \OCA\OpenRegister\Controller\TaskController
 * @covers \OCA\OpenRegister\Db\TaskInboxCriteria
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 * @covers \OCA\OpenRegister\Exception\TaskValidationException
 * @covers \OCA\OpenRegister\Exception\TaskAccessDeniedException
 * @covers \OCA\OpenRegister\Exception\TaskConflictException
 * @covers \OCA\OpenRegister\Exception\TaskFormRefusedException
 * @covers \OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException
 */
class TaskControllerTest extends TestCase {

	/**
	 * The lifecycle service, mocked.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $tasks;

	/**
	 * The inbox service, mocked.
	 *
	 * @var TaskInboxService&MockObject
	 */
	private TaskInboxService&MockObject $inbox;

	/**
	 * Read visibility, mocked.
	 *
	 * @var TaskAuthorizationService&MockObject
	 */
	private TaskAuthorizationService&MockObject $authorization;

	/**
	 * The log, mocked.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	private TaskFormResolver&MockObject $forms;

	private TaskFormCompletion&MockObject $completion;

	/**
	 * The controller under test.
	 *
	 * @var TaskController
	 */
	private TaskController $controller;

	/**
	 * A controller with a session for `alice` and every collaborator mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(originalClassName: TaskService::class);
		$this->inbox = $this->createMock(originalClassName: TaskInboxService::class);
		$this->authorization = $this->createMock(originalClassName: TaskAuthorizationService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->forms = $this->createMock(originalClassName: TaskFormResolver::class);
		$this->forms->method('describe')->willReturn(['form' => null, 'requireChecklist' => false]);
		$this->completion = $this->createMock(originalClassName: TaskFormCompletion::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn(['title' => 'x', 'uuid' => 't-1', '_route' => 'r']);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['reviewers']);
		$groups->method('isAdmin')->willReturn(false);

		// The inbox row is whatever the service returns, plus a marker.
		$this->inbox->method('row')->willReturnCallback(
			static fn (Task $task): array => ['uuid' => $task->getUuid(), 'row' => true]
		);

		$this->controller = new TaskController(
			appName: 'openregister',
			request: $request,
			tasks: $this->tasks,
			inbox: $this->inbox,
			authorization: $this->authorization,
			temporal: new TaskTemporalProjection(),
			userSession: $session,
			forms: $this->forms,
			completion: $this->completion,
			logger: $this->logger,
			groupManager: $groups
		);
	}//end setUp()

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
	 * GET /api/flow-tasks: the inbox page, built from the session's identity.
	 *
	 * @return void
	 */
	public function testIndexReturnsTheInboxPageForTheSession(): void {
		$this->inbox->expects($this->once())->method('inbox')->willReturnCallback(
			function (TaskInboxCriteria $criteria, int $limit, int $offset): array {
				$this->assertSame('alice', $criteria->uid);
				$this->assertSame(['reviewers'], $criteria->groupIds);
				$this->assertFalse($criteria->isAdmin);
				$this->assertSame(TaskInboxCriteria::SORT_DUE, $criteria->sort);
				$this->assertTrue($criteria->sortDescending);
				$this->assertSame(10, $limit);

				return ['results' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
			}
		);

		$response = $this->controller->index(sort: '-dueAt', limit: 10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $response->getData()['total']);
	}//end testIndexReturnsTheInboxPageForTheSession()

	/**
	 * GET /api/flow-tasks/{uuid}: 404 for the invisible, 200 for the visible.
	 *
	 * @return void
	 */
	public function testShowIs404ForAnInvisibleTaskAnd200ForAVisibleOne(): void {
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->method('mayRead')->willReturnOnConsecutiveCalls(false, true);

		$hidden = $this->controller->show(uuid: 't-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $hidden->getStatus());
		$this->assertSame(['error' => 'No such task'], $hidden->getData());

		$shown = $this->controller->show(uuid: 't-1');
		$this->assertSame(Http::STATUS_OK, $shown->getStatus());
		$this->assertSame('t-1', $shown->getData()['uuid']);
	}//end testShowIs404ForAnInvisibleTaskAnd200ForAVisibleOne()

	/**
	 * GET /api/flow-tasks/{uuid} carries the resolved form and the checklist
	 * rule, so the completion surface needs no second round-trip.
	 *
	 * @return void
	 */
	public function testShowCarriesTheResolvedForm(): void {
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->method('mayRead')->willReturn(true);
		$forms = $this->createMock(originalClassName: TaskFormResolver::class);
		$forms->expects($this->once())->method('describe')->willReturn(
			[
				'form' => ['kind' => 'fields', 'state' => 'ready', 'fields' => [['field' => 'reason', 'required' => true, 'order' => 0, 'renderable' => true, 'reason' => null]]],
				'requireChecklist' => true,
			]
		);
		$controller = new TaskController(
			appName: 'openregister',
			request: $this->createMock(originalClassName: IRequest::class),
			tasks: $this->tasks,
			inbox: $this->inbox,
			authorization: $this->authorization,
			temporal: new TaskTemporalProjection(),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			forms: $forms,
			completion: $this->completion
		);

		$data = $controller->show(uuid: 't-1')->getData();

		$this->assertSame('t-1', $data['uuid']);
		$this->assertSame('fields', $data['form']['kind']);
		$this->assertTrue($data['form']['fields'][0]['required']);
		$this->assertTrue($data['requireChecklist']);
	}//end testShowCarriesTheResolvedForm()

	/**
	 * POST complete passes the `data` object through to the form-aware completion.
	 *
	 * @return void
	 */
	public function testCompletePassesTheFieldValuesThrough(): void {
		$this->completion->expects($this->once())->method('complete')
			->with('t-1', 'rejected', null, 'late', ['reason' => 'late'], 'alice')
			->willReturn($this->task());

		$response = $this->controller->complete(uuid: 't-1', outcome: 'rejected', comment: 'late', data: ['reason' => 'late']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testCompletePassesTheFieldValuesThrough()

	/**
	 * A `data` that is not an object is a 400 before any service is reached.
	 *
	 * @return void
	 */
	public function testANonObjectDataIs400(): void {
		$this->completion->expects($this->never())->method('complete');

		$response = $this->controller->complete(uuid: 't-1', outcome: 'approved', data: 'late');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('"data"', $response->getData()['error']);
	}//end testANonObjectDataIs400()

	/**
	 * A refused form payload is a 400 carrying the fields and the kind,
	 * machine-readably, next to the message.
	 *
	 * @return void
	 */
	public function testAFormRefusalIs400WithFieldsAndKind(): void {
		$this->completion->method('complete')->willThrowException(
			new TaskFormRefusedException(
				message: 'Transition "complete" is missing required input field(s): "reason".',
				kind: TaskFormRefusedException::KIND_MISSING,
				fields: ['reason']
			)
		);

		$response = $this->controller->complete(uuid: 't-1', outcome: 'approved', data: []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['reason'], $response->getData()['fields']);
		$this->assertSame('missing', $response->getData()['kind']);
		$this->assertStringContainsString('reason', $response->getData()['error']);
	}//end testAFormRefusalIs400WithFieldsAndKind()

	/**
	 * A write the subject refused is a 422: not malformed, not completed.
	 *
	 * @return void
	 */
	public function testASubjectWriteRefusalIs422(): void {
		$this->completion->method('complete')->willThrowException(
			new TaskSubjectWriteRefusedException('reason must be one of: late, incomplete')
		);

		$response = $this->controller->complete(uuid: 't-1', outcome: 'approved', data: ['reason' => 'other']);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('reason must be', $response->getData()['error']);
	}//end testASubjectWriteRefusalIs422()

	/**
	 * GET /api/flow-tasks/{uuid}: an absent uuid reads exactly like an
	 * invisible one.
	 *
	 * @return void
	 */
	public function testShowIs404ForAnAbsentTask(): void {
		$this->tasks->method('get')->willThrowException(new DoesNotExistException('nope'));

		$response = $this->controller->show(uuid: 'ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testShowIs404ForAnAbsentTask()

	/**
	 * GET /api/flow-tasks/{uuid}/audit: the trail, visibility-checked.
	 *
	 * @return void
	 */
	public function testAuditReturnsTheTrailForAVisibleTask(): void {
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->method('mayRead')->willReturn(true);
		$this->tasks->expects($this->once())->method('auditTrail')->willReturn([]);

		$response = $this->controller->audit(uuid: 't-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => []], $response->getData());
	}//end testAuditReturnsTheTrailForAVisibleTask()

	/**
	 * POST /api/flow-tasks: 201 with the row; the body is passed without the
	 * route bookkeeping.
	 *
	 * @return void
	 */
	public function testCreateReturns201WithTheRow(): void {
		$this->tasks->expects($this->once())->method('create')->willReturnCallback(
			function (array $data, ?string $actor): Task {
				$this->assertSame(['title' => 'x'], $data);
				$this->assertSame('alice', $actor);

				return $this->task();
			}
		);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertTrue($response->getData()['row']);
	}//end testCreateReturns201WithTheRow()

	/**
	 * A refused value is a 400 naming it.
	 *
	 * @return void
	 */
	public function testValidationIs400(): void {
		$this->tasks->method('create')->willThrowException(new TaskValidationException("Priority 'normaal' is refused."));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('normaal', $response->getData()['error']);
	}//end testValidationIs400()

	/**
	 * A DENIED VERB IS 404 FOR A CALLER WHO MAY NOT READ THE TASK: a
	 * stranger probing complete learns nothing, not even that the uuid exists.
	 *
	 * @return void
	 */
	public function testADeniedVerbIs404WhenTheCallerMayNotReadTheTask(): void {
		$this->completion->method('complete')->willThrowException(new TaskAccessDeniedException('denied'));
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->method('mayRead')->willReturn(false);

		$response = $this->controller->complete(uuid: 't-1', outcome: 'approved');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testADeniedVerbIs404WhenTheCallerMayNotReadTheTask()

	/**
	 * A denied verb is 403 for a caller who MAY read the task (a watcher
	 * trying to act): the denial reason is theirs to see.
	 *
	 * @return void
	 */
	public function testADeniedVerbIs403WhenTheCallerMayReadTheTask(): void {
		$this->completion->method('complete')->willThrowException(new TaskAccessDeniedException("Verb 'complete' denied: only the current assignee may perform it."));
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->method('mayRead')->willReturn(true);

		$response = $this->controller->complete(uuid: 't-1', outcome: 'approved');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('assignee', $response->getData()['error']);
	}//end testADeniedVerbIs403WhenTheCallerMayReadTheTask()

	/**
	 * A conflict is 409 carrying the message (the current state, per spec).
	 *
	 * @return void
	 */
	public function testAConflictIs409(): void {
		$this->tasks->method('claim')->willThrowException(new TaskConflictException("already in terminal state 'completed'"));

		$response = $this->controller->claim(uuid: 't-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStringContainsString('completed', $response->getData()['error']);
	}//end testAConflictIs409()

	/**
	 * AN UNEXPECTED FAILURE IS A GENERIC 500: the exception text (which for
	 * a database failure carries SQL and parameters) goes to the log, never
	 * to the wire.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsAGeneric500ThatIsLogged(): void {
		$this->tasks->method('cancel')->willThrowException(
			new RuntimeException("SQLSTATE[42P01]: relation \"oc_openregister_tasks\" does not exist: UPDATE ... WHERE id = 7")
		);
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('SQLSTATE'),
			$this->arrayHasKey('exception')
		);

		$response = $this->controller->cancel(uuid: 't-1', reason: 'x');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('SQLSTATE', $response->getData()['error']);
		$this->assertStringNotContainsString('UPDATE', $response->getData()['error']);
	}//end testAnUnexpectedFailureIsAGeneric500ThatIsLogged()

	/**
	 * Every lifecycle verb route reaches its service verb and answers 200
	 * with the row: the wire contract of the eleven verb endpoints.
	 *
	 * @return void
	 */
	public function testEveryVerbRouteReachesItsServiceVerb(): void {
		foreach (['offer', 'claim', 'unclaim', 'assign', 'reassign', 'delegate', 'resolve', 'cancel', 'checkChecklistItem'] as $verb) {
			$this->tasks->expects($this->once())->method($verb)->willReturn($this->task());
		}

		// Complete goes through the form-aware completion, which owns the
		// write-then-complete order and delegates the verb to the service.
		$this->completion->expects($this->once())->method('complete')->willReturn($this->task());

		$responses = [
			$this->controller->offer(uuid: 't-1'),
			$this->controller->claim(uuid: 't-1'),
			$this->controller->unclaim(uuid: 't-1'),
			$this->controller->assign(uuid: 't-1', assignee: 'bob'),
			$this->controller->reassign(uuid: 't-1', assignee: 'carol'),
			$this->controller->delegate(uuid: 't-1', delegate: 'dora', mandate: 'Volmacht'),
			$this->controller->resolve(uuid: 't-1', resultText: 'ok'),
			$this->controller->complete(uuid: 't-1', outcome: 'approved'),
			$this->controller->cancel(uuid: 't-1', reason: 'moot'),
			$this->controller->checkItem(uuid: 't-1', itemId: 'c1', checked: 'false'),
		];

		foreach ($responses as $response) {
			$this->assertSame(Http::STATUS_OK, $response->getStatus());
			$this->assertSame('t-1', $response->getData()['uuid']);
		}
	}//end testEveryVerbRouteReachesItsServiceVerb()

	/**
	 * Every inbox filter reaches the criteria: states split on commas,
	 * isTerminal and overdue parse as booleans, priority and object pass
	 * through, and the overdue clock instant is set only when asked.
	 *
	 * @return void
	 */
	public function testIndexPassesEveryFilterIntoTheCriteria(): void {
		$this->inbox->expects($this->once())->method('inbox')->willReturnCallback(
			function (TaskInboxCriteria $criteria): array {
				$this->assertSame(TaskInboxCriteria::SCOPE_POOLED, $criteria->scope);
				$this->assertSame(['active', 'enabled'], $criteria->states);
				$this->assertFalse($criteria->isTerminal);
				$this->assertSame('high', $criteria->priority);
				$this->assertSame('obj-1', $criteria->objectUuid);
				$this->assertNotNull($criteria->overdueAt);
				$this->assertSame(TaskInboxCriteria::SORT_PRIORITY, $criteria->sort);
				$this->assertFalse($criteria->sortDescending);

				return ['results' => [], 'total' => 0, 'limit' => 25, 'offset' => 0];
			}
		);

		$response = $this->controller->index(
			scope: TaskInboxCriteria::SCOPE_POOLED,
			state: 'active, enabled,',
			isTerminal: 'false',
			priority: 'high',
			objectUuid: 'obj-1',
			overdue: 'true',
			sort: 'priority'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testIndexPassesEveryFilterIntoTheCriteria()

	/**
	 * With overdue unset the criteria carry no clock instant.
	 *
	 * @return void
	 */
	public function testIndexWithoutOverdueCarriesNoClock(): void {
		$this->inbox->expects($this->once())->method('inbox')->willReturnCallback(
			function (TaskInboxCriteria $criteria): array {
				$this->assertNull($criteria->overdueAt);
				$this->assertNull($criteria->isTerminal);

				return ['results' => [], 'total' => 0, 'limit' => 25, 'offset' => 0];
			}
		);

		$this->controller->index(overdue: 'false');
	}//end testIndexWithoutOverdueCarriesNoClock()

	/**
	 * The audit route answers 404 for absent AND for invisible tasks.
	 *
	 * @return void
	 */
	public function testAuditIs404ForAbsentAndInvisibleTasks(): void {
		$this->tasks->method('get')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('nope')),
			$this->task()
		);
		$this->authorization->method('mayRead')->willReturn(false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->audit(uuid: 'ghost')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->audit(uuid: 't-1')->getStatus());
	}//end testAuditIs404ForAbsentAndInvisibleTasks()

	/**
	 * checkItem reads its flag as a boolean from whatever the route passed.
	 *
	 * @return void
	 */
	public function testCheckItemParsesItsFlag(): void {
		$seen = [];
		$this->tasks->method('checkChecklistItem')->willReturnCallback(
			function (string $uuid, string $itemId, bool $checked) use (&$seen): Task {
				$seen[] = $checked;

				return $this->task();
			}
		);

		$this->controller->checkItem(uuid: 't-1', itemId: 'c1', checked: 'false');
		$this->controller->checkItem(uuid: 't-1', itemId: 'c1', checked: '1');
		$this->controller->checkItem(uuid: 't-1', itemId: 'c1');

		$this->assertSame([false, true, true], $seen);
	}//end testCheckItemParsesItsFlag()

	/**
	 * A verb refused for an ABSENT task is 404 (DoesNotExist from the service).
	 *
	 * @return void
	 */
	public function testAVerbOnAnAbsentTaskIs404(): void {
		$this->tasks->method('unclaim')->willThrowException(new DoesNotExistException('nope'));

		$response = $this->controller->unclaim(uuid: 'ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAVerbOnAnAbsentTaskIs404()

	/**
	 * A failing group backend scopes: no groups, not admin, and the request
	 * still answers.
	 *
	 * @return void
	 */
	public function testAFailingGroupBackendScopesRatherThanWidens(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('getUserGroupIds')->willThrowException(new RuntimeException('ldap down'));
		$groups->method('isAdmin')->willThrowException(new RuntimeException('ldap down'));
		$this->inbox->expects($this->once())->method('inbox')->willReturnCallback(
			function (TaskInboxCriteria $criteria): array {
				$this->assertSame([], $criteria->groupIds);
				$this->assertFalse($criteria->isAdmin);

				return ['results' => [], 'total' => 0, 'limit' => 25, 'offset' => 0];
			}
		);
		$controller = new TaskController(
			appName: 'openregister',
			request: $this->createMock(originalClassName: IRequest::class),
			tasks: $this->tasks,
			inbox: $this->inbox,
			authorization: $this->authorization,
			temporal: new TaskTemporalProjection(),
			userSession: $session,
			forms: $this->forms,
			completion: $this->completion,
			logger: $this->logger,
			groupManager: $groups
		);

		$this->assertSame(Http::STATUS_OK, $controller->index()->getStatus());
	}//end testAFailingGroupBackendScopesRatherThanWidens()

	/**
	 * Without a session the inbox is 401, not an empty 200.
	 *
	 * @return void
	 */
	public function testIndexWithoutASessionIs401(): void {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$controller = new TaskController(
			appName: 'openregister',
			request: $this->createMock(originalClassName: IRequest::class),
			tasks: $this->tasks,
			inbox: $this->inbox,
			authorization: $this->authorization,
			temporal: new TaskTemporalProjection(),
			userSession: $session,
			forms: $this->forms,
			completion: $this->completion
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
	}//end testIndexWithoutASessionIs401()
}//end class
