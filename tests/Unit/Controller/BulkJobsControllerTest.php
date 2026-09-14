<?php

/**
 * Unit tests for BulkJobsController — the HTTP surface of a bulk act.
 *
 * Covers the contract a consumer reads: the action catalogue with its
 * guards and the instance ceiling, the caller's own jobs, one job's
 * per-object outcomes, the outcome file, and the refusals. A job the caller
 * does not own answers 404 rather than 403, so the id space never says
 * which jobs exist.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\BulkJobsController;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class BulkJobsControllerTest extends TestCase {

	/**
	 * The lifecycle service the controller delegates to.
	 *
	 * @var BulkJobService
	 */
	private BulkJobService $service;

	/**
	 * Job persistence.
	 *
	 * @var BulkJobMapper
	 */
	private BulkJobMapper $jobMapper;

	/**
	 * The action catalogue.
	 *
	 * @var BulkActionRegistry
	 */
	private BulkActionRegistry $registry;

	/**
	 * The current-user session.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * The group manager, for the administrator branch.
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * The request, whose parameters each test sets.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * The request parameters for the test in hand.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();

		$this->params = [];
		$this->service = $this->createMock(BulkJobService::class);
		$this->jobMapper = $this->createMock(BulkJobMapper::class);
		$this->registry = $this->createMock(BulkActionRegistry::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);

		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return ($this->params[$key] ?? $default);
			}
		);
	}

	private function signIn(?string $uid, bool $admin = false): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);

			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($admin);
	}

	private function controller(): BulkJobsController {
		return new BulkJobsController(
			'openregister',
			$this->request,
			$this->service,
			$this->jobMapper,
			$this->registry,
			$this->userSession,
			$this->groupManager
		);
	}

	private function job(string $owner = 'coordinator'): BulkJob {
		$job = new BulkJob();
		$job->setId(5);
		$job->setUuid('job-uuid');
		$job->setAction('openregister:assign');
		$job->setState(BulkJob::STATE_PREVIEWED);
		$job->setStartedBy($owner);
		$job->setTotal(2);

		return $job;
	}

	private function member(string $uuid, string $outcome, ?string $reason): BulkJobMember {
		$member = new BulkJobMember();
		$member->setId(1);
		$member->setJobId(5);
		$member->setObjectUuid($uuid);
		$member->setOutcome($outcome);
		$member->setReason($reason);

		return $member;
	}

	public function testTheActionCatalogueCarriesTheGuardsAndTheCeiling(): void {
		$this->signIn('coordinator');
		$this->registry->method('describe')->willReturn(
			[
				[
					'id' => 'openregister:assign',
					'label' => 'Hand over to a handler',
					'description' => 'Names one handler.',
					'requiresJustification' => true,
					'guards' => [],
				],
			]
		);
		$this->service->method('getCeiling')->willReturn(1000);

		$response = $this->controller()->actions();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('openregister:assign', $data['results'][0]['id']);
		$this->assertTrue($data['results'][0]['requiresJustification']);
		$this->assertSame(1000, $data['ceiling']);
	}

	public function testTheActionCatalogueRefusesAnAnonymousCaller(): void {
		$this->signIn(null);

		$this->assertSame(401, $this->controller()->actions()->getStatus());
	}

	public function testTheListingReturnsTheCallersOwnJobs(): void {
		$this->signIn('coordinator');
		$this->jobMapper->expects($this->once())
			->method('findByActor')
			->willReturn([$this->job()]);
		$this->jobMapper->expects($this->never())->method('findAllJobs');

		$data = $this->controller()->index()->getData();

		$this->assertCount(1, $data['results']);
		$this->assertSame('job-uuid', $data['results'][0]['uuid']);
	}

	public function testAnAdministratorCanAskForEveryJob(): void {
		$this->signIn('root', true);
		$this->params['all'] = 'true';
		$this->jobMapper->expects($this->once())
			->method('findAllJobs')
			->willReturn([$this->job()]);

		$this->assertCount(1, $this->controller()->index()->getData()['results']);
	}

	public function testAJobBelongingToSomebodyElseIsNotFoundRatherThanForbidden(): void {
		$this->signIn('someone-else');
		$this->jobMapper->method('find')->willReturn($this->job('coordinator'));

		$response = $this->controller()->show(5);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('No such bulk job', $response->getData()['error']);
	}

	public function testAMissingJobIsNotFound(): void {
		$this->signIn('coordinator');
		$this->jobMapper->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->assertSame(404, $this->controller()->show(5)->getStatus());
	}

	public function testTheMembersRouteCarriesTheOutcomeAndItsReason(): void {
		$this->signIn('coordinator');
		$this->jobMapper->method('find')->willReturn($this->job());
		$this->params['outcome'] = 'skipped';
		$this->service->method('members')->willReturn(
			[$this->member('zaak-1', BulkJobMember::OUTCOME_SKIPPED, 'the object already carries these values')]
		);

		$data = $this->controller()->members(5)->getData();

		$this->assertSame(2, $data['total']);
		$this->assertSame('skipped', $data['results'][0]['outcome']);
		$this->assertSame('the object already carries these values', $data['results'][0]['reason']);
	}

	public function testTheOutcomeFileNamesEveryMemberAndItsReason(): void {
		$this->signIn('coordinator');
		$this->jobMapper->method('find')->willReturn($this->job());
		$this->service->method('members')->willReturn(
			[
				$this->member('zaak-1', BulkJobMember::OUTCOME_APPLIED, null),
				$this->member('zaak-2', BulkJobMember::OUTCOME_REFUSED, 'the update rule does not include you'),
			]
		);

		$response = $this->controller()->download(5);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);

		$csv = $response->render();
		$this->assertStringContainsString('object,outcome,reason', $csv);
		$this->assertStringContainsString('"zaak-1","applied"', $csv);
		$this->assertStringContainsString('"the update rule does not include you"', $csv);
	}

	public function testOnlyAPreviewedJobCanBeCommitted(): void {
		$this->signIn('coordinator');
		$job = $this->job();
		$job->setState(BulkJob::STATE_RUNNING);
		$this->jobMapper->method('find')->willReturn($job);
		$this->service->expects($this->never())->method('commit');

		$response = $this->controller()->commit(5);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('running', $response->getData()['state']);
	}

	public function testARefusalCarriesItsReasonCodeAndItsNumbers(): void {
		$this->signIn('coordinator');
		$this->jobMapper->method('find')->willReturn($this->job());
		$this->service->method('commit')->willThrowException(
			new BulkJobRefusedException(
				'The action openregister:assign cannot be committed without a written reason.',
				'justification-required',
				['action' => 'openregister:assign']
			)
		);

		$response = $this->controller()->commit(5);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('justification-required', $response->getData()['reason']);
		$this->assertSame(['action' => 'openregister:assign'], $response->getData()['details']);
	}

	public function testAMalformedCreateAnswersFourHundred(): void {
		$this->signIn('coordinator');
		$this->params['action'] = 'openregister:assign';
		$this->service->method('create')->willThrowException(
			new \InvalidArgumentException('A bulk job needs both a register and a schema.')
		);

		$response = $this->controller()->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('register and a schema', $response->getData()['error']);
	}

	public function testCancelAndRetryReachTheService(): void {
		$this->signIn('coordinator');
		$job = $this->job();
		$this->jobMapper->method('find')->willReturn($job);
		$this->service->expects($this->once())->method('cancel')->willReturn($job);

		$this->assertInstanceOf(JSONResponse::class, $this->controller()->cancel(5));

		$this->service->method('retry')->willReturn($job);
		$this->assertSame(202, $this->controller()->retry(5)->getStatus());
	}
}
