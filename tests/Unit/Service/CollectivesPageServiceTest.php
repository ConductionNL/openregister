<?php

/**
 * Unit tests for CollectivesPageService.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @spec openspec/changes/integration-collectives/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCA\OpenRegister\Service\CollectivesPageService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CollectivesPageServiceTest extends TestCase
{

    private CollectiveLinkMapper&MockObject $mapper;

    private IAppManager&MockObject $appManager;

    private IClientService&MockObject $clientService;

    private IURLGenerator&MockObject $urlGenerator;

    private IUserSession&MockObject $userSession;

    private LoggerInterface&MockObject $logger;

    private CollectivesPageService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->getMockBuilder(CollectiveLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByObjectUuid', 'findByObjectAndPage', 'deleteByObjectUuid', 'insert', 'delete', 'find'])
            ->getMock();

        $this->appManager    = $this->createMock(IAppManager::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->urlGenerator  = $this->createMock(IURLGenerator::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->urlGenerator
            ->method('linkToRouteAbsolute')
            ->willReturn('https://nc.example.com/apps/collectives');

        $this->service = new CollectivesPageService(
            collectiveLinkMapper: $this->mapper,
            appManager: $this->appManager,
            clientService: $this->clientService,
            urlGenerator: $this->urlGenerator,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    private function mockUser(string $uid='alice'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end mockUser()

    public function testIsCollectivesAvailableReturnsTrueWhenEnabled(): void
    {
        $this->appManager
            ->method('isEnabledForUser')
            ->with('collectives')
            ->willReturn(true);

        $this->assertTrue($this->service->isCollectivesAvailable());
    }//end testIsCollectivesAvailableReturnsTrueWhenEnabled()

    public function testIsCollectivesAvailableReturnsFalseWhenDisabled(): void
    {
        $this->appManager
            ->method('isEnabledForUser')
            ->with('collectives')
            ->willReturn(false);

        $this->assertFalse($this->service->isCollectivesAvailable());
    }//end testIsCollectivesAvailableReturnsFalseWhenDisabled()

    public function testGetLinksForObjectReturnsSerializedLinks(): void
    {
        $link = new CollectiveLink();
        $link->setObjectUuid('obj-uuid-1');
        $link->setCollectiveName('gemeentehandboek');
        $link->setPageId(42);
        $link->setPageTitle('Bezwaarprocedure');
        $link->setLinkedBy('alice');
        $link->setLinkedAt(new DateTime('2026-06-01T10:00:00Z'));

        $this->mapper
            ->method('findByObjectUuid')
            ->with('obj-uuid-1')
            ->willReturn([$link]);

        $result = $this->service->getLinksForObject('obj-uuid-1');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('gemeentehandboek', $result['results'][0]['collectiveName']);
        $this->assertSame(42, $result['results'][0]['pageId']);
    }//end testGetLinksForObjectReturnsSerializedLinks()

    public function testGetLinksForObjectReturnsEmptyWhenNone(): void
    {
        $this->mapper->method('findByObjectUuid')->willReturn([]);

        $result = $this->service->getLinksForObject('no-links-uuid');

        $this->assertSame(0, $result['total']);
        $this->assertEmpty($result['results']);
    }//end testGetLinksForObjectReturnsEmptyWhenNone()

    public function testLinkPageThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->service->linkPage(
            objectUuid: 'obj-uuid',
            collectiveName: 'test',
            pageId: 1,
            pageTitle: 'Title',
        );
    }//end testLinkPageThrowsWhenNoUser()

    public function testLinkPageThrowsOnDuplicate(): void
    {
        $this->mockUser();

        $existing = new CollectiveLink();
        $this->mapper
            ->method('findByObjectAndPage')
            ->with('obj-uuid', 7)
            ->willReturn($existing);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);

        $this->service->linkPage(
            objectUuid: 'obj-uuid',
            collectiveName: 'mywiki',
            pageId: 7,
            pageTitle: 'Intro',
        );
    }//end testLinkPageThrowsOnDuplicate()

    public function testLinkPageCreatesAndInsertsLink(): void
    {
        $this->mockUser('bob');

        $this->mapper->method('findByObjectAndPage')->willReturn(null);

        $saved = new CollectiveLink();
        $saved->setObjectUuid('obj-uuid');
        $saved->setCollectiveName('mywiki');
        $saved->setPageId(7);

        $this->mapper
            ->expects($this->once())
            ->method('insert')
            ->willReturn($saved);

        $result = $this->service->linkPage(
            objectUuid: 'obj-uuid',
            collectiveName: 'mywiki',
            pageId: 7,
            pageTitle: 'Intro',
        );

        $this->assertSame('obj-uuid', $result->getObjectUuid());
        $this->assertSame('mywiki', $result->getCollectiveName());
    }//end testLinkPageCreatesAndInsertsLink()

    public function testUnlinkPageThrowsWhenNotFound(): void
    {
        $this->mapper
            ->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);

        $this->service->unlinkPage(999);
    }//end testUnlinkPageThrowsWhenNotFound()

    public function testUnlinkPageDeletesLink(): void
    {
        $link = new CollectiveLink();
        $this->mapper->method('find')->willReturn($link);
        $this->mapper->expects($this->once())->method('delete')->with($link);

        $this->service->unlinkPage(1);
    }//end testUnlinkPageDeletesLink()

    public function testDeleteLinksForObjectDelegatesCount(): void
    {
        $this->mapper
            ->method('deleteByObjectUuid')
            ->with('del-uuid')
            ->willReturn(3);

        $count = $this->service->deleteLinksForObject('del-uuid');

        $this->assertSame(3, $count);
    }//end testDeleteLinksForObjectDelegatesCount()

    public function testListCollectivesReturnsEmptyWhenAppUnavailable(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $result = $this->service->listCollectives();

        $this->assertSame([], $result);
    }//end testListCollectivesReturnsEmptyWhenAppUnavailable()

    public function testListPagesReturnsEmptyWhenAppUnavailable(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $result = $this->service->listPages('somecollective');

        $this->assertSame([], $result);
    }//end testListPagesReturnsEmptyWhenAppUnavailable()

    public function testGetPageContentReturnsNullWhenAppUnavailable(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $result = $this->service->getPageContent(collectiveName: 'wiki', pageId: 1);

        $this->assertNull($result);
    }//end testGetPageContentReturnsNullWhenAppUnavailable()
}//end class
