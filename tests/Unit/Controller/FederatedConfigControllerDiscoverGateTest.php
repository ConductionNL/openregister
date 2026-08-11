<?php

/**
 * Discovering and fetching shared configuration is gated exactly like installing it.
 *
 * `discover()` and `fetch()` are `#[NoAdminRequired]` and both hand a
 * caller-supplied target to the GitHub broker, which signs the request with the
 * caller's chosen store credential. The credential is held BY REFERENCE — the
 * broker never yields the secret — so an ungated `fetch(repo, path)` is an
 * arbitrary credentialed GitHub read granted to whoever asks, including the
 * callers `install()` refuses. `install()` checked
 * `FederatedConfigAccess::canInstall()`; the two steps that precede it checked
 * nothing.
 *
 * Same shape as FederatedConfigControllerBundleGateTest: a refusal test plus the
 * positive control, so "refuses everyone" cannot pass for "enforces the gate".
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
 * The install gate on the discover and fetch endpoints.
 *
 * @covers \OCA\OpenRegister\Controller\FederatedConfigController
 */
class FederatedConfigControllerDiscoverGateTest extends TestCase
{

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
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->createMock(FederatedConfigService::class);
        $this->access  = $this->createMock(FederatedConfigAccess::class);
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
     * THE SECURITY PROPERTY for discovery. A caller the install gate refuses gets
     * a 403 and the broker is never asked — no outbound request is made on their
     * behalf at all.
     *
     * @return void
     */
    public function testACallerWhoMayNotInstallCannotDiscover(): void
    {
        $this->access->method('canInstall')->willReturn(false);
        $this->service->expects($this->never())->method('discover');
        $this->request->method('getParam')->willReturn('openregister-flows');

        $response = $this->controller->discover();

        $this->assertSame(403, $response->getStatus());

    }//end testACallerWhoMayNotInstallCannotDiscover()

    /**
     * The positive control for discovery: a caller the gate allows still gets
     * results. Without this, the refusal above is satisfied by an endpoint that
     * refuses everyone.
     *
     * @return void
     */
    public function testACallerWhoMayInstallStillGetsDiscoveryResults(): void
    {
        $this->access->method('canInstall')->willReturn(true);
        $this->service->expects($this->once())
            ->method('discover')
            ->willReturn([['repo' => 'ConductionNL/store', 'name' => 'store']]);
        $this->request->method('getParam')->willReturn('openregister-flows');

        $response = $this->controller->discover();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('ConductionNL/store', $response->getData()['results'][0]['repo']);

    }//end testACallerWhoMayInstallStillGetsDiscoveryResults()

    /**
     * THE SECURITY PROPERTY for fetch, and the sharper of the two: `fetch()`
     * takes the repo AND the path from the request, so ungated it is an
     * arbitrary credentialed read, not merely a search.
     *
     * @return void
     */
    public function testACallerWhoMayNotInstallCannotFetchABundle(): void
    {
        $this->access->method('canInstall')->willReturn(false);
        $this->service->expects($this->never())->method('fetchBundle');
        $this->request->method('getParam')->willReturn('ConductionNL/store');

        $response = $this->controller->fetch();

        $this->assertSame(403, $response->getStatus());

    }//end testACallerWhoMayNotInstallCannotFetchABundle()

    /**
     * The positive control for fetch.
     *
     * @return void
     */
    public function testACallerWhoMayInstallStillGetsTheBundle(): void
    {
        $this->access->method('canInstall')->willReturn(true);
        $this->service->expects($this->once())
            ->method('fetchBundle')
            ->willReturn(['type' => 'openregister.flows', 'flows' => []]);
        $this->request->method('getParam')->willReturn('ConductionNL/store');

        $response = $this->controller->fetch();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('openregister.flows', $response->getData()['bundle']['type']);

    }//end testACallerWhoMayInstallStillGetsTheBundle()
}//end class
