<?php

/**
 * Golden outcomes of moving flow runs to another version.
 *
 * Pins, on the controller as it was before migrate() and migrateRuns() were
 * split into small units, the order of the refusals: no migration service is a
 * 503, an unknown run a 404, a flow the caller may not run is refused by the
 * runnable guard, no session is a 401, and only then is anything moved. Also
 * that a mapping that is no list is passed as an empty one, and that a
 * migration that did not happen is a 422.
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

use OCA\OpenRegister\Service\Flow\FlowRunMigrationTarget;
use OCA\OpenRegister\Service\Flow\VersionRunsMigration;
use OCA\OpenRegister\Controller\FlowRunMigrationController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowRunMigrationControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of FlowRunMigrationController.
 */
class FlowRunMigrationAccessGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private FlowRunMapperInterface&MockObject $mapper;

	private IUserSession&MockObject $userSession;

	private FlowService&MockObject $flows;

	private FlowRunMigrationServiceInterface&MockObject $migrations;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(FlowRunMapperInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->flows = $this->createMock(FlowService::class);
		$this->migrations = $this->createMock(FlowRunMigrationServiceInterface::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);
	}

	/**
	 * The controller, with or without the migration service.
	 *
	 * @param bool $withMigrations Whether the service is wired.
	 *
	 * @return FlowRunMigrationController
	 */
	private function controller(bool $withMigrations = true): FlowRunMigrationController {
		$access = $this->createMock(FlowAccess::class);
		$access->method('may')->willReturn(true);

		return FlowRunMigrationControllerFactory::create(
			appName: 'openregister',
			request: $this->request,
			mapper: $this->mapper,
			userSession: $this->userSession,
			guard: new FlowRunnableGuard(flows: $this->flows, access: $access),
			migrations: ($withMigrations === true ? $this->migrations : null)
		);
	}

	/**
	 * Sign alice in.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * A run of flow `flow-1`, found by uuid.
	 *
	 * @return void
	 */
	private function runFound(): void {
		$run = new FlowRun();
		$run->setFlowId('flow-1');
		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
	}

	public function testWithoutTheServiceBothActionsAre503(): void {
		$this->signIn();
		$this->mapper->expects($this->never())->method('findByUuid');

		$this->assertSame(503, $this->controller(false)->migrate('run-1')->getStatus());
		$this->assertSame(503, $this->controller(false)->migrateRuns('flow-1')->getStatus());
	}

	public function testAnUnknownRunIs404(): void {
		$this->signIn();
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('x'));
		$this->migrations->expects($this->never())->method($this->anything());

		$response = $this->controller()->migrate('run-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such run'], $response->getData());
	}

	public function testARunOfAFlowTheCallerMayNotRunIsRefusedAndNotMoved(): void {
		$this->signIn();
		$this->runFound();
		$this->flows->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->migrations->expects($this->never())->method($this->anything());

		$this->assertSame(404, $this->controller()->migrate('run-1')->getStatus());
		$this->assertSame(404, $this->controller()->migrateRuns('flow-1')->getStatus());
	}

	public function testWithoutASessionNothingIsMoved(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->runFound();
		$this->flows->method('find')->willReturn(new Flow());
		$this->migrations->expects($this->never())->method($this->anything());

		$this->assertSame(401, $this->controller()->migrate('run-1')->getStatus());
		$this->assertSame(401, $this->controller()->migrateRuns('flow-1')->getStatus());
	}

	public function testAMigrationThatDidNotHappenIs422AndANonListMappingIsEmpty(): void {
		$this->signIn();
		$this->runFound();
		$this->flows->method('find')->willReturn(new Flow());
		$this->params = ['mapping' => 'n1=n2', 'targetVersion' => '3', 'reason' => 'fix'];
		$this->migrations->expects($this->once())->method('migrate')
			->with('run-1', $this->equalTo(new FlowRunMigrationTarget(3, 'fix', 'alice', [])))
			->willReturn(['dryRun' => false, 'migrated' => false, 'errors' => ['x']]);

		$response = $this->controller()->migrate('run-1');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['dryRun' => false, 'migrated' => false, 'errors' => ['x']], $response->getData());
	}

	public function testAMigrationThatHappenedIs200(): void {
		$this->signIn();
		$this->runFound();
		$this->flows->method('find')->willReturn(new Flow());
		$this->params = ['mapping' => ['n1' => 'n2']];
		$this->migrations->expects($this->once())->method('migrate')
			->with('run-1', $this->equalTo(new FlowRunMigrationTarget(0, '', 'alice', ['n1' => 'n2'])))
			->willReturn(['dryRun' => false, 'migrated' => true]);

		$this->assertSame(200, $this->controller()->migrate('run-1')->getStatus());
	}

	public function testMovingAVersionsRunsPassesTheCastVersionsAndAnEmptyMappingForANonList(): void {
		$this->signIn();
		$this->flows->method('find')->willReturn(new Flow());
		$this->params = ['reason' => ' upgrade ', 'sourceVersion' => '1', 'targetVersion' => '2', 'mapping' => 7];
		$this->migrations->expects($this->once())->method('migrateRunsOfVersion')
			->with($this->equalTo(new VersionRunsMigration('flow-1', 1, new FlowRunMigrationTarget(2, 'upgrade', 'alice', []))))
			->willReturn(['moved' => 4]);

		$response = $this->controller()->migrateRuns('flow-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['moved' => 4], $response->getData());
	}
}
