<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowRunMigrationController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationService;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The bulk and single move of runs between versions of their flow.
 *
 * These endpoints used to live on `FlowRunController`. What is pinned here is
 * unchanged: the surface FAILS CLOSED without its migration service, and it
 * refuses an unexplained move, because the reason is written onto every run
 * the move touches.
 */
class FlowRunMigrationControllerTest extends TestCase {

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
	 * The REAL guard over mocked collaborators, so the run check is exercised.
	 *
	 * @var FlowRunnableGuard
	 */
	private FlowRunnableGuard $guard;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->flows = $this->createMock(FlowService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$access = $this->createMock(FlowAccess::class);
		$access->method('currentUser')->willReturn($user);
		$access->method('may')->willReturn(true);

		$this->guard = new FlowRunnableGuard(flows: $this->flows, access: $access);
	}//end setUp()

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

	/**
	 * Build the controller, optionally with a migration service.
	 *
	 * @param mixed $migrations The migration service double, or null.
	 *
	 * @return FlowRunMigrationController The controller.
	 */
	private function controller($migrations = null): FlowRunMigrationController {
		return new FlowRunMigrationController(
			appName: 'openregister',
			request: $this->request,
			mapper: $this->mapper,
			userSession: $this->userSession,
			guard: $this->guard,
			migrations: $migrations
		);
	}//end controller()

	/**
	 * Without the collaborator there is no validator, so the endpoint refuses
	 * rather than moving runs unvalidated. That is the silent move the whole
	 * surface exists to prevent.
	 *
	 * @return void
	 */
	public function testMigrateRunsFailsClosedWhenMigrationIsNotAvailable(): void {
		$response = $this->controller()->migrateRuns('flow-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testMigrateRunsFailsClosedWhenMigrationIsNotAvailable()

	/**
	 * An unexplained bulk move is refused, and nothing is migrated. The reason
	 * is written onto every run the move touches, so a move without one leaves
	 * an administrator with runs whose version changed and no record of why.
	 *
	 * @return void
	 */
	public function testMigrateRunsRefusesAMoveWithNoReasonAndMovesNothing(): void {
		$migrations = $this->createMock(FlowRunMigrationService::class);
		$migrations->expects($this->never())->method('migrateRunsOfVersion');

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$this->flows->method('find')->willReturn($flow);
		$this->params(['reason' => '   ']);

		$response = $this->controller($migrations)->migrateRuns('flow-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('reason', $response->getData()['error']);
	}//end testMigrateRunsRefusesAMoveWithNoReasonAndMovesNothing()

	/**
	 * An explained move reaches the service with the caller as the actor, and
	 * the endpoint answers the per-run report rather than a count. A bulk
	 * migration that answered only a count would leave an administrator
	 * believing every run moved, and the ones that did not are exactly the
	 * ones somebody has to go and look at.
	 *
	 * @return void
	 */
	public function testMigrateRunsReportsPerRunAndNamesTheActor(): void {
		$report = [
			'migrated' => 1,
			'skipped' => 1,
			'results' => [['run' => 'r1', 'moved' => true], ['run' => 'r2', 'moved' => false]],
		];

		$migrations = $this->createMock(FlowRunMigrationService::class);
		$migrations->expects($this->once())
			->method('migrateRunsOfVersion')
			->with('flow-1', 3, 4, 'the node was renamed', 'alice', [])
			->willReturn($report);

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$this->flows->method('find')->willReturn($flow);
		$this->params(['reason' => 'the node was renamed', 'sourceVersion' => 3, 'targetVersion' => 4]);

		$response = $this->controller($migrations)->migrateRuns('flow-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(2, $response->getData()['results']);
	}//end testMigrateRunsReportsPerRunAndNamesTheActor()

	/**
	 * 🔴 A PREVIEW AND A WRITE ARE TWO CALLS, AND THE ENDPOINT PICKS ONE.
	 *
	 * `FlowRunMigrationService::migrate()` used to take `bool $dryRun = false`
	 * and this endpoint passed the request parameter straight into it. A flag
	 * dropped anywhere along that chain turns a preview into a migration that
	 * nobody asked for, and the answer still says `dryRun` because the caller
	 * asked for one. The service now has two entry points and the branch is
	 * here, where the request is read.
	 *
	 * @return void
	 */
	public function testADryRunAsksForAPreviewAndNeverForTheWrite(): void {
		$migrations = $this->createMock(FlowRunMigrationService::class);
		$migrations->expects($this->never())->method('migrate');
		$migrations->expects($this->once())
			->method('preview')
			->willReturn([
				'migrated' => false,
				'dryRun' => true,
				'run' => 'r1',
				'from' => 2,
				'to' => 3,
				'marking' => [],
				'unmapped' => [],
				'reason' => '',
			]);

		$run = new \OCA\OpenRegister\Db\FlowRun();
		$run->setUuid('r1');
		$run->setFlowId('flow-1');
		$this->mapper->method('findByUuid')->willReturn($run);

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$this->flows->method('find')->willReturn($flow);
		$this->params(['dryRun' => true, 'targetVersion' => 3]);

		$response = $this->controller($migrations)->migrate('r1');

		$this->assertSame(200, $response->getStatus(), 'a successful preview is not a refusal');
		$this->assertTrue($response->getData()['dryRun']);
	}//end testADryRunAsksForAPreviewAndNeverForTheWrite()

	/**
	 * The control for the test above: without `dryRun` the endpoint asks for
	 * the write and never for the preview. Without it, an endpoint that always
	 * previewed would pass the test above and migrate nothing, ever.
	 *
	 * @return void
	 */
	public function testAPlainMigrateAsksForTheWriteAndNeverForThePreview(): void {
		$migrations = $this->createMock(FlowRunMigrationService::class);
		$migrations->expects($this->never())->method('preview');
		$migrations->expects($this->once())
			->method('migrate')
			->willReturn([
				'migrated' => true,
				'dryRun' => false,
				'run' => 'r1',
				'from' => 2,
				'to' => 3,
				'marking' => [],
				'unmapped' => [],
				'reason' => 'the node was renamed',
			]);

		$run = new \OCA\OpenRegister\Db\FlowRun();
		$run->setUuid('r1');
		$run->setFlowId('flow-1');
		$this->mapper->method('findByUuid')->willReturn($run);

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$this->flows->method('find')->willReturn($flow);
		$this->params(['targetVersion' => 3, 'reason' => 'the node was renamed']);

		$response = $this->controller($migrations)->migrate('r1');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['migrated']);
	}//end testAPlainMigrateAsksForTheWriteAndNeverForThePreview()

}//end class
