<?php

/**
 * Golden test pinning the access outcomes of the FlowController version and
 * lifecycle actions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Service\Flow\FlowAdoptionRefused;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Each version/lifecycle action asks for its right (flow.read or
 * flow.update) before it reads the flow: anonymous is 401, no right is 403,
 * an unknown flow is 404.
 */
class FlowControllerLifecycleAccessGoldenTest extends TestCase {

	private const ACTIONS = [
		'versions' => 'flow.read',
		'version' => 'flow.read',
		'versionPreview' => 'flow.read',
		'publish' => 'flow.update',
		'draft' => 'flow.update',
		'deprecate' => 'flow.update',
		'adopt' => 'flow.update',
	];

	private FlowService&MockObject $flows;

	private FlowVersionService&MockObject $versions;

	private IRequest&MockObject $request;

	/** @var list<string> */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		$this->flows    = $this->createMock(FlowService::class);
		$this->versions = $this->createMock(FlowVersionService::class);
		$this->request  = $this->createMock(IRequest::class);
	}

	private function controller(?string $user, array $rights): FlowController {
		$auth = $this->createMock(OpenRegisterActionAuthService::class);
		$auth->method('can')->willReturnCallback(function (IUser $u, string $action) use ($rights): bool {
			$this->asked[] = $action;
			return in_array($action, $rights, true);
		});
		$iUser = null;
		if ($user !== null) {
			$iUser = $this->createMock(IUser::class);
			$iUser->method('getUID')->willReturn($user);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($iUser);
		return FlowControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(EventCatalogService::class),
			$this->createMock(FlowNodeRegistry::class),
			$this->createMock(FlowStateMapper::class),
			$this->createMock(FlowNodePreflight::class),
			$this->flows,
			new FlowAccess(new CallerContext($session, $this->createMock(IGroupManager::class)), $auth),
			$this->versions
		);
	}

	private function call(FlowController $controller, string $action): \OCP\AppFramework\Http\JSONResponse {
		if ($action === 'version') {
			return $controller->version(id: 'f1', version: 2);
		}

		return $controller->$action(id: 'f1');
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function actions(): array {
		$out = [];
		foreach (self::ACTIONS as $action => $right) {
			$out[$action] = [$action, $right];
		}

		return $out;
	}

	#[DataProvider('actions')]
	public function testAnonymousIs401BeforeAnyRead(string $action, string $right): void {
		$this->flows->expects($this->never())->method($this->anything());
		$this->versions->expects($this->never())->method($this->anything());

		$response = $this->call($this->controller(null, [$right]), $action);

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Not signed in.'], $response->getData());
	}

	#[DataProvider('actions')]
	public function testMissingRightIs403BeforeAnyRead(string $action, string $right): void {
		$this->flows->expects($this->never())->method($this->anything());
		$this->versions->expects($this->never())->method($this->anything());
		$other = $right === 'flow.read' ? ['flow.update'] : ['flow.read'];

		$response = $this->call($this->controller('bob', $other), $action);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => sprintf('You do not have the "%s" right.', $right)], $response->getData());
		$this->assertSame([$right], $this->asked);
	}

	#[DataProvider('actions')]
	public function testUnknownFlowIs404(string $action, string $right): void {
		$this->flows->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->versions->expects($this->never())->method($this->anything());

		$response = $this->call($this->controller('bob', [$right]), $action);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such flow'], $response->getData());
	}

	private function flow(): Flow {
		$flow = new Flow();
		$flow->setUuid('f1');
		return $flow;
	}

	private function versionRow(int $number): FlowVersion {
		$row = new FlowVersion();
		$row->setFlowUuid('f1');
		$row->setVersion($number);
		return $row;
	}

	public function testReadsWithTheRight(): void {
		$this->flows->method('find')->with('f1')->willReturn($this->flow());
		$this->versions->method('versionsOf')->with('f1')->willReturn([$this->versionRow(1), $this->versionRow(2)]);
		$this->versions->method('versionOf')->willReturnCallback(fn (string $uuid, int $number): ?FlowVersion => $number === 2 ? $this->versionRow(2) : null);
		$this->versions->method('graphOfVersion')->willReturn(['nodes' => []]);
		$this->versions->method('previewPublish')->willReturn(['bump' => 'minor']);
		$controller = $this->controller('bob', ['flow.read']);

		$list = $controller->versions(id: 'f1')->getData();
		$one  = $controller->version(id: 'f1', version: 2)->getData();
		$none = $controller->version(id: 'f1', version: 3);

		$this->assertSame(2, $list['total']);
		$this->assertCount(2, $list['results']);
		$this->assertSame(['nodes' => []], $one['graph']);
		$this->assertSame(2, $one['version']);
		$this->assertSame([404, ['error' => 'No such version']], [$none->getStatus(), $none->getData()]);
		$this->assertSame(['bump' => 'minor'], $controller->versionPreview(id: 'f1')->getData());
	}

	public function testPublishPassesPublisherAndBump(): void {
		$this->flows->method('find')->willReturn($this->flow());
		$this->request->method('getParam')->with('bump')->willReturn('major');
		$this->versions->expects($this->once())->method('publish')
			->with($this->anything(), 'bob', 'major')->willReturn($this->versionRow(4));

		$response = $this->controller('bob', ['flow.update'])->publish(id: 'f1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(4, $response->getData()['version']);
	}

	public function testPublishRefusals(): void {
		$this->flows->method('find')->willReturn($this->flow());
		$this->versions->method('publish')->willReturnOnConsecutiveCalls(
			$this->throwException(new FlowLifecycleRefused(FlowLifecycleRefused::REASON_NOT_A_DRAFT, 'f1', 'published')),
			$this->throwException(new \UnexpectedValueException('too small'))
		);
		$controller = $this->controller('bob', ['flow.update']);

		$lifecycle = $controller->publish(id: 'f1');
		$bump      = $controller->publish(id: 'f1');

		$this->assertSame(409, $lifecycle->getStatus());
		$this->assertSame(['reason', 'lifecycleStatus', 'flowId'], array_slice(array_keys($lifecycle->getData()), 1));
		$this->assertSame(['f1', 'published'], [$lifecycle->getData()['flowId'], $lifecycle->getData()['lifecycleStatus']]);
		$this->assertSame([409, ['error' => 'too small', 'kind' => 'version-bump-refused']], [$bump->getStatus(), $bump->getData()]);
	}

	public function testDraftDeprecateAndAdopt(): void {
		$this->flows->method('find')->willReturn($this->flow());
		$this->versions->method('createDraft')->willReturn($this->versionRow(5));
		$this->versions->method('deprecate')->willThrowException(new FlowLifecycleRefused(FlowLifecycleRefused::REASON_NOT_PUBLISHED, 'f1'));
		$this->flows->method('adopt')->willReturnOnConsecutiveCalls(
			$this->flow(),
			$this->throwException(new FlowAdoptionRefused('owned', 'Already owned'))
		);
		$controller = $this->controller('bob', ['flow.update']);

		$draft = $controller->draft(id: 'f1');
		$this->assertSame([201, 5], [$draft->getStatus(), $draft->getData()['version']]);
		$this->assertSame(409, $controller->deprecate(id: 'f1')->getStatus());
		$this->assertSame(200, $controller->adopt(id: 'f1')->getStatus());
		$refused = $controller->adopt(id: 'f1');
		$this->assertSame([409, ['error' => 'Already owned', 'reason' => 'owned']], [$refused->getStatus(), $refused->getData()]);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function otherGuarded(): array {
		return ['exportBpmn' => ['exportBpmn', 'flow.read'], 'importBpmn' => ['importBpmn', 'flow.create'], 'run' => ['run', 'flow.run']];
	}

	private function callOther(FlowController $controller, string $action): \OCP\AppFramework\Http\Response {
		if ($action === 'importBpmn') {
			return $controller->importBpmn();
		}

		return $controller->$action(id: 'f1');
	}

	#[DataProvider('otherGuarded')]
	public function testOtherGuardedActionsRefuseFirst(string $action, string $right): void {
		$this->flows->expects($this->never())->method($this->anything());
		$this->request->expects($this->never())->method('getParam');
		$other = $right === 'flow.read' ? ['flow.update'] : ['flow.read'];

		$anonymous = $this->callOther($this->controller(null, [$right]), $action);
		$noRight   = $this->callOther($this->controller('bob', $other), $action);

		$this->assertSame(401, $anonymous->getStatus());
		$this->assertSame(403, $noRight->getStatus());
		$this->assertSame(['error' => sprintf('You do not have the "%s" right.', $right)], $noRight->getData());
	}

	public function testExportOfUnknownFlowAndRunOutcomes(): void {
		$this->flows->method('find')->willThrowException(new \RuntimeException('x'));
		$export = $this->controller('bob', ['flow.read'])->exportBpmn(id: 'f1');
		$this->assertSame([404, ['error' => 'No such flow: f1']], [$export->getStatus(), $export->getData()]);
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function syncValues(): array {
		return [
			'true' => [true, true], 'string true' => ['true', true], 'string 1' => ['1', true], 'int 1' => [1, true],
			'false' => [false, false], 'string false' => ['false', false], 'yes' => ['yes', false],
		];
	}

	#[DataProvider('syncValues')]
	public function testRunQueuesTheTestTrigger(mixed $sync, bool $expected): void {
		$this->request->method('getParam')->willReturnMap([['subject', [], ['s' => 1]], ['context', [], ['c' => 2]], ['sync', false, $sync]]);
		$run = new \OCA\OpenRegister\Db\FlowRun();
		$this->flows->expects($this->once())->method('run')
			->with('f1', ['s' => 1], ['c' => 2], $expected, \OCA\OpenRegister\Service\Flow\FlowRunVersionPin::TRIGGER_TEST)
			->willReturn($run);

		$this->assertSame(201, $this->controller('bob', ['flow.run'])->run(id: 'f1')->getStatus());
	}

	public function testRunRefusals(): void {
		$this->request->method('getParam')->willReturnArgument(1);
		$this->flows->method('run')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('x')),
			$this->throwException(new FlowLifecycleRefused(FlowLifecycleRefused::REASON_NO_PUBLISHED_VERSION, 'f1'))
		);
		$controller = $this->controller('bob', ['flow.run']);

		$missing = $controller->run(id: 'f1');
		$refused = $controller->run(id: 'f1');

		$this->assertSame([404, ['error' => 'No such flow']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame(409, $refused->getStatus());
	}
}
