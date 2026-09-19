<?php

/**
 * Unit tests for OperationsConsoleController — the console's wire contract.
 *
 * Three reads, the shape each one answers, and the posture that keeps them
 * administrators-only. The posture assertion is not decoration: this
 * controller has no in-body admin check by design, so the attribute list IS
 * the authorization, and adding `#[NoAdminRequired]` in a later refactor
 * would open every one of these routes to any signed-in user without a single
 * test going red.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\OperationsConsoleController;
use OCA\OpenRegister\Controller\OperationsConsistencyController;
use OCA\OpenRegister\Controller\OperationsMaintenanceController;
use OCA\OpenRegister\Service\OperationsConsoleService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OperationsConsoleControllerTest extends TestCase {

	/**
	 * The read model the controller delegates to.
	 *
	 * @var OperationsConsoleService
	 */
	private OperationsConsoleService $console;

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

	/**
	 * The run history, run now and the schedule.
	 *
	 * @var \OCA\OpenRegister\Service\Operations\OperationsJobsService
	 */
	private \OCA\OpenRegister\Service\Operations\OperationsJobsService $jobsService;

	/**
	 * Maintenance mode.
	 *
	 * @var \OCA\OpenRegister\Service\Operations\MaintenanceModeService
	 */
	private \OCA\OpenRegister\Service\Operations\MaintenanceModeService $maintenance;

	/**
	 * The support bundle and the instance facts.
	 *
	 * @var \OCA\OpenRegister\Service\Operations\SupportBundleService
	 */
	private \OCA\OpenRegister\Service\Operations\SupportBundleService $bundle;

	/**
	 * The HTTP verb the request reports.
	 *
	 * @var string
	 */
	private string $method = 'GET';

	/**
	 * The uid the session reports, or null for nobody.
	 *
	 * @var string|null
	 */
	private ?string $uid = 'noor';

	protected function setUp(): void {
		parent::setUp();

		$this->params = [];
		$this->console = $this->createMock(OperationsConsoleService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->jobsService = $this->createMock(\OCA\OpenRegister\Service\Operations\OperationsJobsService::class);
		$this->maintenance = $this->createMock(\OCA\OpenRegister\Service\Operations\MaintenanceModeService::class);
		$this->bundle = $this->createMock(\OCA\OpenRegister\Service\Operations\SupportBundleService::class);

		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return ($this->params[$key] ?? $default);
			}
		);
		$this->request->method('getMethod')->willReturnCallback(fn (): string => $this->method);
	}

	private function controller(): OperationsConsoleController {
		$session = $this->createMock(\OCP\IUserSession::class);

		if ($this->uid !== null) {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn($this->uid);
			$session->method('getUser')->willReturn($user);
		}

		return new OperationsConsoleController(
			'openregister',
			$this->request,
			$this->console,
			$this->jobsService,
			$this->createMock(\OCA\OpenRegister\Service\Operations\JobAlertService::class),
			$session
		);
	}

	/**
	 * The maintenance and facts surface, which moved to its own controller.
	 *
	 * @return OperationsMaintenanceController The controller.
	 */
	private function maintenanceController(): OperationsMaintenanceController {
		$session = $this->createMock(\OCP\IUserSession::class);

		if ($this->uid !== null) {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn($this->uid);
			$session->method('getUser')->willReturn($user);
		}

		return new OperationsMaintenanceController(
			'openregister',
			$this->request,
			$this->maintenance,
			$this->bundle,
			$session
		);
	}

	/**
	 * A refusal from the job service reaches the caller as a 422 carrying the
	 * run it collided with, not a bare "no".
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 *
	 * @return void
	 */
	public function testARefusedRunNowAnswersTheRunThatHoldsTheJob(): void {
		$this->method = 'POST';
		$this->params['job'] = 'Acme\\NightlyJob';
		$this->jobsService->method('runNow')->willThrowException(
			new \OCA\OpenRegister\Exception\JobRunRefusedException(
				'This job is already running.',
				'already-running',
				['runId' => 41]
			)
		);

		$response = $this->controller()->runNow();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('already-running', $response->getData()['reason']);
		$this->assertSame(41, $response->getData()['details']['runId']);
	}

	/**
	 * Run now names the administrator asking, so the run row can too.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 *
	 * @return void
	 */
	public function testRunNowHandsTheSessionUidToTheService(): void {
		$this->method = 'POST';
		$this->params['job'] = 'Acme\\NightlyJob';

		$seen = null;
		$this->jobsService->method('runNow')->willReturnCallback(
			function (string $job, string $actor) use (&$seen): array {
				$seen = $actor;

				return ['job' => $job, 'started' => true, 'run' => null];
			}
		);

		$this->assertSame(202, $this->controller()->runNow()->getStatus());
		$this->assertSame('noor', $seen);
	}

	/**
	 * Naming no job is a bad request, never a run of something unnamed.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 *
	 * @return void
	 */
	public function testRunNowWithoutAJobIsRefused(): void {
		$this->method = 'POST';

		$this->assertSame(400, $this->controller()->runNow()->getStatus());
	}

	/**
	 * The verb decides: GET reads the mode, DELETE leaves it, POST enters it.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testMaintenanceModeIsReadEnteredAndLeftByVerb(): void {
		$this->maintenance->method('state')->willReturn(['holds' => false]);
		$this->maintenance->expects($this->once())->method('enter')->willReturn(['holds' => true]);
		$this->maintenance->expects($this->once())->method('leave')->willReturn(['holds' => false]);

		$this->method = 'GET';
		$this->assertFalse($this->maintenanceController()->maintenance()->getData()['holds']);

		$this->method = 'POST';
		$this->params['message'] = 'onderhoud tot 14:00';
		$this->assertTrue($this->maintenanceController()->maintenance()->getData()['holds']);

		$this->method = 'DELETE';
		$this->assertFalse($this->maintenanceController()->maintenance()->getData()['holds']);
	}

	/**
	 * The facts page answers the version and the build a support call opens
	 * with.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 *
	 * @return void
	 */
	public function testTheFactsEndpointAnswersTheVersionAndTheBuild(): void {
		$this->bundle->method('facts')->willReturn(['version' => '2.1.32', 'build' => 'a6ab296']);

		$facts = $this->maintenanceController()->facts()->getData();

		$this->assertSame('2.1.32', $facts['version']);
		$this->assertSame('a6ab296', $facts['build']);
	}

	public function testTheConsoleAnswersItsWindowAndItsPanes(): void {
		$this->console->method('panes')->willReturn(
			[
				'window' => ['hours' => 24, 'since' => '2026-09-15T11:00:00+00:00'],
				'panes' => [
					['id' => 'jobs', 'total' => 5, 'attention' => 1],
					['id' => 'notifications', 'total' => 12, 'attention' => 2],
					['id' => 'rule-runs', 'total' => 3, 'attention' => 0],
				],
			]
		);

		$response = $this->controller()->index();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(24, $data['window']['hours']);
		$this->assertSame(
			['jobs', 'notifications', 'rule-runs'],
			array_column($data['panes'], 'id')
		);
	}

	public function testTheWindowIsReadFromTheRequest(): void {
		$this->params['hours'] = '168';
		$this->console->expects($this->once())->method('panes')->with(168)->willReturn([]);

		$this->controller()->index();
	}

	public function testAMalformedWindowFallsBackRatherThanReadingAsZero(): void {
		$this->params['hours'] = 'last tuesday';
		$this->console->expects($this->once())
			->method('panes')
			->with(OperationsConsoleService::DEFAULT_WINDOW_HOURS)
			->willReturn([]);

		$this->controller()->index();
	}

	public function testTheJobPaneCarriesTheRowsAndTheUnobservedJobs(): void {
		$this->params['state'] = 'failed';
		$this->console->expects($this->once())
			->method('jobs')
			->with('failed', 50)
			->willReturn(
				[
					'results' => [['id' => 1, 'state' => 'failed']],
					'registered' => [['name' => 'ArchivalRetentionTask', 'observed' => false]],
					'unobserved' => [['name' => 'ArchivalRetentionTask', 'observed' => false]],
				]
			);

		$data = $this->controller()->jobs()->getData();

		$this->assertSame('failed', $data['results'][0]['state']);
		$this->assertSame('ArchivalRetentionTask', $data['unobserved'][0]['name']);
	}

	public function testAnEmptyStateFilterIsNoFilterRatherThanAStateCalledNothing(): void {
		$this->params['state'] = '';
		$this->console->expects($this->once())->method('jobs')->with(null, 50)->willReturn([]);

		$this->controller()->jobs();
	}

	public function testTheRuleRunsReadCarriesTheRunsAndTheRulesHoldingAnError(): void {
		$this->console->method('ruleRuns')->willReturn(
			[
				'results' => [['ruleId' => 'rule-a', 'verdict' => 'error']],
				'holdingAnError' => [['ruleId' => 'rule-a', 'lastError' => 'no such property']],
			]
		);

		$data = $this->controller()->ruleRuns()->getData();

		$this->assertSame('rule-a', $data['results'][0]['ruleId']);
		$this->assertSame('no such property', $data['holdingAnError'][0]['lastError']);
	}

	/**
	 * The posture, asserted rather than assumed.
	 *
	 * @param string $method The controller method.
	 *
	 * @return void
	 *
	 * @dataProvider consoleReads
	 */
	public function testTheConsoleIsNotReachableByANonAdministrator(string $method): void {
		$reflected = new ReflectionMethod(OperationsConsoleController::class, $method);

		$this->assertSame(
			[],
			$reflected->getAttributes(NoAdminRequired::class),
			$method.'() carries #[NoAdminRequired], which hands the whole operations console to any signed-in user. '
			.'This controller has no in-body admin check by design; the middleware is the barrier.'
		);
		$this->assertSame([], $reflected->getAttributes(PublicPage::class), $method.'() is reachable anonymously.');
	}

	/**
	 * The same posture on the two controllers the surface was split into.
	 *
	 * 🔴 THE SPLIT MUST NOT HAVE MOVED THE BARRIER. These endpoints have no
	 * in-body admin check by design: the middleware refuses a
	 * non-administrator before the controller is built, and it does that only
	 * while none of them declares `#[NoAdminRequired]`. Moving a method to a
	 * new class is exactly the moment an attribute gets added "to match the
	 * neighbours", so it is asserted here rather than assumed.
	 *
	 * @param string $controller The controller class.
	 * @param string $method     The controller method.
	 *
	 * @return void
	 *
	 * @dataProvider movedOperationsEndpoints
	 */
	public function testTheMovedOperationsEndpointsStayAdministratorOnly(string $controller, string $method): void {
		$reflected = new ReflectionMethod($controller, $method);

		$this->assertSame(
			[],
			$reflected->getAttributes(NoAdminRequired::class),
			$controller.'::'.$method.'() carries #[NoAdminRequired], which hands an operations endpoint to any '
			.'signed-in user. The middleware is the only barrier these have.'
		);
		$this->assertSame(
			[],
			$reflected->getAttributes(PublicPage::class),
			$controller.'::'.$method.'() is reachable anonymously.'
		);
	}//end testTheMovedOperationsEndpointsStayAdministratorOnly()

	/**
	 * Every endpoint that moved out of the console controller.
	 *
	 * @return array<string, array<int, string>> The controller and method pairs.
	 */
	public static function movedOperationsEndpoints(): array {
		return [
			'consistency check' => [OperationsConsistencyController::class, 'consistency'],
			'repair plan' => [OperationsConsistencyController::class, 'repairPlan'],
			'repair' => [OperationsConsistencyController::class, 'repair'],
			'maintenance' => [OperationsMaintenanceController::class, 'maintenance'],
			'support bundle' => [OperationsMaintenanceController::class, 'supportBundle'],
			'facts' => [OperationsMaintenanceController::class, 'facts'],
		];
	}//end movedOperationsEndpoints()

	/**
	 * The console's three reads.
	 *
	 * @return array<string, array<int, string>> The methods.
	 */
	public static function consoleReads(): array {
		return [
			'panes' => ['index'],
			'jobs' => ['jobs'],
			'rule runs' => ['ruleRuns'],
		];
	}
}
