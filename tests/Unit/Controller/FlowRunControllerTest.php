<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowRunController;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Flow\FlowDeadEnd;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlowRunControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Run mapper mock.
	 *
	 * @var FlowRunMapper&MockObject
	 */
	private FlowRunMapper&MockObject $mapper;

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
	 * Organisation service mock.
	 *
	 * @var OrganisationService&MockObject
	 */
	private OrganisationService&MockObject $organisations;

	/**
	 * Flow CRUD surface mock.
	 *
	 * @var \OCA\OpenRegister\Service\Flow\FlowService&MockObject
	 */
	private \OCA\OpenRegister\Service\Flow\FlowService&MockObject $flows;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Controller under test.
	 *
	 * @var FlowRunController
	 */
	private FlowRunController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->runner = $this->createMock(FlowRunService::class);
		$this->resolvers = $this->createMock(FlowLocator::class);
		$this->organisations = $this->createMock(OrganisationService::class);
		$this->flows = $this->createMock(\OCA\OpenRegister\Service\Flow\FlowService::class);

		// A session is required for the history read to return anything: the
		// scoping rule is "runs you triggered, plus runs of flows you own", and
		// an unauthenticated caller owns neither half.
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		// Named arguments deliberately. This call has now silently mis-bound
		// twice as the constructor grew: first an ArgumentCountError when
		// IUserSession arrived, then $flows landing in the $groupManager slot
		// and failing as a TypeError. Positionally, both look like a correct
		// call right up until the type happens not to match — and a nullable
		// parameter would have accepted the wrong value in silence.
		$this->controller = new FlowRunController(
			appName: 'openregister',
			request: $this->request,
			mapper: $this->mapper,
			runner: $this->runner,
			resolvers: $this->resolvers,
			userSession: $this->userSession,
			organisationService: $this->organisations,
			flows: $this->flows
		);
	}//end setUp()

	/**
	 * Make getActiveOrganisation() answer with an organisation of this uuid.
	 *
	 * @param string|null $uuid The organisation uuid, or null for "no active organisation".
	 *
	 * @return void
	 */
	private function activeOrganisation(?string $uuid): void {
		if ($uuid === null) {
			$this->organisations->method('getActiveOrganisation')->willReturn(null);
			return;
		}

		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$this->organisations->method('getActiveOrganisation')->willReturn($organisation);
	}//end activeOrganisation()

	/**
	 * Map a params array onto the request mock's getParam(name, default).
	 *
	 * @param array<string,mixed> $values The params the request should answer.
	 *
	 * @return void
	 */
	private function params(array $values): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $name, $default = null) => $values[$name] ?? $default
		);
	}//end params()

	public function testActiveWithNoResolvableOrganisationReturnsNothing(): void {
		$this->params([]);
		$this->activeOrganisation(null);

		// The mapper must not be consulted at all: an unscoped read here would
		// put every tenant's runs on the caller's dashboard.
		$this->mapper->expects($this->never())->method('findActive');

		$body = $this->controller->active()->getData();

		$this->assertSame([], $body['results']);
		$this->assertSame(0, $body['total']);
	}//end testActiveWithNoResolvableOrganisationReturnsNothing()

	public function testActiveScopesToTheCallersOrganisation(): void {
		$this->params([]);
		$this->activeOrganisation('org-a');

		// No `subject` on the request means NO subject predicate reaches the
		// mapper: the org-wide widget's read is bit-identical to before.
		$this->mapper->expects($this->once())->method('findActive')
			->with('org-a', 10, null)
			->willReturn([]);
		$this->mapper->expects($this->once())->method('countActive')
			->with('org-a', null)
			->willReturn(0);

		$this->controller->active();
	}//end testActiveScopesToTheCallersOrganisation()

	public function testActivePassesTheSubjectToBothTheRowsAndTheTotal(): void {
		$this->params(['subject' => 'case-x']);
		$this->activeOrganisation('org-a');

		// The organisation predicate stays: the subject NARROWS inside it. And
		// the total is counted with the same filter, so a case widget can say
		// "2 running" rather than the tenant-wide number.
		$this->mapper->expects($this->once())->method('findActive')
			->with('org-a', 10, 'case-x')
			->willReturn([]);
		$this->mapper->expects($this->once())->method('countActive')
			->with('org-a', 'case-x')
			->willReturn(2);

		$this->assertSame(2, $this->controller->active()->getData()['total']);
	}//end testActivePassesTheSubjectToBothTheRowsAndTheTotal()

	public function testActiveWithASubjectStillReadsNothingWithoutAnOrganisation(): void {
		$this->params(['subject' => 'case-x']);
		$this->activeOrganisation(null);

		// A subject uuid is guessable. It must not become a way to read runs
		// when the tenant scope cannot be established: no query at all.
		$this->mapper->expects($this->never())->method('findActive');
		$this->mapper->expects($this->never())->method('countActive');

		$body = $this->controller->active()->getData();

		$this->assertSame([], $body['results']);
		$this->assertSame(0, $body['total']);
	}//end testActiveWithASubjectStillReadsNothingWithoutAnOrganisation()

	public function testABlankSubjectOnTheLiveReadIsNoFilter(): void {
		$this->params(['subject' => '   ']);
		$this->activeOrganisation('org-a');

		$this->mapper->expects($this->once())->method('findActive')
			->with('org-a', 10, null)
			->willReturn([]);
		$this->mapper->method('countActive')->willReturn(0);

		$this->controller->active();
	}//end testABlankSubjectOnTheLiveReadIsNoFilter()

	public function testCompletedForSubjectWithoutASubjectIsRefusedNamingTheParameter(): void {
		$this->params([]);
		$this->activeOrganisation('org-a');

		// Refused, not answered widely: there is no org-wide "everything that
		// ever finished" on this path. The history endpoint is that surface,
		// with its own per-caller visibility rule.
		$this->mapper->expects($this->never())->method('findCompletedForSubject');
		$this->mapper->expects($this->never())->method('countCompletedForSubject');

		$response = $this->controller->completedForSubject();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('subject', $response->getData()['error']);
	}//end testCompletedForSubjectWithoutASubjectIsRefusedNamingTheParameter()

	public function testCompletedForSubjectWithABlankSubjectIsRefused(): void {
		$this->params(['subject' => '  ']);
		$this->activeOrganisation('org-a');

		$this->mapper->expects($this->never())->method('findCompletedForSubject');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->completedForSubject()->getStatus());
	}//end testCompletedForSubjectWithABlankSubjectIsRefused()

	public function testCompletedForSubjectWithNoResolvableOrganisationReturnsNothing(): void {
		$this->params(['subject' => 'case-x']);
		$this->activeOrganisation(null);

		$this->mapper->expects($this->never())->method('findCompletedForSubject');
		$this->mapper->expects($this->never())->method('countCompletedForSubject');

		$response = $this->controller->completedForSubject();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $body['results']);
		$this->assertSame(0, $body['total']);
	}//end testCompletedForSubjectWithNoResolvableOrganisationReturnsNothing()

	public function testCompletedForSubjectScopesToTheOrganisationAndTheSubjectWithACappedLimit(): void {
		$this->params(['subject' => 'case-x', 'limit' => 5000]);
		$this->activeOrganisation('org-a');

		$this->mapper->expects($this->once())->method('findCompletedForSubject')
			->with('org-a', 'case-x', 50)
			->willReturn([]);
		$this->mapper->expects($this->once())->method('countCompletedForSubject')
			->with('org-a', 'case-x')
			->willReturn(14);

		$body = $this->controller->completedForSubject()->getData();

		$this->assertSame(50, $body['limit']);
		// The honest total, not the length of the bounded page.
		$this->assertSame(14, $body['total']);
	}//end testCompletedForSubjectScopesToTheOrganisationAndTheSubjectWithACappedLimit()

	public function testBothReadsShareOneRowShapeAndNeverCarryTheMarkingItemsOrLog(): void {
		$this->params(['subject' => 'case-x']);
		$this->activeOrganisation('org-a');

		$live = new FlowRun();
		$live->setUuid('run-live');
		$live->setFlowId('f1');
		$live->setStatus(FlowRun::STATUS_SUSPENDED);
		$live->setMarking(['await-reply' => 1]);
		$live->setItems([['record' => 'the subject\'s own data']]);
		$live->setLog([['step' => 1, 'node' => 'start']]);
		$live->setContext(['secret' => 'x']);
		$live->setCreated(new \DateTime('2026-09-01T10:00:00+00:00'));
		$live->setSubjectUuid('case-x');
		$live->setSubjectRegister('cases');
		$live->setSubjectSchema('case');

		$done = new FlowRun();
		$done->setUuid('run-done');
		$done->setFlowId('f1');
		$done->setStatus(FlowRun::STATUS_FAILED);
		// A finished run has no token anywhere: step must read null, not ''.
		$done->setMarking([]);
		$done->setItems([['record' => 'more subject data']]);
		$done->setLog([['step' => 1], ['step' => 2]]);
		$done->setCreated(new \DateTime('2026-08-30T09:00:00+00:00'));
		$done->setSubjectUuid('case-x');
		$done->setSubjectRegister('cases');
		$done->setSubjectSchema('case');

		$this->mapper->method('findActive')->willReturn([$live]);
		$this->mapper->method('countActive')->willReturn(1);
		$this->mapper->method('findCompletedForSubject')->willReturn([$done]);
		$this->mapper->method('countCompletedForSubject')->willReturn(1);
		$this->resolvers->method('resolveFlow')->with('f1')->willReturn(['id' => 'f1', 'name' => 'Hersteltermijn']);

		$liveRow = $this->controller->active()->getData()['results'][0];
		$doneRow = $this->controller->completedForSubject()->getData()['results'][0];

		// The five widget fields plus the subject block, on both.
		$this->assertSame('run-live', $liveRow['uuid']);
		$this->assertSame('Hersteltermijn', $liveRow['flowName']);
		$this->assertSame('await-reply', $liveRow['step']);
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $liveRow['status']);
		$this->assertSame('2026-09-01T10:00:00+00:00', $liveRow['created']);
		$this->assertSame(['uuid' => 'case-x', 'register' => 'cases', 'schema' => 'case'], $liveRow['subject']);

		$this->assertSame('run-done', $doneRow['uuid']);
		$this->assertSame('Hersteltermijn', $doneRow['flowName']);
		$this->assertNull($doneRow['step']);
		$this->assertSame(FlowRun::STATUS_FAILED, $doneRow['status']);
		$this->assertSame('2026-08-30T09:00:00+00:00', $doneRow['created']);
		$this->assertSame(['uuid' => 'case-x', 'register' => 'cases', 'schema' => 'case'], $doneRow['subject']);

		// One shape: a widget renders live and finished runs as one list.
		$this->assertSame(array_keys($liveRow), array_keys($doneRow));

		// And never the heavy fields: kilobytes per row a list never renders,
		// and items can hold the subject's own record data.
		foreach (['marking', 'items', 'log', 'context', 'error', 'steps'] as $heavy) {
			$this->assertArrayNotHasKey($heavy, $liveRow, "live row leaks '$heavy'");
			$this->assertArrayNotHasKey($heavy, $doneRow, "completed row leaks '$heavy'");
		}
	}//end testBothReadsShareOneRowShapeAndNeverCarryTheMarkingItemsOrLog()

	public function testActiveSummarisesEachRunWithItsFlowNameAndStep(): void {
		$this->params(['limit' => 5]);
		$this->activeOrganisation('org-a');

		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('f1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setTrigger('object.created');
		$run->setTriggeredBy('alice');
		$run->setMarking(['await-approval' => 1]);
		$run->setSubjectUuid('subj-1');
		$run->setSubjectRegister('hermiq');
		$run->setSubjectSchema('agent');

		$this->mapper->method('findActive')->willReturn([$run]);
		$this->mapper->method('countActive')->willReturn(42);
		$this->resolvers->method('resolveFlow')->with('f1')->willReturn(['id' => 'f1', 'name' => 'Hydra Triage']);

		$body = $this->controller->active()->getData();
		$row = $body['results'][0];

		$this->assertSame('run-1', $row['uuid']);
		$this->assertSame('Hydra Triage', $row['flowName']);
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $row['status']);
		$this->assertSame('await-approval', $row['step']);
		$this->assertSame('alice', $row['startedBy']);
		$this->assertSame('agent', $row['subject']['schema']);
		// The honest total, not the length of the bounded page.
		$this->assertSame(42, $body['total']);
	}//end testActiveSummarisesEachRunWithItsFlowNameAndStep()

	public function testActiveFallsBackToTheFlowIdWhenTheFlowNoLongerResolves(): void {
		$this->params([]);
		$this->activeOrganisation('org-a');

		$run = new FlowRun();
		$run->setUuid('run-2');
		$run->setFlowId('orphan-flow');
		$run->setStatus(FlowRun::STATUS_QUEUED);

		$this->mapper->method('findActive')->willReturn([$run]);
		$this->mapper->method('countActive')->willReturn(1);
		// The owning app is disabled — no resolver claims the id.
		$this->resolvers->method('resolveFlow')->willReturn(null);

		$row = $this->controller->active()->getData()['results'][0];

		$this->assertSame('orphan-flow', $row['flowName']);
		$this->assertNull($row['step']);
	}//end testActiveFallsBackToTheFlowIdWhenTheFlowNoLongerResolves()

	public function testActiveCapsTheRequestedLimit(): void {
		$this->params(['limit' => 5000]);
		$this->activeOrganisation('org-a');

		$this->mapper->expects($this->once())->method('findActive')
			->with('org-a', 50)
			->willReturn([]);
		$this->mapper->method('countActive')->willReturn(0);

		$this->assertSame(50, $this->controller->active()->getData()['limit']);
	}//end testActiveCapsTheRequestedLimit()

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

	/**
	 * REGRESSION GUARD. The history read must never be unscoped.
	 *
	 * This scoping has now been lost twice to merge churn, and an unscoped
	 * `index()` returns every tenant's runs — including each run's log, which
	 * records the subject data the flow touched — to any authenticated caller.
	 * The assertion is on the ARGUMENTS reaching the mapper, because that is
	 * the only place the difference is observable: an unscoped query and a
	 * scoped one that happens to match everything return the same rows.
	 *
	 * @return void
	 */
	public function testTheHistoryReadIsScopedToTheCaller(): void {
		$this->params([]);
		$this->flows->method('idsOwnedByCaller')->willReturn(['owned-flow']);

		$this->mapper->expects($this->once())
			->method('findAllRuns')
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				'alice',
				['owned-flow']
			)
			->willReturn([]);

		$this->controller->index();
	}//end testTheHistoryReadIsScopedToTheCaller()

	/**
	 * Positive control for the guard above: with no session there is no caller
	 * to scope to, so nothing comes back rather than everything.
	 *
	 * @return void
	 */
	public function testTheHistoryReadReturnsNothingWithoutASession(): void {
		$this->params([]);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new FlowRunController(
			appName: 'openregister',
			request: $this->request,
			mapper: $this->mapper,
			runner: $this->runner,
			resolvers: $this->resolvers,
			userSession: $session,
			organisationService: $this->organisations,
			flows: $this->flows
		);

		$this->mapper->expects($this->never())->method('findAllRuns');

		$this->assertSame([], $controller->index()->getData()['results']);
	}//end testTheHistoryReadReturnsNothingWithoutASession()

	public function testResumeSignalsTheSuspendedRunWithTheRequestBody(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);

		$signalled = new FlowRun();
		$signalled->setUuid('run-1');
		$signalled->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');

		// The routing artefacts must not reach the run as signal payload.
		$this->request->method('getParams')->willReturn(
			['uuid' => 'run-1', '_route' => 'openregister.flowRun.resume', 'approved' => true]
		);

		$this->runner->expects($this->once())
			->method('signal')
			->with($run, ['approved' => true])
			->willReturn($signalled);

		$response = $this->controller->resume('run-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('run-1', $response->getData()['uuid']);
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $response->getData()['status']);
	}//end testResumeSignalsTheSuspendedRunWithTheRequestBody()

	public function testResumeIsNotFoundForAnUnknownRun(): void {
		$this->mapper->method('findByUuid')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('no such run'));
		$this->runner->expects($this->never())->method('signal');

		$response = $this->controller->resume('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('No such run', $response->getData()['error']);
	}//end testResumeIsNotFoundForAnUnknownRun()

	public function testResumeIsNotFoundWhenTheCallerMayNotSeeTheFlow(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('someone-elses-flow');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->mapper->method('findByUuid')->willReturn($run);
		$this->flows->method('find')->willThrowException(new \RuntimeException('not visible'));
		$this->runner->expects($this->never())->method('signal');

		$response = $this->controller->resume('run-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertStringContainsString('No such flow', $response->getData()['error']);
	}//end testResumeIsNotFoundWhenTheCallerMayNotSeeTheFlow()

	public function testResumeConflictsWhenTheRunIsNotSuspended(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');
		$run->setStatus('running');

		$this->mapper->method('findByUuid')->willReturn($run);
		$this->request->method('getParams')->willReturn([]);
		$this->runner->method('signal')->willReturn(null);

		$response = $this->controller->resume('run-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStringContainsString('running', $response->getData()['error']);
	}//end testResumeConflictsWhenTheRunIsNotSuspended()

	/**
	 * A step assigned to someone else must not be answerable by the caller.
	 *
	 * THE POINT OF THE WHOLE GUARD. AwaitSignalNode has always recorded an
	 * `assignee` and nothing read it back, so the recorded assignment looked
	 * like authorization and was not: everyone who could run the flow could
	 * approve a step assigned to another person. ADR-098 names this gap.
	 *
	 * @return void
	 */
	public function testResumeRefusesWhenTheStepIsAssignedToSomebodyElse(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setContext([
			'resumeState' => ['approval' => ['askedAt' => '2026-08-27T00:00:00+00:00', 'assignee' => 'bob']],
		]);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');

		// It must refuse BEFORE signalling — a 403 that still woke the run
		// would be a refusal in name only.
		$this->runner->expects($this->never())->method('signal');

		$response = $this->controller->resume('run-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testResumeRefusesWhenTheStepIsAssignedToSomebodyElse()

	/**
	 * The assignee themselves may answer.
	 *
	 * @return void
	 */
	public function testResumeAllowsTheNamedAssignee(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setContext([
			'resumeState' => ['approval' => ['askedAt' => '2026-08-27T00:00:00+00:00', 'assignee' => 'alice']],
		]);

		$signalled = new FlowRun();
		$signalled->setUuid('run-1');
		$signalled->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');
		$this->request->method('getParams')->willReturn(['decision' => 'approved']);
		$this->runner->expects($this->once())->method('signal')->willReturn($signalled);

		$response = $this->controller->resume('run-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testResumeAllowsTheNamedAssignee()

	/**
	 * An unassigned step is unchanged — silence still means anyone.
	 *
	 * Deliberate: a webhook or a child-run signal is not a human decision, and
	 * tightening the unassigned case would break every one of them. Asserted so
	 * that the scope of the guard is visible rather than assumed.
	 *
	 * @return void
	 */
	public function testResumeStillAllowsAnyoneWhenNoAssigneeWasRecorded(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setContext([
			'resumeState' => ['webhook' => ['askedAt' => '2026-08-27T00:00:00+00:00']],
		]);

		$signalled = new FlowRun();
		$signalled->setUuid('run-1');
		$signalled->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');
		$this->request->method('getParams')->willReturn(['ok' => true]);
		$this->runner->expects($this->once())->method('signal')->willReturn($signalled);

		$response = $this->controller->resume('run-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testResumeStillAllowsAnyoneWhenNoAssigneeWasRecorded()

	/**
	 * Build a suspended run carrying the given resume slots.
	 *
	 * @param mixed $resumeState The resumeState value to store on the context.
	 *
	 * @return FlowRun The suspended run.
	 */
	private function suspendedRunWithResumeState(mixed $resumeState): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setContext(['resumeState' => $resumeState]);

		return $run;
	}//end suspendedRunWithResumeState()

	/**
	 * Build the controller with a specific session and group manager.
	 *
	 * @param IUserSession       $session      The session to use.
	 * @param IGroupManager|null $groupManager The group manager, or null.
	 *
	 * @return FlowRunController The controller.
	 */
	private function controllerWith(
		IUserSession $session,
		?IGroupManager $groupManager = null,
	): FlowRunController {
		return new FlowRunController(
			appName: 'openregister',
			request: $this->request,
			mapper: $this->mapper,
			runner: $this->runner,
			resolvers: $this->resolvers,
			userSession: $session,
			organisationService: $this->organisations,
			groupManager: $groupManager,
			flows: $this->flows
		);
	}//end controllerWith()

	/**
	 * An assigned step is never answerable anonymously.
	 *
	 * This is the fail-CLOSED half of the guard, and it is the half that is
	 * easy to get backwards: the unassigned case deliberately allows anyone
	 * through, so an implementation that treated "no uid" the same way would
	 * pass every other test in this file while leaving an assigned decision
	 * open to an unauthenticated caller.
	 *
	 * @return void
	 */
	public function testResumeRefusesAnAssignedStepWithoutASession(): void {
		$run = $this->suspendedRunWithResumeState(
			['approval' => ['askedAt' => '2026-08-27T00:00:00+00:00', 'assignee' => 'bob']]
		);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');
		$this->runner->expects($this->never())->method('signal');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$response = $this->controllerWith(session: $session)->resume('run-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('sign in', $response->getData()['error']);
	}//end testResumeRefusesAnAssignedStepWithoutASession()

	/**
	 * An assignee may be a GROUP, and a member of it may answer.
	 *
	 * AwaitSignalNode records a single `assignee` string without saying whether
	 * it names a person or a group, so the guard has to try both. Without this
	 * every group-assigned step would refuse its own intended audience — a
	 * regression that reads as "the guard works" because refusing is what a
	 * guard does.
	 *
	 * @return void
	 */
	public function testResumeAllowsAMemberOfAnAssignedGroup(): void {
		$run = $this->suspendedRunWithResumeState(
			['approval' => ['askedAt' => '2026-08-27T00:00:00+00:00', 'assignee' => 'reviewers']]
		);

		$signalled = new FlowRun();
		$signalled->setUuid('run-1');
		$signalled->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');
		$this->request->method('getParams')->willReturn(['decision' => 'approved']);
		$this->runner->expects($this->once())->method('signal')->willReturn($signalled);

		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())
			->method('isInGroup')
			->with('alice', 'reviewers')
			->willReturn(true);

		$response = $this->controllerWith(
			session: $this->userSession,
			groupManager: $groups
		)->resume('run-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testResumeAllowsAMemberOfAnAssignedGroup()

	/**
	 * A non-member of an assigned group is still refused.
	 *
	 * The companion to the test above: it is what makes the group lookup a
	 * check rather than a rubber stamp.
	 *
	 * @return void
	 */
	public function testResumeRefusesANonMemberOfAnAssignedGroup(): void {
		$run = $this->suspendedRunWithResumeState(
			['approval' => ['askedAt' => '2026-08-27T00:00:00+00:00', 'assignee' => 'reviewers']]
		);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');
		$this->runner->expects($this->never())->method('signal');

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->with('alice', 'reviewers')->willReturn(false);

		$response = $this->controllerWith(
			session: $this->userSession,
			groupManager: $groups
		)->resume('run-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('someone else', $response->getData()['error']);
	}//end testResumeRefusesANonMemberOfAnAssignedGroup()

	/**
	 * A slot that has not asked yet does not assign anybody.
	 *
	 * A run accumulates a slot per node across its life. Only a slot that
	 * actually asked (`askedAt`) is awaiting an answer, so an assignee sitting
	 * on a not-yet-asked slot must not gate the step that IS asking — that
	 * would refuse the right person on the strength of a future step.
	 *
	 * @return void
	 */
	public function testAnAssigneeOnASlotThatHasNotAskedDoesNotGate(): void {
		$run = $this->suspendedRunWithResumeState(
			[
				'webhook' => ['askedAt' => '2026-08-27T00:00:00+00:00'],
				'later'   => ['assignee' => 'bob'],
			]
		);

		$signalled = new FlowRun();
		$signalled->setUuid('run-1');
		$signalled->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');
		$this->request->method('getParams')->willReturn(['ok' => true]);
		$this->runner->expects($this->once())->method('signal')->willReturn($signalled);

		$response = $this->controller->resume('run-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAnAssigneeOnASlotThatHasNotAskedDoesNotGate()

	/**
	 * A malformed resumeState is read as unassigned, not as a crash.
	 *
	 * The context is stored JSON that older runs wrote under earlier shapes, so
	 * the guard must survive a scalar or a non-array slot where it expects a
	 * map. Refusing here would strand in-flight runs; throwing would 500 them.
	 *
	 * @return void
	 */
	public function testAMalformedResumeStateIsTreatedAsUnassigned(): void {
		$run = $this->suspendedRunWithResumeState('not-a-map');

		$signalled = new FlowRun();
		$signalled->setUuid('run-1');
		$signalled->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');
		$this->request->method('getParams')->willReturn(['ok' => true]);
		$this->runner->expects($this->once())->method('signal')->willReturn($signalled);

		$response = $this->controller->resume('run-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAMalformedResumeStateIsTreatedAsUnassigned()

	/**
	 * A non-array slot inside a well-formed map is skipped, not read.
	 *
	 * @return void
	 */
	public function testANonArraySlotIsSkipped(): void {
		$run = $this->suspendedRunWithResumeState(
			[
				'legacy'   => 'a bare string a previous shape wrote',
				'approval' => ['askedAt' => '2026-08-27T00:00:00+00:00', 'assignee' => 'alice'],
			]
		);

		$signalled = new FlowRun();
		$signalled->setUuid('run-1');
		$signalled->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->flows->method('find')->with('flow-1');
		$this->request->method('getParams')->willReturn(['ok' => true]);
		$this->runner->expects($this->once())->method('signal')->willReturn($signalled);

		$response = $this->controller->resume('run-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testANonArraySlotIsSkipped()

	/**
	 * Set the request up as a test-run POST for one flow.
	 *
	 * @param string $flowId The flow to test-run.
	 *
	 * @return void
	 */
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

		$this->flows->method('find')->willReturn(new \OCA\OpenRegister\Db\Flow());
		$this->resolvers->method('resolveFlow')->willReturn(['nodes' => [], 'edges' => []]);
	}//end aTestRunOf()

	/**
	 * 🔴 A LIFECYCLE REFUSAL ON THE TEST-RUN PATH IS A 409, NOT A 500.
	 *
	 * `FlowRunController::test()` is the OTHER dispatch a person presses, and it
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

}//end class
