<?php

/**
 * Unit tests for OperationsMaintenanceController.
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

use OCA\OpenRegister\Controller\OperationsMaintenanceController;
use OCA\OpenRegister\Service\Operations\MaintenanceModeServiceInterface;
use OCA\OpenRegister\Service\Operations\SupportBundleServiceInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\OperationsMaintenanceControllerFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The maintenance endpoint dispatches on the HTTP method: GET reads the state,
 * DELETE leaves with the acting user, anything else enters with the acting user
 * and the optional message. The support bundle and facts endpoints pass through.
 *
 * @covers \OCA\OpenRegister\Controller\OperationsMaintenanceController
 * @uses \OCA\OpenRegister\Controller\Support\OperationsActingRequest
 * @uses \OCA\OpenRegister\Controller\Support\OperationsMaintenanceControllerServices
 *
 * @spec openspec/specs/production-observability/spec.md
 */
class OperationsMaintenanceControllerTest extends TestCase {

	/** @var MaintenanceModeServiceInterface&MockObject */
	private MaintenanceModeServiceInterface $maintenance;

	/** @var SupportBundleServiceInterface&MockObject */
	private SupportBundleServiceInterface $bundle;

	/** @var IRequest&MockObject */
	private IRequest $request;

	protected function setUp(): void {
		$this->maintenance = $this->createMock(MaintenanceModeServiceInterface::class);
		$this->bundle = $this->createMock(SupportBundleServiceInterface::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * A controller wired to a request with a method, params and acting user.
	 *
	 * @param string                      $method The HTTP method.
	 * @param array<string, string|null>  $params The params getParam() answers.
	 * @param string                      $uid    The signed-in user, '' for anonymous.
	 *
	 * @return OperationsMaintenanceController The controller.
	 */
	private function controller(string $method = 'GET', array $params = [], string $uid = 'ops'): OperationsMaintenanceController {
		$this->request->method('getMethod')->willReturn($method);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$signedIn = $user;
		if ($uid === '') {
			$signedIn = null;
		}

		$session->method('getUser')->willReturn($signedIn);

		return OperationsMaintenanceControllerFactory::create(
			$this->request,
			$this->maintenance,
			$this->bundle,
			$session
		);
	}//end controller()

	public function testGetReadsTheState(): void {
		$this->maintenance->expects($this->once())->method('state')->willReturn(['on' => false]);
		$this->maintenance->expects($this->never())->method('enter');
		$this->maintenance->expects($this->never())->method('leave');

		$response = $this->controller(method: 'GET')->maintenance();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['on' => false], $response->getData());
	}//end testGetReadsTheState()

	public function testDeleteLeavesAsTheActingUser(): void {
		$this->maintenance->expects($this->once())->method('leave')->with('ops')->willReturn(['on' => false]);
		$this->maintenance->expects($this->never())->method('enter');

		$response = $this->controller(method: 'DELETE', uid: 'ops')->maintenance();

		$this->assertSame(['on' => false], $response->getData());
	}//end testDeleteLeavesAsTheActingUser()

	public function testPostEntersWithTheActorAndTheMessage(): void {
		$this->maintenance->expects($this->once())
			->method('enter')
			->with('ops', 'upgrading')
			->willReturn(['on' => true, 'message' => 'upgrading']);

		$response = $this->controller(method: 'POST', params: ['message' => 'upgrading'], uid: 'ops')->maintenance();

		$this->assertSame(['on' => true, 'message' => 'upgrading'], $response->getData());
	}//end testPostEntersWithTheActorAndTheMessage()

	public function testPostWithoutAMessageEntersWithNull(): void {
		$this->maintenance->expects($this->once())->method('enter')->with('ops', null)->willReturn(['on' => true]);

		$this->controller(method: 'PUT', params: ['message' => ''], uid: 'ops')->maintenance();
	}//end testPostWithoutAMessageEntersWithNull()

	public function testAnAnonymousActorIsTheEmptyString(): void {
		$this->maintenance->expects($this->once())->method('leave')->with('')->willReturn([]);

		$this->controller(method: 'DELETE', uid: '')->maintenance();
	}//end testAnAnonymousActorIsTheEmptyString()

	public function testSupportBundlePassesThrough(): void {
		$this->bundle->expects($this->once())->method('build')->willReturn(['logs' => []]);

		$response = $this->controller()->supportBundle();

		$this->assertSame(['logs' => []], $response->getData());
	}//end testSupportBundlePassesThrough()

	public function testFactsPassesThrough(): void {
		$this->bundle->expects($this->once())->method('facts')->willReturn(['version' => '1']);

		$response = $this->controller()->facts();

		$this->assertSame(['version' => '1'], $response->getData());
	}//end testFactsPassesThrough()
}//end class
