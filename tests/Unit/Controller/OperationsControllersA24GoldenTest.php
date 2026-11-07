<?php

/**
 * Golden outcomes of the three operations controllers, pinned before a refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OperationsConsistencyController;
use OCA\OpenRegister\Controller\OperationsConsoleController;
use OCA\OpenRegister\Controller\OperationsMaintenanceController;
use OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException;
use OCA\OpenRegister\Exception\JobRunRefusedException;
use OCA\OpenRegister\Exception\RepairRefusedException;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckServiceInterface;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairServiceInterface;
use OCA\OpenRegister\Service\Operations\JobAlertServiceInterface;
use OCA\OpenRegister\Service\Operations\MaintenanceModeServiceInterface;
use OCA\OpenRegister\Service\Operations\OperationsJobsServiceInterface;
use OCA\OpenRegister\Service\Operations\SupportBundleServiceInterface;
use OCA\OpenRegister\Service\OperationsConsoleServiceInterface;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the admin/CSRF posture, the acting uid and every refusal body.
 */
class OperationsControllersA24GoldenTest extends TestCase {

	/**
	 * console.
	 *
	 * @var OperationsConsoleServiceInterface&MockObject
	 */
	private OperationsConsoleServiceInterface&MockObject $console;

	/**
	 * jobs.
	 *
	 * @var OperationsJobsServiceInterface&MockObject
	 */
	private OperationsJobsServiceInterface&MockObject $jobs;

	/**
	 * alerts.
	 *
	 * @var JobAlertServiceInterface&MockObject
	 */
	private JobAlertServiceInterface&MockObject $alerts;

	/**
	 * check.
	 *
	 * @var ConsistencyCheckServiceInterface&MockObject
	 */
	private ConsistencyCheckServiceInterface&MockObject $check;

	/**
	 * repair.
	 *
	 * @var ConsistencyRepairServiceInterface&MockObject
	 */
	private ConsistencyRepairServiceInterface&MockObject $repair;

	/**
	 * maintenance.
	 *
	 * @var MaintenanceModeServiceInterface&MockObject
	 */
	private MaintenanceModeServiceInterface&MockObject $maintenance;

