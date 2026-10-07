<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowTestRunController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowDeadEnd;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The flow editor's "Test" button: run a flow now and hand back the trace.
 *
 * Moved here with the endpoint. The case that matters is the last four: the
 * test run is gated on `flow.update`, not on merely being allowed to see the
 * flow, because `startAt` and `pins` are authoring affordances (or#3643).
 */
class FlowTestRunControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Run execution service mock.
	 *
	 * @var FlowRunService&MockObject
	 */
	private FlowRunService&MockObject $runner;

	/**
	 * Flow subject resolver mock.
	 *
	 * @var FlowLocator&MockObject
	 */
	private FlowLocator&MockObject $resolvers;

	/**
	 * Flow CRUD surface mock.
	 *
	 * @var FlowService&MockObject
	 */
	private FlowService&MockObject $flows;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Flow action-rights matrix mock (or#3643's guard on `test()`).
	 *
	 * @var FlowAccess&MockObject
	 */
	private FlowAccess&MockObject $access;

	/**
	 * Controller under test, wired with an authorized editor.
	 *
	 * @var FlowTestRunController
	 */
	private FlowTestRunController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->runner = $this->createMock(FlowRunService::class);
		$this->resolvers = $this->createMock(FlowLocator::class);
		$this->flows = $this->createMock(FlowService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		// Default: an authorized editor, so every test below exercises what it
		// was written to exercise rather than tripping the or#3643 guard. The
		// refusal itself is covered by the dedicated tests further down, which
		// override these two methods.
		$this->access = $this->createMock(FlowAccess::class);
		$this->access->method('currentUser')->willReturn($user);
		$this->access->method('may')->willReturn(true);

		$this->controller = $this->controllerWith(access: $this->access);
	}//end setUp()

	/**
	 * Build the controller over a given rights matrix, or none at all.
	 *
	 * @param FlowAccess|null $access The rights matrix, or null for the DI failure mode.
	 *
	 * @return FlowTestRunController The controller.
	 */
	private function controllerWith(?FlowAccess $access): FlowTestRunController {
		return new FlowTestRunController(
			appName: 'openregister',
			request: $this->request,
			runner: $this->runner,
			resolvers: $this->resolvers,
			userSession: $this->userSession,
			guard: new FlowRunnableGuard(flows: $this->flows, access: $access)
		);
	}//end controllerWith()

	/**
	 * Answer the named request parameters, defaulting the rest.
	 *
	 * @param array<string, mixed> $values The parameters.
	 *
	 * @return void
	 */
	private function params(array $values): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($values[$key] ?? $default)
		);
	}//end params()

	public function testTestWithoutAFlowIdIsABadRequest(): void {
		$this->params([]);
		$res = $this->controller->test();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
	}//end testTestWithoutAFlowIdIsABadRequest()

	public function testTestWithAnUnknownFlowIsNotFound(): void {
		$this->params(['flowId' => 'ghost']);
		$this->resolvers->method('resolveFlow')->willReturn(null);

		$res = $this->controller->test();
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
	}//end testTestWithAnUnknownFlowIsNotFound()

	public function testTestRunsSynchronouslyAndReturnsTheResult(): void {
		$this->params(
			[
				'flowId' => 'f1',
				'startAt' => 'middle',
				'pins' => ['first' => [['json' => ['x' => 1]]]],
			]
		);
		$this->resolvers->method('resolveFlow')->with('f1')->willReturn(['id' => 'f1', 'edges' => []]);

		$queued = new FlowRun();
		$queued->setStatus(FlowRun::STATUS_QUEUED);
		$this->runner->method('queue')->willReturn($queued);

		$done = new FlowRun();
		$done->setStatus(FlowRun::STATUS_COMPLETED);
		$done->setLog([['transition' => 'second', 'status' => 'completed']]);

		// The controller must pass the parsed startAt through to execute().
		$this->runner->expects($this->once())->method('execute')
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				'middle'
			)
			->willReturn($done);

		$res = $this->controller->test();
		$body = $res->getData();

		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(FlowRun::STATUS_COMPLETED, $body['status']);
	}//end testTestRunsSynchronouslyAndReturnsTheResult()

	public function testTestPassesPinsOnTheRunContext(): void {
		$pins = ['first' => [['json' => ['pinned' => true]]]];
		$this->params(['flowId' => 'f1', 'pins' => $pins]);
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'f1']);

		// Queue() must receive the pins on the context so the engine can read them.
		$this->runner->expects($this->once())->method('queue')
			->with(
				'f1',
				$this->anything(),
				'test',
				['pins' => $pins]
			)
			->willReturn(new FlowRun());
		$done = new FlowRun();
		$done->setStatus(FlowRun::STATUS_COMPLETED);
		$this->runner->method('execute')->willReturn($done);

		$this->controller->test();
	}//end testTestPassesPinsOnTheRunContext()

	private function aTestRunOf(string $flowId): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($flowId) {
				return match ($key) {
					'flowId' => $flowId,
					'pins' => [],
					default => $default,
				};
			}
		);

		$this->flows->method('find')->willReturn(new Flow());
		$this->resolvers->method('resolveFlow')->willReturn(['nodes' => [], 'edges' => []]);
	}//end aTestRunOf()

	/**
	 * 🔴 A LIFECYCLE REFUSAL ON THE TEST-RUN PATH IS A 409, NOT A 500.
	 *
	 * `FlowTestRunController::test()` is the OTHER dispatch a person presses, and it
	 * let `FlowLifecycleRefused` escape exactly as `FlowController::run()` did:
	 * the editor got an HTML error page — "the server is broken" — for what is
	 * actually "publish this flow first". Removing the catch turns this red with
	 * the exception escaping, which is the defect itself.
	 *
	 * @return void
	 */
	public function testARefusedTestRunIs409WithAReason(): void {
		$this->aTestRunOf('flow-1');
		$this->runner->method('queue')->willThrowException(
			new FlowLifecycleRefused(
				reason: FlowLifecycleRefused::REASON_NO_PUBLISHED_VERSION,
				flowId: 'flow-1',
				state: null
			)
		);

		$response = $this->controller->test();

		$this->assertSame(
			Http::STATUS_CONFLICT,
			$response->getStatus(),
			'a test run refused by the flow lifecycle must be a 409, not a fault'
		);
		$this->assertSame(
			FlowLifecycleRefused::REASON_NO_PUBLISHED_VERSION,
			$response->getData()['reason'],
			'the refusal must name its reason as a field — "publish a version" and '
				. '"create a draft" want opposite buttons from the editor'
		);
	}//end testARefusedTestRunIs409WithAReason()

	/**
	 * A dead end on the test-run path is the same kind of answer: the author
	 * wired a node a token cannot leave, and the engine has already written the
	 * sentence that says which one. Escaping as a 500 threw that sentence away.
	 *
	 * @return void
	 */
	public function testADeadEndTestRunIs409NamingTheDefect(): void {
		$this->aTestRunOf('flow-2');
		$this->runner->method('queue')->willThrowException(
			new FlowDeadEnd(nodeIds: ['step-a'])
		);

		$response = $this->controller->test();

		$this->assertSame(
			Http::STATUS_CONFLICT,
			$response->getStatus(),
			'a dead end is the author\'s document, not a server fault'
		);
		$this->assertSame('dead-end', $response->getData()['reason']);
		$this->assertStringContainsString(
			'step-a',
			(string)$response->getData()['error'],
			'the refusal must still name the node, which is the one fact the author needs'
		);
	}//end testADeadEndTestRunIs409NamingTheDefect()

	/**
	 * 🔴 or#3643 — THE UNGUARDED FLOW-RUN ENDPOINT.
	 *
	 * `test()` used to reach the engine with no check on the CALLER at all —
	 * only {@see FlowService::find()}'s organisation scoping, which passes for
	 * every signed-in member of the flow's organisation, editor or not. This is
	 * the test that must fail against the vulnerable code and pass against the
	 * fix: a caller who holds no `flow.update` right is refused, and — this is
	 * the part a status-code-only assertion would miss — the engine is NEVER
	 * reached, so the run has no side effect at all.
	 *
	 * Mutation check: comment out the `refusalUnlessMayEditFlow()` call (or make
	 * `FlowRunnableGuard::refusalUnlessMayEditFlow()` always return null) in
	 * `FlowTestRunController::test()` and this test reddens — `queue()` gets called
	 * and the status is 200, not 403.
	 *
	 * @return void
	 */
	public function testTestRefusesACallerWithoutTheEditRight(): void {
		$this->aTestRunOf('flow-1');
		$this->access = $this->createMock(FlowAccess::class);
		$this->access->method('currentUser')->willReturn($this->createMock(IUser::class));
		$this->access->method('may')->with($this->anything(), 'flow.update')->willReturn(false);

		$controller = $this->controllerWith(access: $this->access);

		$this->runner->expects($this->never())->method('queue');

		$response = $controller->test();

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$response->getStatus(),
			'a caller without the flow.update right must be refused, not run the flow'
		);
	}//end testTestRefusesACallerWithoutTheEditRight()

	/**
	 * An anonymous caller (no session `FlowAccess::currentUser()` can resolve)
	 * gets 401, not 403 — "sign in" and "you may not do this" are different
	 * answers and {@see FlowAccess} exists precisely so callers do not collapse
	 * them.
	 *
	 * @return void
	 */
	public function testTestRefusesAnAnonymousCallerWithUnauthorized(): void {
		$this->aTestRunOf('flow-1');
		$this->access = $this->createMock(FlowAccess::class);
		$this->access->method('currentUser')->willReturn(null);

		$controller = $this->controllerWith(access: $this->access);

		$this->runner->expects($this->never())->method('queue');

		$response = $controller->test();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testTestRefusesAnAnonymousCallerWithUnauthorized()

	/**
	 * FAIL CLOSED: no `FlowAccess` collaborator at all (the DI failure mode —
	 * same posture as `$flows === null` elsewhere in this controller) must
	 * refuse, not silently allow. An absent collaborator is "no way to decide",
	 * and this controller's rule for that is always refusal.
	 *
	 * @return void
	 */
	public function testTestFailsClosedWithoutTheAccessCollaborator(): void {
		$this->aTestRunOf('flow-1');
		$controller = $this->controllerWith(access: null);

		$this->runner->expects($this->never())->method('queue');

		$response = $controller->test();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testTestFailsClosedWithoutTheAccessCollaborator()

	/**
	 * The edit-right check runs before the flow is even resolved: an
	 * unprivileged caller gets refused for a flow that does not exist, exactly
	 * as for one that does — no oracle for "does this flow id exist" leaks
	 * through which 4xx comes back first.
	 *
	 * @return void
	 */
	public function testTestChecksTheEditRightBeforeResolvingTheFlow(): void {
		$this->params(['flowId' => 'ghost']);
		$this->access = $this->createMock(FlowAccess::class);
		$this->access->method('currentUser')->willReturn($this->createMock(IUser::class));
		$this->access->method('may')->willReturn(false);

		$controller = $this->controllerWith(access: $this->access);

		$this->flows->expects($this->never())->method('find');
		$this->resolvers->expects($this->never())->method('resolveFlow');

		$response = $controller->test();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testTestChecksTheEditRightBeforeResolvingTheFlow()

}//end class
