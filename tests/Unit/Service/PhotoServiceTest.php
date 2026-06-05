<?php

/**
 * Unit tests for PhotoService.
 *
 * @category Test
 * @package  Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-6
 */

declare(strict_types=1);

namespace Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Integration\PhotosProvider;
use OCA\OpenRegister\Service\PhotoService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test suite for PhotoService.
 */
class PhotoServiceTest extends TestCase
{

    private PhotoService $service;

    private FileService&MockObject $fileService;

    private IDBConnection&MockObject $db;

    private IConfig&MockObject $config;

    private LoggerInterface&MockObject $logger;

    /**
     * Set up mocks and service under test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fileService = $this->createMock(FileService::class);
        $this->db          = $this->createMock(IDBConnection::class);
        $this->config      = $this->createMock(IConfig::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->service = new PhotoService(
            fileService: $this->fileService,
            db: $this->db,
            config: $this->config,
            logger: $this->logger
        );
    }//end setUp()

    /**
     * Test that isImageMime returns true for recognised image types.
     */
    public function testIsImageMimeReturnsTrueForJpeg(): void
    {
        $this->assertTrue($this->service->isImageMime(mimeType: 'image/jpeg'));
    }//end testIsImageMimeReturnsTrueForJpeg()

    /**
     * Test that isImageMime returns true for PNG.
     */
    public function testIsImageMimeReturnsTrueForPng(): void
    {
        $this->assertTrue($this->service->isImageMime(mimeType: 'image/png'));
    }//end testIsImageMimeReturnsTrueForPng()

    /**
     * Test that isImageMime returns false for non-image MIME types.
     */
    public function testIsImageMimeReturnsFalseForPdf(): void
    {
        $this->assertFalse($this->service->isImageMime(mimeType: 'application/pdf'));
    }//end testIsImageMimeReturnsFalseForPdf()

    /**
     * Test that isImageMime returns false for text files.
     */
    public function testIsImageMimeReturnsFalseForText(): void
    {
        $this->assertFalse($this->service->isImageMime(mimeType: 'text/plain'));
    }//end testIsImageMimeReturnsFalseForText()

    /**
     * Test that stripGpsFromExif removes GPS keys.
     */
    public function testStripGpsFromExifRemovesGpsKeys(): void
    {
        $exif = [
            'Make'         => 'Canon',
            'GPSLatitude'  => [50, 30, 0],
            'GPSLongitude' => [4, 20, 0],
            'DateTime'     => '2026:06:05 12:00:00',
        ];

        $result = $this->service->stripGpsFromExif(exif: $exif);

        $this->assertArrayHasKey('Make', $result);
        $this->assertArrayHasKey('DateTime', $result);
        $this->assertArrayNotHasKey('GPSLatitude', $result);
        $this->assertArrayNotHasKey('GPSLongitude', $result);
    }//end testStripGpsFromExifRemovesGpsKeys()

    /**
     * Test that formatPhoto returns the expected array structure.
     */
    public function testFormatPhotoReturnsExpectedShape(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(42);
        $file->method('getName')->willReturn('photo.jpg');
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(1024);
        $file->method('getMTime')->willReturn(1717600000);
        $file->method('getEtag')->willReturn('abc123');

        $result = $this->service->formatPhoto(file: $file, exif: ['Make' => 'Canon']);

        $this->assertSame(42, $result['id']);
        $this->assertSame('photo.jpg', $result['name']);
        $this->assertSame('image/jpeg', $result['mimeType']);
        $this->assertSame(['Make' => 'Canon'], $result['exif']);
    }//end testFormatPhotoReturnsExpectedShape()

    /**
     * Test that getPhotos returns only image files from the folder.
     */
    public function testGetPhotosFiltersToImages(): void
    {
        $imageFile = $this->createMock(File::class);
        $imageFile->method('getMimeType')->willReturn('image/jpeg');

        $docFile = $this->createMock(File::class);
        $docFile->method('getMimeType')->willReturn('application/pdf');

        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$imageFile, $docFile]);

        $object = $this->createMock(ObjectEntity::class);

        $this->fileService->method('getObjectFolder')->willReturn($folder);

        $result = $this->service->getPhotos(object: $object);

        $this->assertCount(1, $result);
        $this->assertSame($imageFile, $result[0]);
    }//end testGetPhotosFiltersToImages()

    /**
     * Test that getPhotos returns empty array when folder is null.
     */
    public function testGetPhotosReturnsEmptyArrayWhenNoFolder(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->fileService->method('getObjectFolder')->willReturn(null);

        $result = $this->service->getPhotos(object: $object);

        $this->assertSame([], $result);
    }//end testGetPhotosReturnsEmptyArrayWhenNoFolder()

    /**
     * Test that isGpsStripEnabled reflects the config value.
     */
    public function testIsGpsStripEnabledReturnsTrueWhenConfigSet(): void
    {
        $this->config
            ->method('getAppValue')
            ->willReturn('true');

        $this->assertTrue($this->service->isGpsStripEnabled());
    }//end testIsGpsStripEnabledReturnsTrueWhenConfigSet()

    /**
     * Test that PhotosProvider exposes the correct integration metadata.
     */
    public function testPhotosProviderMetadata(): void
    {
        $provider = new PhotosProvider();

        $this->assertSame('photos', $provider->getId());
        $this->assertSame('Photos', $provider->getLabel());
        $this->assertSame('Image', $provider->getIcon());
        $this->assertSame('docs', $provider->getGroup());
        $this->assertSame('photos', $provider->getRequiredApp());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertNull($provider->requiresPermission());
    }//end testPhotosProviderMetadata()
}//end class