	/**
	 * bundle.
	 *
	 * @var SupportBundleServiceInterface&MockObject
	 */
	private SupportBundleServiceInterface&MockObject $bundle;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->console = $this->createMock(OperationsConsoleServiceInterface::class);
		$this->jobs = $this->createMock(OperationsJobsServiceInterface::class);
		$this->alerts = $this->createMock(JobAlertServiceInterface::class);
		$this->check = $this->createMock(ConsistencyCheckServiceInterface::class);
		$this->repair = $this->createMock(ConsistencyRepairServiceInterface::class);
		$this->maintenance = $this->createMock(MaintenanceModeServiceInterface::class);
		$this->bundle = $this->createMock(SupportBundleServiceInterface::class);
	}//end setUp()

	/**
	 * A request over these params and verb.
	 *
	 * @param array<string, mixed> $params Params.
	 * @param string               $verb   HTTP verb.
	 *
	 * @return IRequest
	 */
	private function request(array $params, string $verb = 'GET'): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getMethod')->willReturn($verb);
		return $request;
	}//end request()

	/**
	 * A session for a uid, or none.
	 *
	 * @param string|null $uid The uid.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * The console controller.
	 *
	 * @param array<string, mixed> $params Params.
	 * @param string               $verb   Verb.
	 * @param string|null          $uid    Acting uid.
	 *
	 * @return OperationsConsoleController
	 */
	private function consoleController(array $params, string $verb = 'GET', ?string $uid = 'admin'): OperationsConsoleController {
		return new OperationsConsoleController('openregister', $this->request($params, $verb), $this->console, $this->jobs, $this->alerts, $this->session($uid));
	}//end consoleController()

	/**
	 * The posture of each public action: [noCsrf].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function posture(): array {
		return [
			'console' => [
				OperationsConsoleController::class,
				['index' => true, 'jobs' => true, 'ruleRuns' => true, 'runs' => true, 'runNow' => false, 'schedule' => false, 'alerts' => true, 'administerAlerts' => false],
			],
			'consistency' => [
				OperationsConsistencyController::class,
				['consistency' => true, 'repairPlan' => true, 'repair' => false],
			],
			'maintenance' => [
				OperationsMaintenanceController::class,
				['maintenance' => false, 'supportBundle' => true, 'facts' => true],
			],
		];
	}//end posture()

	/**
	 * Posture test.
	 *
	 * @param string               $class    Controller.
	 * @param array<string, bool>  $expected Method => NoCSRFRequired.
	 *
	 * @return void
	 *
	 * @dataProvider posture
	 */
	public function testPublicActionsAndTheirPosture(string $class, array $expected): void {
		$actual = [];
		foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor() === true) {
				continue;
			}

			$this->assertSame([], $method->getAttributes(NoAdminRequired::class), $method->getName());
			$this->assertSame([], $method->getAttributes(PublicPage::class), $method->getName());
			$actual[$method->getName()] = ($method->getAttributes(NoCSRFRequired::class) !== []);
		}

		ksort($expected);
		ksort($actual);
		$this->assertSame($expected, $actual);
	}

	/**
	 * Console reads parse params.
	 *
	 * @return void
	 */
	public function testConsoleReadsParseParams(): void {
		$this->console->expects($this->exactly(2))->method('panes')->willReturnCallback(fn (int $windowHours): array => ['w' => $windowHours]);
		$this->assertSame(['w' => 6], $this->consoleController(['hours' => '6'])->index()->getData());
		$this->assertSame(['w' => OperationsConsoleServiceInterface::DEFAULT_WINDOW_HOURS], $this->consoleController(['hours' => 'x'])->index()->getData());

		$this->console->method('jobs')->willReturnCallback(fn (?string $state = null, int $limit = 50): array => [$state, $limit]);
		$this->assertSame(['failed', 5], $this->consoleController(['state' => 'failed', 'limit' => '5'])->jobs()->getData());
		$this->assertSame([null, 50], $this->consoleController(['state' => ''])->jobs()->getData());

		$this->console->method('ruleRuns')->willReturnCallback(fn (int $windowHours, int $limit): array => [$windowHours, $limit]);
		$this->assertSame([2, 50], $this->consoleController(['hours' => 2])->ruleRuns()->getData());
	}

	/**
	 * Runs parses filters.
	 *
	 * @return void
	 */
	public function testRunsParsesFilters(): void {
		$this->jobs->method('runs')->willReturnCallback(
			fn (?string $jobClass, ?string $outcome, ?int $windowHours, int $limit, int $offset): array => [$jobClass, $outcome, $windowHours, $limit, $offset]
		);
		$this->assertSame(['J', 'failed', 3, 10, 20], $this->consoleController(['job' => 'J', 'outcome' => 'failed', 'hours' => '3', 'limit' => '10', 'offset' => '20'])->runs()->getData());
		$this->assertSame([null, null, null, 50, 0], $this->consoleController(['job' => '', 'hours' => 'abc'])->runs()->getData());
	}

	/**
	 * Run now outcomes.
	 *
	 * @return void
	 */
	public function testRunNowOutcomes(): void {
		$r = $this->consoleController([], 'POST')->runNow();
		$this->assertSame([400, ['error' => 'no-job', 'message' => 'Name the job to start.']], [$r->getStatus(), $r->getData()]);

		$this->jobs->method('runNow')->willReturnCallback(
			function (string $jobClass, string $actor): array {
				if ($jobClass === 'Busy') {
					throw new JobRunRefusedException('already running', 'running', ['runId' => 4]);
				}

				return ['job' => $jobClass, 'actor' => $actor];
			}
		);
		$r = $this->consoleController(['job' => 'J'], 'POST')->runNow();
		$this->assertSame([202, ['job' => 'J', 'actor' => 'admin']], [$r->getStatus(), $r->getData()]);
		$r = $this->consoleController(['job' => 'J'], 'POST', null)->runNow();
		$this->assertSame(['job' => 'J', 'actor' => ''], $r->getData());
		$r = $this->consoleController(['job' => 'Busy'], 'POST')->runNow();
		$this->assertSame(
			[422, ['error' => 'refused', 'reason' => 'running', 'message' => 'already running', 'details' => ['runId' => 4]]],
			[$r->getStatus(), $r->getData()]
		);
	}

	/**
	 * Schedule outcomes.
	 *
	 * @return void
	 */
	public function testScheduleOutcomes(): void {
		$r = $this->consoleController(['job' => ''])->schedule();
		$this->assertSame([400, ['error' => 'no-job', 'message' => 'Name the job whose schedule this is.']], [$r->getStatus(), $r->getData()]);

		$this->jobs->method('schedule')->with('J')->willReturn(['read' => true]);
		$this->jobs->expects($this->never())->method('administerSchedule')->with('never');
		$this->assertSame(['read' => true], $this->consoleController(['job' => 'J'])->schedule()->getData());
	}

	/**
	 * Schedule administered.
	 *
	 * @return void
	 */
	public function testScheduleAdministered(): void {
		$this->jobs->expects($this->exactly(2))->method('administerSchedule')->willReturnCallback(
			fn (string $jobClass, ?bool $enabled, ?int $intervalSeconds, ?int $windowStartHour, ?int $windowEndHour): array => func_get_args()
		);
		$r = $this->consoleController(['job' => 'J', 'enabled' => false, 'intervalSeconds' => '60', 'windowStartHour' => 1, 'windowEndHour' => 'x'], 'PUT')->schedule();
		$this->assertSame([200, ['J', false, 60, 1, null]], [$r->getStatus(), $r->getData()]);
		$r = $this->consoleController(['job' => 'J', 'enabled' => 'true'], 'POST')->schedule();
		$this->assertSame(['J', null, null, null, null], $r->getData());
	}

	/**
	 * Alerts.
	 *
	 * @return void
	 */
	public function testAlerts(): void {
		$this->console->method('jobs')->with(null, 1)->willReturn(['registered' => [['class' => 'A'], ['class' => 'B']]]);
		$this->jobs->method('alerts')->with(['A', 'B'])->willReturn(['alerts' => []]);
		$this->assertSame(['alerts' => []], $this->consoleController([])->alerts()->getData());
		$this->alerts->method('administer')->willReturnCallback(fn (?int $threshold, ?int $periodMinutes): array => [$threshold, $periodMinutes]);
		$this->assertSame([3, null], $this->consoleController(['threshold' => '3', 'periodMinutes' => ''], 'PUT')->administerAlerts()->getData());
	}

	/**
	 * Consistency outcomes.
	 *
	 * @return void
	 */
	public function testConsistencyOutcomes(): void {
		$this->check->method('check')->willReturnOnConsecutiveCalls(['ok' => 1], $this->throwException(new ConsistencyCheckWouldWriteException('wrote', 'probe-x')));
		$c = new OperationsConsistencyController('openregister', $this->request([]), $this->check, $this->repair, $this->session('admin'));
		$this->assertSame(['ok' => 1], $c->consistency()->getData());
		$r = $c->consistency();
		$this->assertSame([500, ['error' => 'would-write', 'probe' => 'probe-x', 'message' => 'wrote']], [$r->getStatus(), $r->getData()]);
		$r = $c->repairPlan();
		$this->assertSame([400, ['error' => 'no-check', 'message' => 'Name the check whose finding this repairs.']], [$r->getStatus(), $r->getData()]);
		$r = $c->repair();
		$this->assertSame(400, $r->getStatus());
	}

	/**
	 * Repair plan and apply.
	 *
	 * @return void
	 */
	public function testRepairPlanAndApply(): void {
		$this->repair->expects($this->never())->method('apply')->with('never', 'never');
		$this->repair->method('plan')->willReturnCallback(function (string $slug): array {
			if ($slug === 'bad') {
				throw new RepairRefusedException('cannot', 'unknown-check');
			}

			return ['plan' => $slug];
		});
		$c = new OperationsConsistencyController('openregister', $this->request(['check' => 'orphans']), $this->check, $this->repair, $this->session('admin'));
		$this->assertSame([200, ['plan' => 'orphans']], [$c->repairPlan()->getStatus(), $c->repairPlan()->getData()]);
		$c = new OperationsConsistencyController('openregister', $this->request(['check' => 'bad']), $this->check, $this->repair, $this->session('admin'));
		$r = $c->repairPlan();
		$this->assertSame([422, ['error' => 'refused', 'reason' => 'unknown-check', 'message' => 'cannot']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Repair apply names the actor.
	 *
	 * @return void
	 */
	public function testRepairApplyNamesTheActor(): void {
		$this->repair->expects($this->never())->method('plan');
		$this->repair->method('apply')->willReturnCallback(function (string $slug, string $actor): array {
			if ($slug === 'bad') {
				throw new RepairRefusedException('cannot', 'nothing-to-do');
			}

			return [$slug, $actor];
		});
		$c = new OperationsConsistencyController('openregister', $this->request(['check' => 'orphans'], 'POST'), $this->check, $this->repair, $this->session('root'));
		$this->assertSame(['orphans', 'root'], $c->repair()->getData());
		$c = new OperationsConsistencyController('openregister', $this->request(['check' => 'orphans'], 'POST'), $this->check, $this->repair, $this->session(null));
		$this->assertSame(['orphans', ''], $c->repair()->getData());
		$c = new OperationsConsistencyController('openregister', $this->request(['check' => 'bad'], 'POST'), $this->check, $this->repair, $this->session('root'));
		$r = $c->repair();
		$this->assertSame([422, ['error' => 'refused', 'reason' => 'nothing-to-do', 'message' => 'cannot']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Maintenance by verb.
	 *
	 * @return void
	 */
	public function testMaintenanceByVerb(): void {
		$this->maintenance->method('state')->willReturn(['on' => false]);
		$this->maintenance->method('leave')->willReturnCallback(fn (string $actor): array => ['left' => $actor]);
		$this->maintenance->method('enter')->willReturnCallback(fn (string $actor, ?string $message = null): array => ['entered' => $actor, 'message' => $message]);
		$this->bundle->method('build')->willReturn(['bundle' => 1]);
		$this->bundle->method('facts')->willReturn(['facts' => 1]);
		$m = fn (string $verb, array $params, ?string $uid) => new OperationsMaintenanceController('openregister', $this->request($params, $verb), $this->maintenance, $this->bundle, $this->session($uid));
		$this->assertSame(['on' => false], $m('GET', [], 'a')->maintenance()->getData());
		$this->assertSame(['left' => 'a'], $m('DELETE', [], 'a')->maintenance()->getData());
		$this->assertSame(['entered' => 'a', 'message' => 'bye'], $m('POST', ['message' => 'bye'], 'a')->maintenance()->getData());
		$this->assertSame(['entered' => '', 'message' => null], $m('PUT', ['message' => ''], null)->maintenance()->getData());
		$this->assertSame(['bundle' => 1], $m('GET', [], 'a')->supportBundle()->getData());
		$this->assertSame(['facts' => 1], $m('GET', [], 'a')->facts()->getData());
	}
}//end class
