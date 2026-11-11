<?php

/**
 * FlowControllerReadRightTest: the flow listing, a flow and its stored state need `flow.read`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The flow reads that return a definition or its state ask `flow.read` before any lookup.
 */
class FlowControllerReadRightTest extends TestCase {

	/**
	 * @var FlowService&MockObject The flow store.
	 */
	private FlowService&MockObject $flows;

	/**
	 * @var FlowStateMapper&MockObject The stored flow state.
	 */
	private FlowStateMapper&MockObject $state;

	/**
	 * @var array<int, string> The rights the controller asked about, in order.
	 */
	private array $asked = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->flows = $this->createMock(originalClassName: FlowService::class);
		$this->state = $this->createMock(originalClassName: FlowStateMapper::class);
	}

	/**
	 * A controller for a caller holding the given rights.
	 *
	 * @param string|null        $user   The caller's uid, null when anonymous.
	 * @param array<int, string> $rights The rights the caller holds.
	 *
	 * @return FlowController The controller.
	 */
	private function controller(?string $user, array $rights): FlowController {
		$auth = $this->createMock(originalClassName: OpenRegisterActionAuthService::class);
		$auth->method('can')->willReturnCallback(function (IUser $caller, string $action) use ($rights): bool {
			$this->asked[] = $action;
			return in_array($action, $rights, true);
		});

		$caller = null;
		if ($user !== null) {
			$caller = $this->createMock(originalClassName: IUser::class);
			$caller->method('getUID')->willReturn($user);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($caller);

		return FlowControllerFactory::create(
			'openregister',
			$this->createMock(originalClassName: IRequest::class),
			$this->createMock(originalClassName: EventCatalogService::class),
			$this->createMock(originalClassName: FlowNodeRegistry::class),
			$this->state,
			$this->createMock(originalClassName: FlowNodePreflight::class),
			$this->flows,
			new FlowAccess(
				caller: new CallerContext(userSession: $session, groupManager: $this->createMock(originalClassName: IGroupManager::class)),
				actionAuth: $auth
			),
			$this->createMock(originalClassName: FlowVersionService::class)
		);
	}

	/**
	 * Call one of the guarded reads.
	 *
	 * @param FlowController $controller The controller.
	 * @param string         $read       The method name.
	 *
	 * @return JSONResponse The answer.
	 */
	private function read(FlowController $controller, string $read): JSONResponse {
		return match ($read) {
			'index' => $controller->index(),
			'show'  => $controller->show(id: 'f1'),
			'state' => $controller->state(flowId: 'f1'),
		};
	}

	/**
	 * The guarded reads.
	 *
	 * @return array<string, array{string}> The method names.
	 */
	public static function reads(): array {
		return ['index' => ['index'], 'show' => ['show'], 'state' => ['state']];
	}

	/**
	 * An anonymous caller is refused before any flow is read.
	 *
	 * @param string $read The method name.
	 *
	 * @return void
	 */
	#[DataProvider('reads')]
	public function testAnonymousIs401BeforeAnyRead(string $read): void {
		$this->flows->expects($this->never())->method($this->anything());
		$this->state->expects($this->never())->method($this->anything());

		$response = $this->read(controller: $this->controller(user: null, rights: ['flow.read']), read: $read);

		$this->assertSame(expected: 401, actual: $response->getStatus());
	}

	/**
	 * A caller without `flow.read` is refused before any flow is read.
	 *
	 * @param string $read The method name.
	 *
	 * @return void
	 */
	#[DataProvider('reads')]
	public function testMissingReadRightIs403BeforeAnyRead(string $read): void {
		$this->flows->expects($this->never())->method($this->anything());
		$this->state->expects($this->never())->method($this->anything());

		$response = $this->read(controller: $this->controller(user: 'bob', rights: ['flow.update']), read: $read);

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'You do not have the "flow.read" right.'], actual: $response->getData());
		$this->assertSame(expected: ['flow.read'], actual: $this->asked);
	}

	/**
	 * A caller holding `flow.read` gets the flow, as before.
	 *
	 * @param string $read The method name.
	 *
	 * @return void
	 */
	#[DataProvider('reads')]
	public function testTheReadRightLetsTheReadThrough(string $read): void {
		$flow = new Flow();
		$flow->setUuid('f1');
		$this->flows->method('find')->willReturn($flow);
		$this->flows->method('findAll')->willReturn([$flow]);
		$this->flows->method('count')->willReturn(1);

		$response = $this->read(controller: $this->controller(user: 'bob', rights: ['flow.read']), read: $read);

		$this->assertSame(expected: 200, actual: $response->getStatus());
	}
}
