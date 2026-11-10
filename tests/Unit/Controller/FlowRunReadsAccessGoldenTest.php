<?php

/**
 * Golden outcomes of the flow-run history, summary, signal-by-key and test-run endpoints.
 *
 * Pins, on the controllers as they were before these actions were split into
 * small units, who reads what: an administrator reads unscoped, anybody else
 * reads their own runs plus the runs of the flows they own, and nobody without
 * a session reads anything. Also pinned: how the paging, status, flow and
 * since parameters reach the mapper, and that a flow the caller may not run
 * refuses a signal or test run before anything is signalled or queued.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\FlowRunController;
use OCA\OpenRegister\Controller\FlowTestRunController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowCaller;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowRunControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowTestRunControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of FlowRunController's reads and signal, and FlowTestRunController::test().
 */
class FlowRunReadsAccessGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private FlowRunMapper&MockObject $mapper;

	private FlowRunService&MockObject $runner;

	private FlowLocator&MockObject $resolvers;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private FlowService&MockObject $flows;

	private FlowAccess&MockObject $access;

	private FlowCaller&MockObject $ownership;

	private FlowRunSignalService&MockObject $signals;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->runner = $this->createMock(FlowRunService::class);
		$this->resolvers = $this->createMock(FlowLocator::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->flows = $this->createMock(FlowService::class);
		$this->access = $this->createMock(FlowAccess::class);
		$this->access->method('may')->willReturn(true);
		$this->ownership = $this->createMock(FlowCaller::class);
		$this->ownership->method('idsOwnedByCaller')->willReturn(['own-1']);
		$this->signals = $this->createMock(FlowRunSignalService::class);

		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);
		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);
	}

	/**
	 * Sign a caller in.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $isAdmin Whether they administer the instance.
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $isAdmin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->access->method('currentUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
	}

	/**
	 * The run controller.
	 *
	 * @return FlowRunController
	 */
	private function runs(): FlowRunController {
		return FlowRunControllerFactory::create(
			appName: 'openregister',
			request: $this->request,
			mapper: $this->mapper,
			runner: $this->runner,
			resolvers: $this->resolvers,
			userSession: $this->userSession,
			organisationService: $this->createMock(OrganisationService::class),
			guard: new FlowRunnableGuard(flows: $this->flows, access: $this->access),
			groupManager: $this->groupManager,
			flowOwnership: $this->ownership,
			signalService: $this->signals
		);
	}

	/**
	 * The test-run controller.
	 *
	 * @return FlowTestRunController
	 */
	private function testRuns(): FlowTestRunController {
		return FlowTestRunControllerFactory::create(
			request: $this->request,
			runner: $this->runner,
			resolvers: $this->resolvers,
			userSession: $this->userSession,
			guard: new FlowRunnableGuard(flows: $this->flows, access: $this->access)
		);
	}

	/**
	 * A suspended run of flow `flow-1`.
	 *
	 * @return FlowRun
	 */
	private function suspendedRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);

		return $run;
	}

	/**
	 * Key a mocked call's positional arguments by the method's parameter names, defaults filled in.
	 *
	 * @param class-string $class      The mocked class.
	 * @param string       $method     The method.
	 * @param array        $positional The arguments as received.
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $class, string $method, array $positional): array {
		$named = [];
		foreach ((new \ReflectionMethod($class, $method))->getParameters() as $index => $parameter) {
			if (array_key_exists($index, $positional) === true) {
				$named[$parameter->getName()] = $positional[$index];
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true) {
				$named[$parameter->getName()] = $parameter->getDefaultValue();
			}
		}

		return $named;
	}

	// --- index ------------------------------------------------------------

	public function testAnAdministratorReadsTheHistoryUnscopedWithEveryFilter(): void {
		$this->signIn('root', true);
		$this->params = [
			'flowId' => 12,
			'status' => 'done',
			'limit' => '500',
			'offset' => '-3',
			'flowIds' => ' a, b ,a,,',
			'since' => '2026-01-01T12:00:00+02:00',
		];
		$this->ownership->expects($this->never())->method('idsOwnedByCaller');
		$this->mapper->expects($this->once())->method('findAllRuns')->willReturnCallback(
			function (...$positional): array {
				$args = $this->named(FlowRunMapper::class, 'findAllRuns', $positional);
				$this->assertSame('12', $args['listing']->filter->flowId);
				$this->assertSame('done', $args['listing']->filter->status);
				$this->assertSame(200, $args['listing']->limit);
				$this->assertSame(0, $args['listing']->offset);
				$this->assertNull($args['viewer']->requesterUid);
				$this->assertSame([], $args['viewer']->ownedFlowIds);
				$this->assertSame(['a', 'b'], $args['listing']->filter->flowIds);
				$this->assertSame('2026-01-01T10:00:00+00:00', $args['listing']->filter->since->format(DateTime::ATOM));
				return [];
			}
		);

		$response = $this->runs()->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [], 'limit' => 200, 'offset' => 0], $response->getData());
	}

	public function testAnyoneElseReadsTheirOwnRunsAndTheRunsOfFlowsTheyOwn(): void {
		$this->signIn('alice', false);
		$this->params = ['limit' => '0', 'flowIds' => ['x', 7, ' ', 'y']];
		$this->mapper->expects($this->once())->method('findAllRuns')->willReturnCallback(
			function (...$positional): array {
				$args = $this->named(FlowRunMapper::class, 'findAllRuns', $positional);
				$this->assertNull($args['listing']->filter->flowId);
				$this->assertNull($args['listing']->filter->status);
				$this->assertSame(1, $args['listing']->limit);
				$this->assertSame('alice', $args['viewer']->requesterUid);
				$this->assertSame(['own-1'], $args['viewer']->ownedFlowIds);
				$this->assertSame(['x', 'y'], $args['listing']->filter->flowIds);
				$this->assertNull($args['listing']->filter->since);
				return [$this->suspendedRun()];
			}
		);

		$response = $this->runs()->index();

		$this->assertSame(1, count($response->getData()['results']));
		$this->assertSame('run-1', $response->getData()['results'][0]['uuid']);
	}

	public function testWithoutASessionTheHistoryIsEmptyAndTheMapperIsNotAsked(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->mapper->expects($this->never())->method('findAllRuns');

		$this->assertSame(['results' => [], 'limit' => 50, 'offset' => 0], $this->runs()->index()->getData());
	}

	public function testANonStringSinceIsRefused(): void {
		$this->signIn('alice', false);
		$this->params = ['since' => ['2026-01-01']];
		$this->mapper->expects($this->never())->method('findAllRuns');

		$response = $this->runs()->index();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'The since parameter must be an ISO 8601 date or date-time.'], $response->getData());
	}

	public function testANonListFlowIdsIsNoFilter(): void {
		$this->signIn('root', true);
		$this->params = ['flowIds' => 5, 'since' => '  '];
		$this->mapper->expects($this->once())->method('findAllRuns')->willReturnCallback(
			function (...$positional): array {
				$args = $this->named(FlowRunMapper::class, 'findAllRuns', $positional);
				$this->assertSame([], $args['listing']->filter->flowIds);
				$this->assertNull($args['listing']->filter->since);
				return [];
			}
		);

		$this->runs()->index();
	}

	// --- summary ----------------------------------------------------------

	public function testAnAdministratorsSummaryIsUnscopedAndCapsTheFlows(): void {
		$this->signIn('root', true);
		$ids = [];
		for ($i = 0; $i < 250; $i++) {
			$ids[] = 'f' . $i;
		}

		$this->params = ['flowIds' => $ids, 'since' => '2026-02-01T00:00:00Z'];
		$this->ownership->expects($this->never())->method('idsOwnedByCaller');
		$this->mapper->expects($this->once())->method('summarizeByFlow')->willReturnCallback(
			function (...$positional): array {
				$args = $this->named(FlowRunMapper::class, 'summarizeByFlow', $positional);
				$this->assertCount(200, $args['window']->flowIds);
				$this->assertSame('f199', $args['window']->flowIds[199]);
				$this->assertNull($args['viewer']->requesterUid);
				$this->assertSame([], $args['viewer']->ownedFlowIds);
				return ['f1' => ['done' => 2]];
			}
		);

		$response = $this->runs()->summary();

		$this->assertSame('2026-02-01T00:00:00+00:00', $response->getData()['since']);
		$this->assertEquals((object) ['f1' => ['done' => 2]], $response->getData()['results']);
	}

	public function testANonAdministratorsSummaryIsScoped(): void {
		$this->signIn('alice', false);
		$this->params = ['since' => '2026-02-01'];
		$this->mapper->expects($this->once())->method('summarizeByFlow')->willReturnCallback(
			function (...$positional): array {
				$args = $this->named(FlowRunMapper::class, 'summarizeByFlow', $positional);
				$this->assertSame('alice', $args['viewer']->requesterUid);
				$this->assertSame(['own-1'], $args['viewer']->ownedFlowIds);
				return [];
			}
		);

		$this->runs()->summary();
	}

	public function testANonStringSinceIsRefusedOnTheSummary(): void {
		$this->signIn('root', true);
		$this->params = ['since' => 3];
		$this->mapper->expects($this->never())->method('summarizeByFlow');

		$this->assertSame(400, $this->runs()->summary()->getStatus());
	}

	// --- signalByKey ------------------------------------------------------

	public function testASignalOnAFlowTheCallerMayNotRunIsRefusedAndNothingIsSignalled(): void {
		$this->signIn('alice', false);
		$this->mapper->method('findSuspendedByCorrelationKey')->with('k-1')->willReturn([$this->suspendedRun()]);
		$this->flows->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->signals->expects($this->never())->method('signalRunAs');

		$response = $this->runs()->signalByKey('  k-1 ');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such flow: flow-1'], $response->getData());
	}

	public function testASignalCarriesTheBodyWithoutTheRoutingKeys(): void {
		$this->signIn('alice', false);
		$run = $this->suspendedRun();
		$this->params = ['key' => 'k-1', '_route' => 'r', 'answer' => 'yes'];
		$this->mapper->method('findSuspendedByCorrelationKey')->willReturn([$run]);
		$this->flows->method('find')->willReturn(new Flow());
		$this->signals->expects($this->once())->method('signalRunAs')
			->with($run, ['answer' => 'yes'], 'alice')->willReturn($run);

		$this->assertSame(200, $this->runs()->signalByKey('k-1')->getStatus());
	}

	// --- test -------------------------------------------------------------

	public function testATestRunOnAFlowTheCallerMayNotRunIsRefusedBeforeItIsResolved(): void {
		$this->signIn('alice', false);
		$this->params = ['flowId' => 'flow-1'];
		$this->flows->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->resolvers->expects($this->never())->method('resolveFlow');
		$this->runner->expects($this->never())->method('queue');

		$this->assertSame(404, $this->testRuns()->test()->getStatus());
	}

	public function testATestRunWithoutStartAtOrSeedItemsPassesNullForBoth(): void {
		$this->signIn('alice', false);
		$this->params = ['flowId' => ' flow-1 ', 'startAt' => '   '];
		$flow = new Flow();
		$run = $this->suspendedRun();
		$this->flows->method('find')->willReturn($flow);
		$this->resolvers->method('resolveFlow')->with('flow-1')->willReturn(['id' => 'flow-1']);
		$this->runner->expects($this->once())->method('queue')
			->with('flow-1', [], 'test', ['pins' => []], 'alice')->willReturn($run);
		$this->runner->expects($this->once())->method('execute')->willReturnCallback(
			function (...$positional) use ($run): FlowRun {
				$args = $this->named(FlowRunService::class, 'execute', $positional);
				$this->assertNull($args['seedItems']);
				$this->assertNull($args['startAt']);
				return $run;
			}
		);

		$this->assertSame(200, $this->testRuns()->test()->getStatus());
	}

	public function testATestRunNormalisesTheSeedItems(): void {
		$this->signIn('alice', false);
		$this->params = ['flowId' => 'flow-1', 'seedItems' => [['json' => ['a' => 1]]], 'pins' => ['n1' => [1]]];
		$flow = new Flow();
		$run = $this->suspendedRun();
		$this->flows->method('find')->willReturn($flow);
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'flow-1']);
		$this->runner->method('queue')->with('flow-1', [], 'test', ['pins' => ['n1' => [1]]], 'alice')->willReturn($run);
		$this->runner->expects($this->once())->method('seedItemsFrom')->with([['json' => ['a' => 1]]])
			->willReturnCallback(static fn (mixed $value): array => FlowItems::normalise(value: $value));
		$this->runner->expects($this->once())->method('execute')->willReturnCallback(
			function (...$positional) use ($run): FlowRun {
				$args = $this->named(FlowRunService::class, 'execute', $positional);
				$this->assertIsArray($args['seedItems']);
				$this->assertNotEmpty($args['seedItems']);
				return $run;
			}
		);

		$this->assertSame(200, $this->testRuns()->test()->getStatus());
	}
}
