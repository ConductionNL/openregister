<?php

/**
 * Packaging configuration for sharing is gated exactly like publishing it.
 *
 * `POST /api/federated-config/bundle` is `#[NoAdminRequired]` and produces the
 * byte-for-byte payload `publish()` pushes to GitHub — the same
 * `IShareableConfigType::serialise()` call, minus the round-trip. `publish()`
 * checked `FederatedConfigAccess::canPublish()`; `bundle()` checked nothing, so
 * anyone the publish gate refused could ask for the export directly and do what
 * they liked with it. A gate one endpoint enforces and its twin does not is not
 * a gate.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FederatedConfigController;
use OCA\OpenRegister\Service\Config\FederatedConfigAccess;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The publish gate on the bundle endpoint.
 *
 * @covers \OCA\OpenRegister\Controller\FederatedConfigController
 */
class FederatedConfigControllerBundleGateTest extends TestCase {

	/**
	 * The federation engine, mocked.
	 *
	 * @var FederatedConfigService&MockObject
	 */
	private FederatedConfigService&MockObject $service;

	/**
	 * The publish/install gate, mocked.
	 *
	 * @var FederatedConfigAccess&MockObject
	 */
	private FederatedConfigAccess&MockObject $access;

	/**
	 * The request, mocked.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The controller under test.
	 *
	 * @var FederatedConfigController
	 */
	private FederatedConfigController $controller;

	/**
	 * Build the controller over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(FederatedConfigService::class);
		$this->access = $this->createMock(FederatedConfigAccess::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new FederatedConfigController(
			'openregister',
			$this->request,
			$this->service,
			$this->access,
			$this->createMock(IUserSession::class),
			$this->createMock(IConfig::class),
			$this->createMock(IGroupManager::class)
		);

	}//end setUp()

	/**
	 * THE SECURITY PROPERTY. A caller the publish gate refuses gets a 403 and
	 * NO export — the serialiser is never reached, so nothing is packaged and
	 * then discarded.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotPublishCannotBundle(): void {
		$this->access->method('canPublish')->willReturn(false);
		$this->service->expects($this->never())->method('bundle');
		$this->request->method('getParam')->willReturn('openregister.flows');

		$response = $this->controller->bundle();

		$this->assertSame(403, $response->getStatus());

	}//end testACallerWhoMayNotPublishCannotBundle()

	/**
	 * The positive control: a caller the gate allows still gets the bundle.
	 * Without this, the refusal above is satisfied by an endpoint that refuses
	 * everyone.
	 *
	 * @return void
	 */
	public function testACallerWhoMayPublishStillGetsTheBundle(): void {
		$this->access->method('canPublish')->willReturn(true);
		$this->service->expects($this->once())
			->method('bundle')
			->willReturn(['type' => 'openregister.flows', 'flows' => []]);
		$this->request->method('getParam')->willReturn('openregister.flows');

		$response = $this->controller->bundle();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('openregister.flows', $response->getData()['type']);

	}//end testACallerWhoMayPublishStillGetsTheBundle()
}//end class
