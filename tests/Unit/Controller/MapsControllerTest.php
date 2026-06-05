<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\MapsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\MapLocationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MapsController.
 */
class MapsControllerTest extends TestCase
{

    private IRequest&MockObject $request;

    private MapLocationService&MockObject $mapLocationService;

    private ObjectService&MockObject $objectService;

    private IUserSession&MockObject $userSession;

    private LoggerInterface&MockObject $logger;

    private MapsController $controller;

    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->mapLocationService = $this->createMock(MapLocationService::class);
        $this->objectService      = $this->createMock(ObjectService::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new MapsController(
            appName: 'openregister',
            request: $this->request,
            mapLocationService: $this->mapLocationService,
            objectService: $this->objectService,
            userSession: $this->userSession,
            logger: $this->logger
        );
    }//end setUp()

    private function setupObject(string $uuid='obj-uuid'): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setRegister(1);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);
        return $object;
    }//end setupObject()

    private function setupUser(string $uid='user1'): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        return $user;
    }//end setupUser()

    // ── index() ──
    public function testIndexReturnsMapsWhenAvailable(): void
    {
        $this->setupObject();
        $this->mapLocationService->method('isMapsAvailable')->willReturn(true);
        $this->request->method('getParams')->willReturn([]);

        $expected = ['results' => [['id' => 1, 'lat' => 52.37]], 'total' => 1];
        $this->mapLocationService->method('getLocationsForObject')
            ->with('obj-uuid', null, null)
            ->willReturn($expected);

        $response = $this->controller->index('reg', 'sch', 'obj-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($expected, $response->getData());
    }//end testIndexReturnsMapsWhenAvailable()

    public function testIndexReturns501WhenMapsNotInstalled(): void
    {
        $this->mapLocationService->method('isMapsAvailable')->willReturn(false);

        $response = $this->controller->index('reg', 'sch', 'any-id');

        $this->assertSame(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
        $this->assertSame('APP_NOT_AVAILABLE', $response->getData()['code']);
    }//end testIndexReturns501WhenMapsNotInstalled()

    public function testIndexReturns404WhenObjectNotFound(): void
    {
        $this->mapLocationService->method('isMapsAvailable')->willReturn(true);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn(null);

        $response = $this->controller->index('reg', 'sch', 'missing');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testIndexReturns404WhenObjectNotFound()

    // ── create() ──
    public function testCreateByAddressReturns201(): void
    {
        $this->setupObject();
        $this->setupUser();
        $this->mapLocationService->method('isMapsAvailable')->willReturn(true);
        $this->request->method('getParams')->willReturn(
                [
                    'mode'    => 'address',
                    'address' => 'Kalverstraat 1, Amsterdam',
                ]
                );

        $expected = ['id' => 5, 'address' => 'Kalverstraat 1, Amsterdam', 'addressSource' => 'address-geocoded'];
        $this->mapLocationService->method('addByAddress')->willReturn($expected);

        $response = $this->controller->create('reg', 'sch', 'obj-uuid');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame($expected, $response->getData());
    }//end testCreateByAddressReturns201()

    public function testCreateByClickReturns201(): void
    {
        $this->setupObject();
        $this->setupUser();
        $this->mapLocationService->method('isMapsAvailable')->willReturn(true);
        $this->request->method('getParams')->willReturn(
                [
                    'mode' => 'click',
                    'lat'  => '52.3676',
                    'lon'  => '4.9041',
                ]
                );

        $expected = ['id' => 6, 'lat' => 52.3676, 'lon' => 4.9041, 'addressSource' => 'click-placed'];
        $this->mapLocationService->method('addByClick')->willReturn($expected);

        $response = $this->controller->create('reg', 'sch', 'obj-uuid');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }//end testCreateByClickReturns201()

    public function testCreateMissingAddressReturns400(): void
    {
        $this->setupObject();
        $this->setupUser();
        $this->mapLocationService->method('isMapsAvailable')->willReturn(true);
        $this->request->method('getParams')->willReturn(['mode' => 'address']);

        $response = $this->controller->create('reg', 'sch', 'obj-uuid');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testCreateMissingAddressReturns400()

    // ── destroy() ──
    public function testDestroyReturns200WhenLinkDeleted(): void
    {
        $this->setupObject();
        $this->setupUser();
        $this->mapLocationService->method('isMapsAvailable')->willReturn(true);
        $this->mapLocationService->method('removeLink')->with('obj-uuid', 42)->willReturn(true);

        $response = $this->controller->destroy('reg', 'sch', 'obj-uuid', '42');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }//end testDestroyReturns200WhenLinkDeleted()

    public function testDestroyReturns404WhenLinkNotFound(): void
    {
        $this->setupObject();
        $this->setupUser();
        $this->mapLocationService->method('isMapsAvailable')->willReturn(true);
        $this->mapLocationService->method('removeLink')->willReturn(false);

        $response = $this->controller->destroy('reg', 'sch', 'obj-uuid', '999');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testDestroyReturns404WhenLinkNotFound()
}//end class
