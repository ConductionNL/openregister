<?php

/**
 * The admin gate on the federation trust configuration.
 *
 * `trust()` reads, and `setTrust()` writes, the list of publisher keys this
 * instance will accept configuration from. Appending a key there decides whose
 * published configuration this instance installs — it is the root of the
 * federation trust chain, not a preference.
 *
 * Both are `#[NoCSRFRequired]` and admin-only by Nextcloud's default, with the
 * body checking again. #2342 removed a `#[NoAdminRequired]` that used to
 * contradict the body. The pair being right depends on BOTH halves staying
 * right, and the body half had no test.
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
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reading and writing the trust configuration is administrators only.
 *
 * @covers \OCA\OpenRegister\Controller\FederatedConfigController
 */
class FederatedConfigControllerTrustGateTest extends TestCase
{

    /**
     * The federation engine, mocked.
     *
     * @var FederatedConfigService&MockObject
     */
    private FederatedConfigService&MockObject $service;

    /**
     * The request, mocked.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * The session, mocked.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * The group manager, mocked.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Build the controller over mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service      = $this->createMock(FederatedConfigService::class);
        $this->request      = $this->createMock(IRequest::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

    }//end setUp()

    /**
     * A controller whose caller is, or is not, an administrator.
     *
     * @param boolean $isAdmin   Whether the caller is an administrator.
     * @param boolean $hasUser   Whether there is a session at all.
     *
     * @return FederatedConfigController The controller.
     */
    private function controllerFor(bool $isAdmin, bool $hasUser=true): FederatedConfigController
    {
        $user = null;
        if ($hasUser === true) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('someone');
        }

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn($isAdmin);

        return new FederatedConfigController(
            'openregister',
            $this->request,
            $this->service,
            $this->createMock(FederatedConfigAccess::class),
            $this->userSession,
            $this->createMock(IConfig::class),
            $this->groupManager
        );

    }//end controllerFor()

    /**
     * A non-administrator cannot read the trust configuration — it names the
     * allowlisted sources and the trusted publisher keys.
     *
     * @return void
     */
    public function testANonAdminCannotReadTheTrustConfiguration(): void
    {
        $this->service->expects($this->never())->method('getTrustConfig');

        $this->assertSame(403, $this->controllerFor(isAdmin: false)->trust()->getStatus());

    }//end testANonAdminCannotReadTheTrustConfiguration()

    /**
     * FAIL CLOSED. No session is not an administrator, and `isAdmin()` short
     * circuits on the null before it can be asked about a uid that does not
     * exist.
     *
     * @return void
     */
    public function testAnAnonymousCallerCannotReadTheTrustConfiguration(): void
    {
        $this->service->expects($this->never())->method('getTrustConfig');

        $response = $this->controllerFor(isAdmin: true, hasUser: false)->trust();

        $this->assertSame(403, $response->getStatus());

    }//end testAnAnonymousCallerCannotReadTheTrustConfiguration()

    /**
     * The positive control: an administrator gets the configuration, so the
     * refusals above are not an endpoint that refuses everyone.
     *
     * @return void
     */
    public function testAnAdminReadsTheTrustConfiguration(): void
    {
        $this->service->method('getTrustConfig')->willReturn(['sourceAllowlist' => ['ConductionNL']]);

        $response = $this->controllerFor(isAdmin: true)->trust();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['ConductionNL'], $response->getData()['sourceAllowlist']);

    }//end testAnAdminReadsTheTrustConfiguration()

    /**
     * THE SHARP ONE. A non-administrator must not be able to append a publisher
     * key — that is the decision about whose configuration this instance will
     * accept. `trustPublisherKey` is asserted never to be reached, not merely
     * that the status is 403.
     *
     * @return void
     */
    public function testANonAdminCannotTrustAPublisherKey(): void
    {
        $this->service->expects($this->never())->method('trustPublisherKey');
        $this->service->expects($this->never())->method('setTrustValue');
        $this->request->method('getParam')->willReturn('an-attacker-supplied-public-key');

        $this->assertSame(403, $this->controllerFor(isAdmin: false)->setTrust()->getStatus());

    }//end testANonAdminCannotTrustAPublisherKey()

    /**
     * An administrator supplying a key appends it, and the updated
     * configuration comes back.
     *
     * @return void
     */
    public function testAnAdminCanTrustAPublisherKey(): void
    {
        $this->service->expects($this->once())
            ->method('trustPublisherKey')
            ->with('a-public-key');
        $this->service->method('getTrustConfig')->willReturn(['trustedKeys' => ['a-public-key']]);
        $this->request->method('getParam')->willReturnCallback(
            static function (string $name, mixed $default=null) {
                if ($name === 'trustKey') {
                    return 'a-public-key';
                }

                return $default;
            }
        );

        $response = $this->controllerFor(isAdmin: true)->setTrust();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['a-public-key'], $response->getData()['trustedKeys']);

    }//end testAnAdminCanTrustAPublisherKey()

    /**
     * An administrator supplying neither a key nor a field gets a 400 — the
     * branch that stops an empty POST from silently reporting success.
     *
     * @return void
     */
    public function testATrustUpdateNeedsAFieldOrAKey(): void
    {
        $this->service->expects($this->never())->method('trustPublisherKey');
        $this->service->expects($this->never())->method('setTrustValue');
        $this->request->method('getParam')->willReturn('');

        $this->assertSame(400, $this->controllerFor(isAdmin: true)->setTrust()->getStatus());

    }//end testATrustUpdateNeedsAFieldOrAKey()

    /**
     * A field the service rejects is a 400 carrying the service's own message,
     * not a 500.
     *
     * @return void
     */
    public function testAnInvalidTrustFieldIsABadRequest(): void
    {
        $this->service->method('setTrustValue')->willThrowException(new \InvalidArgumentException('Unknown field: nope'));
        $this->request->method('getParam')->willReturnCallback(
            static function (string $name, mixed $default=null) {
                if ($name === 'field') {
                    return 'nope';
                }

                return $default;
            }
        );

        $response = $this->controllerFor(isAdmin: true)->setTrust();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Unknown field: nope', $response->getData()['error']);

    }//end testAnInvalidTrustFieldIsABadRequest()

    /**
     * The happy path for a field update.
     *
     * @return void
     */
    public function testAnAdminCanSetATrustField(): void
    {
        $this->service->expects($this->once())
            ->method('setTrustValue')
            ->with('sourceAllowlist', 'ConductionNL');
        $this->service->method('getTrustConfig')->willReturn(['sourceAllowlist' => ['ConductionNL']]);
        $this->request->method('getParam')->willReturnCallback(
            static function (string $name, mixed $default=null) {
                if ($name === 'field') {
                    return 'sourceAllowlist';
                }

                if ($name === 'value') {
                    return 'ConductionNL';
                }

                return $default;
            }
        );

        $response = $this->controllerFor(isAdmin: true)->setTrust();

        $this->assertSame(200, $response->getStatus());

    }//end testAnAdminCanSetATrustField()
}//end class
