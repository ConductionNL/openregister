<?php

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\MapLink;
use OCA\OpenRegister\Db\MapLinkMapper;
use OCA\OpenRegister\Service\MapLocationService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MapLocationService.
 */
class MapLocationServiceTest extends TestCase
{

    private MapLinkMapper&MockObject $mapLinkMapper;

    private IAppManager&MockObject $appManager;

    private IClientService&MockObject $clientService;

    private IURLGenerator&MockObject $urlGenerator;

    private IUserSession&MockObject $userSession;

    private LoggerInterface&MockObject $logger;

    private MapLocationService $service;

    protected function setUp(): void
    {
        $this->mapLinkMapper = $this->getMockBuilder(MapLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByObjectUuid', 'countByObjectUuid', 'findByObjectAndId', 'deleteByObjectUuid', 'insert', 'delete'])
            ->getMock();
        $this->appManager    = $this->createMock(IAppManager::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->urlGenerator  = $this->createMock(IURLGenerator::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->service = new MapLocationService(
            mapLinkMapper: $this->mapLinkMapper,
            appManager: $this->appManager,
            clientService: $this->clientService,
            urlGenerator: $this->urlGenerator,
            userSession: $this->userSession,
            logger: $this->logger
        );
    }//end setUp()

    // ── isMapsAvailable ──
    public function testIsMapsAvailableReturnsTrueWhenInstalled(): void
    {
        $this->appManager->method('isInstalled')->with('maps')->willReturn(true);
        $this->assertTrue($this->service->isMapsAvailable());
    }//end testIsMapsAvailableReturnsTrueWhenInstalled()

    public function testIsMapsAvailableReturnsFalseWhenNotInstalled(): void
    {
        $this->appManager->method('isInstalled')->with('maps')->willReturn(false);
        $this->assertFalse($this->service->isMapsAvailable());
    }//end testIsMapsAvailableReturnsFalseWhenNotInstalled()

    // ── getLocationsForObject ──
    public function testGetLocationsForObjectReturnsPaginatedResults(): void
    {
        $link = new MapLink();
        $link->setObjectUuid('uuid-1');
        $link->setAddress('Dam 1, Amsterdam');

        $this->mapLinkMapper->method('findByObjectUuid')->with('uuid-1', 10, 0)->willReturn([$link]);
        $this->mapLinkMapper->method('countByObjectUuid')->with('uuid-1')->willReturn(1);

        $result = $this->service->getLocationsForObject(objectUuid: 'uuid-1', limit: 10, offset: 0);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Dam 1, Amsterdam', $result['results'][0]['address']);
    }//end testGetLocationsForObjectReturnsPaginatedResults()

    // ── addByClick ──
    public function testAddByClickPersistsLinkWithClickSource(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->mapLinkMapper->method('insert')->willReturnCallback(
                static function (MapLink $link) {
                    return $link;
                }
                );

        $result = $this->service->addByClick(
            objectUuid: 'uuid-2',
            registerId: 3,
            lat: 52.3676,
            lon: 4.9041,
            address: 'Centraal Station Amsterdam'
        );

        $this->assertSame('click-placed', $result['addressSource']);
        $this->assertSame(52.3676, $result['lat']);
        $this->assertSame(4.9041, $result['lon']);
    }//end testAddByClickPersistsLinkWithClickSource()

    // ── addByAddress (geocoding failure path) ──
    public function testAddByAddressStoresNullCoordsWhenGeocodingFails(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user2');
        $this->userSession->method('getUser')->willReturn($user);

        // Simulate HTTP exception from geocoding.
        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new \Exception('connection refused'));
        $this->clientService->method('newClient')->willReturn($client);
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->mapLinkMapper->method('insert')->willReturnCallback(
                static function (MapLink $link) {
                    return $link;
                }
                );

        $result = $this->service->addByAddress(
            objectUuid: 'uuid-3',
            registerId: 1,
            address: 'Binnenhof 1, Den Haag'
        );

        $this->assertSame('address-geocoded', $result['addressSource']);
        $this->assertNull($result['lat']);
        $this->assertNull($result['lon']);
        $this->assertSame('Binnenhof 1, Den Haag', $result['address']);
    }//end testAddByAddressStoresNullCoordsWhenGeocodingFails()

    // ── removeLink ──
    public function testRemoveLinkReturnsTrueWhenFound(): void
    {
        $link = new MapLink();
        $link->setObjectUuid('uuid-4');

        $this->mapLinkMapper->method('findByObjectAndId')->with('uuid-4', 99)->willReturn($link);
        $this->mapLinkMapper->expects($this->once())->method('delete')->with($link);

        $this->assertTrue($this->service->removeLink(objectUuid: 'uuid-4', linkId: 99));
    }//end testRemoveLinkReturnsTrueWhenFound()

    public function testRemoveLinkReturnsFalseWhenNotFound(): void
    {
        $this->mapLinkMapper->method('findByObjectAndId')->with('uuid-5', 55)->willReturn(null);

        $this->assertFalse($this->service->removeLink(objectUuid: 'uuid-5', linkId: 55));
    }//end testRemoveLinkReturnsFalseWhenNotFound()
}//end class
