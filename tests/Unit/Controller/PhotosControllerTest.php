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

namespace Tests\Unit\Controller;

use OCA\OpenRegister\Controller\PhotosController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PhotoService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\IRequest;
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

    /**
     * Set up mocks and controller under test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(IRequest::class);
        $this->photoService  = $this->createMock(PhotoService::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->controller = new PhotosController(
            appName: 'openregister',
            request: $this->request,
            photoService: $this->photoService,
            objectService: $this->objectService,
            userSession: $this->userSession,
            logger: $this->logger
        );
    }//end setUp()

    /**
     * Test index returns 404 when object does not exist.
     */
    public function testIndexReturns404WhenObjectNotFound(): void
    {
        $this->objectService->method('getObject')->willReturn(null);

        $result = $this->controller->index(register: 'reg', schema: 'sch', id: 'uuid-123');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }//end testIndexReturns404WhenObjectNotFound()

    /**
     * Test index returns list of photos when object exists.
     */
    public function testIndexReturnsPhotoList(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->objectService->method('getObject')->willReturn($object);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(10);
        $file->method('getName')->willReturn('test.jpg');
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(512);
        $file->method('getMTime')->willReturn(1717600000);
        $file->method('getEtag')->willReturn('etag1');

        $this->photoService->method('getPhotos')->willReturn([$file]);
        $this->photoService->method('formatPhoto')->willReturn([
            'id' => 10, 'name' => 'test.jpg', 'mimeType' => 'image/jpeg',
            'size' => 512, 'mtime' => 1717600000, 'etag' => 'etag1', 'exif' => null,
        ]);

        $result = $this->controller->index(register: 'reg', schema: 'sch', id: 'uuid-123');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_OK, $result->getStatus());

        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(1, $data['results']);
        $this->assertSame(1, $data['count']);
    }//end testIndexReturnsPhotoList()

    /**
     * Test show returns 404 when photo is not found.
     */
    public function testShowReturns404WhenPhotoNotFound(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->objectService->method('getObject')->willReturn($object);
        $this->photoService->method('getPhotoWithExif')->willReturn(null);

        $result = $this->controller->show(
            register: 'reg',
            schema: 'sch',
            id: 'uuid-123',
            fileId: 999
        );

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }//end testShowReturns404WhenPhotoNotFound()

    /**
     * Test show returns photo with EXIF when found.
     */
    public function testShowReturnsPhotoWithExif(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->objectService->method('getObject')->willReturn($object);

        $photoData = [
            'id'       => 10,
            'name'     => 'photo.jpg',
            'mimeType' => 'image/jpeg',
            'size'     => 1024,
            'mtime'    => 1717600000,
            'etag'     => 'abc',
            'exif'     => ['Make' => 'Canon', 'DateTime' => '2026:06:05 12:00:00'],
        ];

        $this->photoService->method('getPhotoWithExif')->willReturn($photoData);

        $result = $this->controller->show(
            register: 'reg',
            schema: 'sch',
            id: 'uuid-123',
            fileId: 10
        );

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_OK, $result->getStatus());

        $data = $result->getData();
        $this->assertSame(10, $data['id']);
        $this->assertArrayHasKey('exif', $data);
        $this->assertSame('Canon', $data['exif']['Make']);
    }//end testShowReturnsPhotoWithExif()
}//end class
