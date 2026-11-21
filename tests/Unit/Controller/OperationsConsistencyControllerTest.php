<?php

/**
 * Unit tests for OperationsConsistencyController.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OperationsConsistencyController;
use OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException;
use OCA\OpenRegister\Exception\RepairRefusedException;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckServiceInterface;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairServiceInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\OperationsConsistencyControllerFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Consistency reads the check report (and turns a would-write refusal into a
 * 500 that names the probe); the repair endpoints need a `check` slug, refuse
 * a repair with a 422 that names the reason, and pass the acting user to apply.
 *
 * @covers \OCA\OpenRegister\Controller\OperationsConsistencyController
 * @uses \OCA\OpenRegister\Controller\Support\OperationsActingRequest
 * @uses \OCA\OpenRegister\Controller\Support\OperationsConsistencyControllerServices
 * @uses \OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException
 * @uses \OCA\OpenRegister\Exception\RepairRefusedException
 *
 * @spec openspec/specs/production-observability/spec.md
 */
class OperationsConsistencyControllerTest extends TestCase {

	/** @var ConsistencyCheckServiceInterface&MockObject */
	private ConsistencyCheckServiceInterface $check;

	/** @var ConsistencyRepairServiceInterface&MockObject */
	private ConsistencyRepairServiceInterface $repair;

	/** @var IRequest&MockObject */
	private IRequest $request;

	protected function setUp(): void {
		$this->check = $this->createMock(ConsistencyCheckServiceInterface::class);
		$this->repair = $this->createMock(ConsistencyRepairServiceInterface::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * A controller wired to a request with params and an acting user.
	 *
	 * @param array<string, string|null> $params The params getParam() answers.
	 * @param string                     $uid    The signed-in user.
	 *
	 * @return OperationsConsistencyController The controller.
	 */
	private function controller(array $params = [], string $uid = 'ops'): OperationsConsistencyController {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return OperationsConsistencyControllerFactory::create(
			$this->request,
			$this->check,
			$this->repair,
			$session
		);
	}//end controller()

	public function testConsistencyReturnsTheReport(): void {
		$this->check->expects($this->once())->method('check')->willReturn(['findings' => []]);

		$response = $this->controller()->consistency();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['findings' => []], $response->getData());
	}//end testConsistencyReturnsTheReport()

	public function testAWouldWriteRefusalIs500AndNamesTheProbe(): void {
		$this->check->method('check')->willThrowException(new ConsistencyCheckWouldWriteException('it would write', 'probe-7'));

		$response = $this->controller()->consistency();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['error' => 'would-write', 'probe' => 'probe-7', 'message' => 'it would write'],
			$response->getData()
		);
	}//end testAWouldWriteRefusalIs500AndNamesTheProbe()

	public function testRepairPlanNeedsACheckSlug(): void {
		$this->repair->expects($this->never())->method('plan');

		$response = $this->controller(params: ['check' => ''])->repairPlan();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('no-check', $response->getData()['error']);
	}//end testRepairPlanNeedsACheckSlug()

	public function testRepairPlanRunsTheNamedCheck(): void {
		$this->repair->expects($this->once())->method('plan')->with('orphans')->willReturn(['steps' => 2]);

		$response = $this->controller(params: ['check' => 'orphans'])->repairPlan();

		$this->assertSame(['steps' => 2], $response->getData());
	}//end testRepairPlanRunsTheNamedCheck()

	public function testRepairAppliesAsTheActingUser(): void {
		$this->repair->expects($this->once())->method('apply')->with('orphans', 'ops')->willReturn(['repaired' => 1]);

		$response = $this->controller(params: ['check' => 'orphans'], uid: 'ops')->repair();

		$this->assertSame(['repaired' => 1], $response->getData());
	}//end testRepairAppliesAsTheActingUser()

	public function testARefusedRepairIs422AndNamesTheReason(): void {
		$this->repair->method('apply')->willThrowException(new RepairRefusedException('not allowed now', 'locked'));

		$response = $this->controller(params: ['check' => 'orphans'])->repair();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			['error' => 'refused', 'reason' => 'locked', 'message' => 'not allowed now'],
			$response->getData()
		);
	}//end testARefusedRepairIs422AndNamesTheReason()
}//end class
