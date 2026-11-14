<?php

/**
 * FlowProgressController: the same access as reading the flow, and the window check.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-the-report-respects-organisations-and-shows-no-content
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\FlowProgressController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowAccessInterface;
use OCA\OpenRegister\Service\Flow\FlowProgressService;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\FlowProgressController
 * @uses \OCA\OpenRegister\Service\Flow\FlowProgressPeriod
 * @uses \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 * @uses \OCA\OpenRegister\Db\Flow
 */
class FlowProgressControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private FlowServiceInterface&MockObject $flows;

	private FlowAccessInterface&MockObject $access;

	private FlowProgressService&MockObject $progress;

	/**
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$this->flows = $this->createMock(FlowServiceInterface::class);
		$this->access = $this->createMock(FlowAccessInterface::class);
		$this->progress = $this->createMock(FlowProgressService::class);
	}

	private function controller(): FlowProgressController {
		return new FlowProgressController(
			request: $this->request,
			flows: $this->flows,
			access: $this->access,
			progress: $this->progress,
			temporal: new TaskTemporalProjection()
		);
	}

	private function signedInWithRead(bool $may = true): void {
		$user = $this->createMock(IUser::class);
		$this->access->method('currentUser')->willReturn($user);
		$this->access->method('may')->with($user, 'flow.read')->willReturn($may);
	}

	private function flow(string $uuid): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setOrganisation('org-a');

		return $flow;
	}

	/**
	 * A non-admin organisation member reads a flow's progress: 200 with the service's report.
	 */
	public function testAMemberReadsTheProgressOfAFlowOfTheirOrganisation(): void {
		$this->signedInWithRead();
		$flow = $this->flow('flow-1');
		$this->flows->expects($this->once())->method('find')->with('flow-1')->willReturn($flow);
		$this->progress->expects($this->once())->method('forFlow')
			->with(
				$flow,
				$this->callback(static fn (DateTime $from): bool => $from->format('Y-m-d H:i:s') === '2026-08-01 00:00:00'),
				$this->callback(static fn (DateTime $to): bool => $to->format('Y-m-d H:i:s') === '2026-08-31 23:59:59')
			)
			->willReturn(['flow' => 'flow-1', 'steps' => []]);
		$this->params = ['from' => '2026-08-01', 'to' => '2026-08-31'];

		$response = $this->controller()->show(id: 'flow-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['flow' => 'flow-1', 'steps' => []], $response->getData());
	}

	/**
	 * Another organisation's flow is not found, exactly like a flow that does not exist.
	 */
	public function testAnotherOrganisationsFlowIs404(): void {
		$this->signedInWithRead();
		$this->flows->method('find')->willThrowException(new DoesNotExistException('No such flow'));
		$this->progress->expects($this->never())->method('forFlow');

		$response = $this->controller()->show(id: 'flow-of-b');

		$this->assertSame(404, $response->getStatus());
	}

	public function testNoSessionIs401(): void {
		$this->access->method('currentUser')->willReturn(null);
		$this->flows->expects($this->never())->method('find');

		$this->assertSame(401, $this->controller()->show(id: 'flow-1')->getStatus());
		$this->assertSame(401, $this->controller()->index()->getStatus());
	}

	public function testWithoutTheReadRightIs403(): void {
		$this->signedInWithRead(may: false);
		$this->flows->expects($this->never())->method('find');

		$response = $this->controller()->show(id: 'flow-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertStringContainsString('flow.read', $response->getData()['error']);
	}

	/**
	 * The spec's 400: a window longer than 366 days names `from`.
	 */
	public function testAWindowThatIsTooLongIs400NamingFrom(): void {
		$this->signedInWithRead();
		$this->flows->expects($this->never())->method('find');
		$this->params = ['from' => '2025-01-01', 'to' => '2026-09-01'];

		$response = $this->controller()->show(id: 'flow-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('"from"', $response->getData()['error']);
		$this->assertSame(400, $this->controller()->index()->getStatus());
	}

	/**
	 * The comparison pages the caller's flows, at most 50 a page, with the total.
	 */
	public function testAManagerComparesFlowsFiftyAPage(): void {
		$this->signedInWithRead();
		$flows = array_map(fn (int $i): Flow => $this->flow('flow-' . $i), range(1, 50));
		$this->flows->expects($this->once())->method('findAll')->with(null, null, null, 50, 50)->willReturn($flows);
		$this->flows->method('count')->willReturn(60);
		$this->progress->expects($this->once())->method('forFlows')
			->with($flows, $this->isInstanceOf(DateTime::class), $this->isInstanceOf(DateTime::class))
			->willReturn(array_fill(0, 50, ['flow' => 'x']));
		$this->params = ['_limit' => '500', '_page' => '2'];

		$response = $this->controller()->index();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(50, $data['results']);
		$this->assertSame([60, 2, 50], [$data['total'], $data['page'], $data['limit']]);
		$this->assertArrayHasKey('from', $data['window']);
	}
}
