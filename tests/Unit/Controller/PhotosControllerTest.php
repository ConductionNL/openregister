<?php

/**
 * Unit tests for PhotosController.
 *
 * @category Test
 * @package  Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-6
 */

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\PhotosController;
use OCA\OpenRegister\Db\FileLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PhotoService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test suite for PhotosController.
 */
class PhotosControllerTest extends TestCase
{

    private PhotosController $controller;
    private IRequest&MockObject $request;
    private PhotoService&MockObject $photoService;
    private ObjectService&MockObject $objectService;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(IRequest::class);
        $this->photoService  = $this->createMock(PhotoService::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new PhotosController(
            appName: 'openregister',
            request: $this->request,
            photoService: $this->photoService,
            objectService: $this->objectService,
            userSession: $this->userSession,
            logger: $this->logger
        );
    }//end setUp()

    public function testIndexReturns404WhenObjectNotFound(): void
    {
        $this->objectService->method('getObject')->willReturn(null);

        $response = $this->controller->index('reg', 'sch', 'missing-uuid');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(404, $response->getStatus());
    }//end testIndexReturns404WhenObjectNotFound()

    public function testIndexReturnsPhotoList(): void
    {
        $object = $this->buildObject('obj-uuid');
        $this->objectService->method('getObject')->willReturn($object);

        $link = new FileLink();
        $link->setObjectUuid('obj-uuid');
        $link->setMimeType('image/jpeg');

        $this->photoService->method('getPhotos')->with('obj-uuid')->willReturn([$link]);

        $response = $this->controller->index('reg', 'sch', 'obj-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(1, $data);
        $this->assertSame('image/jpeg', $data[0]['mimeType']);
    }//end testIndexReturnsPhotoList()

    public function testShowReturns404WhenPhotoNotFound(): void
    {
        $object = $this->buildObject('obj-uuid');
        $this->objectService->method('getObject')->willReturn($object);
        $this->photoService->method('getPhoto')->willReturn(null);

        $response = $this->controller->show('reg', 'sch', 'obj-uuid', 99);

        $this->assertSame(404, $response->getStatus());
    }//end testShowReturns404WhenPhotoNotFound()

    public function testShowReturnsPhotoWithExif(): void
    {
        $object = $this->buildObject('obj-uuid');
        $this->objectService->method('getObject')->willReturn($object);

        $link = new FileLink();
        $link->setObjectUuid('obj-uuid');
        $link->setMimeType('image/png');
        $link->setExifMetadata('{"Make":"Canon"}');

        $this->photoService->method('getPhoto')->with('obj-uuid', 5)->willReturn($link);

        $response = $this->controller->show('reg', 'sch', 'obj-uuid', 5);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('Canon', $data['exifMetadata']['Make']);
    }//end testShowReturnsPhotoWithExif()

    public function testCreateReturns400WhenFileIdMissing(): void
    {
        $object = $this->buildObject('obj-uuid');
        $this->objectService->method('getObject')->willReturn($object);
        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->create('reg', 'sch', 'obj-uuid');

        $this->assertSame(400, $response->getStatus());
    }//end testCreateReturns400WhenFileIdMissing()

    public function testCreateReturns201OnSuccess(): void
    {
        $object = $this->buildObject('obj-uuid');
        $this->objectService->method('getObject')->willReturn($object);
        $this->request->method('getParams')->willReturn(['fileId' => 42]);

        $link = new FileLink();
        $link->setObjectUuid('obj-uuid');
        $link->setFileId(42);
        $link->setMimeType('image/jpeg');

        $this->photoService->method('linkPhoto')->with('obj-uuid', 42)->willReturn($link);

        $response = $this->controller->create('reg', 'sch', 'obj-uuid');

        $this->assertSame(201, $response->getStatus());
    }//end testCreateReturns201OnSuccess()

    public function testDeleteReturns404WhenPhotoNotFound(): void
    {
        $object = $this->buildObject('obj-uuid');
        $this->objectService->method('getObject')->willReturn($object);
        $this->photoService->method('unlinkPhoto')->willReturn(false);

        $response = $this->controller->delete('reg', 'sch', 'obj-uuid', 10);

        $this->assertSame(404, $response->getStatus());
    }//end testDeleteReturns404WhenPhotoNotFound()

    public function testDeleteReturns200OnSuccess(): void
    {
        $object = $this->buildObject('obj-uuid');
        $this->objectService->method('getObject')->willReturn($object);
        $this->photoService->method('unlinkPhoto')->willReturn(true);

        $response = $this->controller->delete('reg', 'sch', 'obj-uuid', 10);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }//end testDeleteReturns200OnSuccess()

    public function testGpsStripSettingReturnsCurrentValue(): void
    {
        $this->request->method('getMethod')->willReturn('GET');
        $this->photoService->method('isGpsStripEnabled')->willReturn(false);

        $response = $this->controller->gpsStripSetting();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertFalse($response->getData()['stripGps']);
    }//end testGpsStripSettingReturnsCurrentValue()

    private function buildObject(string $uuid): ObjectEntity&MockObject
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('getUuid')->willReturn($uuid);

        return $object;
    }//end buildObject()
}//end class
