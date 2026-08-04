<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\OpenProjectLinkService}.
 *
 * Exercises the Tier-2 service contract (link / createAndLink / unlink /
 * list + available picker) against a mocked OpenProjectLinkMapper and a
 * mocked OpenProjectProvider / ExternalIntegrationRouter. Tests that
 * touch the external OpenProject source use the "OpenConnector
 * unavailable" path or a stubbed provider response because the real
 * round-trip requires the OpenConnector app + a live source.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-openproject/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Db\OpenProjectLinkMapper;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCA\OpenRegister\Service\OpenProjectLinkService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OpenProjectLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class OpenProjectLinkServiceTest extends TestCase
{

    private OpenProjectLinkMapper&MockObject $mapper;

    private OpenProjectProvider&MockObject $provider;

    private ExternalIntegrationRouter&MockObject $router;

    private IAppManager&MockObject $appManager;

    private IUserSession&MockObject $userSession;

    private LoggerInterface&MockObject $logger;

    private OpenProjectLinkService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->getMockBuilder(OpenProjectLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(
                [
                    'findByObjectUuid',
                    'findByObjectAndWorkPackage',
                    'deleteByObjectAndWorkPackage',
                    'insert',
                    'update',
                ]
            )
            ->getMock();

        $this->provider    = $this->createMock(OpenProjectProvider::class);
        $this->router      = $this->createMock(ExternalIntegrationRouter::class);
        $this->appManager  = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->service = new OpenProjectLinkService(
            $this->mapper,
            $this->provider,
            $this->router,
            $this->appManager,
            $this->userSession,
            $this->logger
        );
    }//end setUp()

    private function setupUser(string $uid='alice'): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        return $user;
    }//end setupUser()

    public function testIsOpenConnectorAvailableTrue(): void
    {
        $this->appManager->method('isEnabledForUser')->with('openconnector')->willReturn(true);
        $this->assertTrue($this->service->isOpenConnectorAvailable());
    }//end testIsOpenConnectorAvailableTrue()

    public function testIsOpenConnectorAvailableFalse(): void
    {
        $this->appManager->method('isEnabledForUser')->with('openconnector')->willReturn(false);
        $this->assertFalse($this->service->isOpenConnectorAvailable());
    }//end testIsOpenConnectorAvailableFalse()

    public function testLinkWorkPackageThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->linkWorkPackage('abc-123', 1, 2, 99);
    }//end testLinkWorkPackageThrowsWhenNoUser()

    public function testLinkWorkPackageThrowsOnZeroId(): void
    {
        $this->setupUser();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service->linkWorkPackage('abc-123', 1, 2, 0);
    }//end testLinkWorkPackageThrowsOnZeroId()

    public function testLinkWorkPackageThrowsOnDuplicate(): void
    {
        $this->setupUser();
        $this->mapper->method('findByObjectAndWorkPackage')->with('abc-123', 99)->willReturn(new OpenProjectLink());

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Work package already linked to this object');

        $this->service->linkWorkPackage('abc-123', 1, 2, 99);
    }//end testLinkWorkPackageThrowsOnDuplicate()

    public function testLinkWorkPackagePersistsEvenWhenSourceUnconfigured(): void
    {
        $this->setupUser();
        // OpenConnector unavailable → metadata fetch skipped, link still persisted.
        $this->appManager->method('isEnabledForUser')->with('openconnector')->willReturn(false);
        $this->mapper->method('findByObjectAndWorkPackage')->with('abc-123', 99)->willReturn(null);
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (OpenProjectLink $link): OpenProjectLink => $link);

        $link = $this->service->linkWorkPackage('abc-123', 1, 2, 99);

        $this->assertSame(99, $link->getWorkPackageId());
        $this->assertSame('#99', $link->getSubject());
        $this->assertSame('alice', $link->getLinkedBy());
    }//end testLinkWorkPackagePersistsEvenWhenSourceUnconfigured()

    public function testCreateAndLinkWorkPackageThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->createAndLinkWorkPackage('abc-123', 1, 2, '5', 'Ship it');
    }//end testCreateAndLinkWorkPackageThrowsWhenNoUser()

    public function testCreateAndLinkWorkPackageThrowsOnEmptySubject(): void
    {
        $this->setupUser();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service->createAndLinkWorkPackage('abc-123', 1, 2, '5', '   ');
    }//end testCreateAndLinkWorkPackageThrowsOnEmptySubject()

    public function testCreateAndLinkWorkPackageThrowsOnMissingProject(): void
    {
        $this->setupUser();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service->createAndLinkWorkPackage('abc-123', 1, 2, '', 'Ship it');
    }//end testCreateAndLinkWorkPackageThrowsOnMissingProject()

    public function testCreateAndLinkWorkPackageSurfaces503WhenSourceUnavailable(): void
    {
        $this->setupUser();
        $this->provider->method('create')->willThrowException(
            new ProviderUnavailableException(
                'no source',
                ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING
            )
        );

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);

        $this->service->createAndLinkWorkPackage('abc-123', 1, 2, '5', 'Ship it');
    }//end testCreateAndLinkWorkPackageSurfaces503WhenSourceUnavailable()

    public function testCreateAndLinkWorkPackagePersistsLink(): void
    {
        $this->setupUser();
        $this->provider->method('create')->willReturn(
            [
                'id'       => 42,
                'subject'  => 'Ship it',
                'status'   => 'New',
                'type'     => 'Task',
                'priority' => 'High',
                'project'  => 'Portal',
                'url'      => 'https://op.example.org/wp/42',
            ]
        );
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (OpenProjectLink $link): OpenProjectLink => $link);

        $link = $this->service->createAndLinkWorkPackage('abc-123', 1, 2, '5', 'Ship it', 'Task');

        $this->assertSame(42, $link->getWorkPackageId());
        $this->assertSame('Ship it', $link->getSubject());
        $this->assertSame('Task', $link->getType());
        $this->assertSame('High', $link->getPriority());
        $this->assertSame('Portal', $link->getProject());
    }//end testCreateAndLinkWorkPackagePersistsLink()

    public function testUnlinkThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->unlink('abc-123', 99);
    }//end testUnlinkThrowsWhenNoUser()

    public function testUnlinkThrowsWhenLinkMissing(): void
    {
        $this->setupUser();
        $this->mapper->method('deleteByObjectAndWorkPackage')->with('abc-123', 99)->willReturn(0);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);

        $this->service->unlink('abc-123', 99);
    }//end testUnlinkThrowsWhenLinkMissing()

    public function testUnlinkSucceeds(): void
    {
        $this->setupUser();
        $this->mapper->expects($this->once())
            ->method('deleteByObjectAndWorkPackage')
            ->with('abc-123', 99)
            ->willReturn(1);

        $this->service->unlink('abc-123', 99);
    }//end testUnlinkSucceeds()

    public function testGetLinkedWorkPackagesReturnsRows(): void
    {
        // OpenConnector unavailable → no refresh, rows returned as-is.
        $this->appManager->method('isEnabledForUser')->with('openconnector')->willReturn(false);

        $link = new OpenProjectLink();
        $link->setObjectUuid('abc-123');
        $link->setWorkPackageId(42);
        $link->setSubject('Ship it');
        $link->setStatus('New');

        $this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

        $rows = $this->service->getLinkedWorkPackages('abc-123');

        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows[0]['workPackageId']);
        $this->assertSame('Ship it', $rows[0]['subject']);
        $this->assertSame('New', $rows[0]['status']);
    }//end testGetLinkedWorkPackagesReturnsRows()

    public function testGetLinkedWorkPackagesEmpty(): void
    {
        $this->appManager->method('isEnabledForUser')->with('openconnector')->willReturn(false);
        $this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([]);

        $this->assertSame([], $this->service->getLinkedWorkPackages('abc-123'));
    }//end testGetLinkedWorkPackagesEmpty()

    public function testGetAvailableWorkPackagesThrowsWhenOpenConnectorUnavailable(): void
    {
        $this->appManager->method('isEnabledForUser')->with('openconnector')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);

        $this->service->getAvailableWorkPackages();
    }//end testGetAvailableWorkPackagesThrowsWhenOpenConnectorUnavailable()

    public function testGetAvailableWorkPackagesSurfaces503OnRouterFailure(): void
    {
        $this->appManager->method('isEnabledForUser')->with('openconnector')->willReturn(true);
        $this->router->method('call')->willThrowException(
            new ProviderUnavailableException(
                'down',
                ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN
            )
        );

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);

        $this->service->getAvailableWorkPackages('export');
    }//end testGetAvailableWorkPackagesSurfaces503OnRouterFailure()

    public function testGetAvailableWorkPackagesNormalisesRows(): void
    {
        $this->appManager->method('isEnabledForUser')->with('openconnector')->willReturn(true);
        $this->router->method('call')->willReturn(
            [
                'results' => [
                    [
                        'id'      => 7,
                        'subject' => 'Refactor auth',
                        '_links'  => [
                            'self'   => ['href' => '/api/v3/work_packages/7'],
                            'status' => ['title' => 'Open'],
                            'type'   => ['title' => 'Bug'],
                        ],
                    ],
                ],
            ]
        );

        $rows = $this->service->getAvailableWorkPackages();

        $this->assertCount(1, $rows);
        $this->assertSame(7, $rows[0]['workPackageId']);
        $this->assertSame('Refactor auth', $rows[0]['subject']);
        $this->assertSame('Bug', $rows[0]['type']);
        $this->assertSame('Open', $rows[0]['status']);
        $this->assertSame('/api/v3/work_packages/7', $rows[0]['url']);
    }//end testGetAvailableWorkPackagesNormalisesRows()
}//end class
