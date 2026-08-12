<?php

declare(strict_types=1);

/**
 * FileService::getFilesForEntity() Unit Tests
 *
 * Regression coverage for the object files subresource returning HTTP 500
 * (fleet detail-page audit, ISSUE A): objects whose stored `_folder` node id
 * no longer resolves — or that never had a files folder — must degrade to an
 * empty list on the read/list path instead of surfacing a 500.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Focused unit tests for FileService::getFilesForEntity().
 *
 * FileService has a 28-argument constructor; these tests only exercise the
 * object-folder read path, so the service is instantiated without invoking the
 * constructor and only the two collaborators the method touches
 * (FolderManagementHandler + LoggerInterface) are injected via reflection.
 */
class FileServiceGetFilesForEntityTest extends TestCase
{
    /** @var FolderManagementHandler&MockObject */
    private FolderManagementHandler $folderManagementHandler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private FileService $fileService;

    /**
     * Build a FileService with only the collaborators getFilesForEntity() uses.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->folderManagementHandler = $this->createMock(FolderManagementHandler::class);
        $this->logger                  = $this->createMock(LoggerInterface::class);

        $reflection        = new ReflectionClass(FileService::class);
        $this->fileService = $reflection->newInstanceWithoutConstructor();

        $this->setPrivate('folderManagementHandler', $this->folderManagementHandler);
        $this->setPrivate('logger', $this->logger);
    }//end setUp()

    /**
     * Set a private property on the FileService under test.
     *
     * @param string $name  Property name.
     * @param mixed  $value Property value.
     *
     * @return void
     */
    private function setPrivate(string $name, mixed $value): void
    {
        $property = (new ReflectionClass(FileService::class))->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($this->fileService, $value);
    }//end setPrivate()

    /**
     * Build a real ObjectEntity reporting the given uuid as its identifier.
     *
     * ObjectEntity::getId() is resolved through Nextcloud's Entity magic
     * accessor and cannot be reconfigured on a PHPUnit mock, so a concrete
     * instance is used instead.
     *
     * @param string $uuid Object uuid.
     *
     * @return ObjectEntity
     */
    private function makeObject(string $uuid): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setId(42);
        $object->setUuid($uuid);
        return $object;
    }//end makeObject()

    /**
     * An object whose folder cannot be resolved (getObjectFolder throws)
     * yields an empty list, not an exception.
     *
     * @return void
     */
    #[Test]
    public function testThrowingFolderResolutionDegradesToEmptyList(): void
    {
        $object = $this->makeObject('a9b88110-a48d-4896-823f-80da08daabca');

        $this->folderManagementHandler->method('getObjectFolder')
            ->willThrowException(new \RuntimeException('invalid folder ID 227'));

        $result = $this->fileService->getFilesForEntity(entity: $object);

        $this->assertSame([], $result);
    }//end testThrowingFolderResolutionDegradesToEmptyList()

    /**
     * An object with no resolvable folder (getObjectFolder returns null)
     * yields an empty list.
     *
     * @return void
     */
    #[Test]
    public function testNullFolderYieldsEmptyList(): void
    {
        $object = $this->makeObject('no-folder-object');

        $this->folderManagementHandler->method('getObjectFolder')->willReturn(null);

        $result = $this->fileService->getFilesForEntity(entity: $object);

        $this->assertSame([], $result);
    }//end testNullFolderYieldsEmptyList()

    /**
     * When the object folder resolves, its directory listing is returned.
     *
     * @return void
     */
    #[Test]
    public function testResolvedFolderReturnsDirectoryListing(): void
    {
        $object = $this->makeObject('object-with-files');

        $node   = $this->createMock(Node::class);
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$node]);

        $this->folderManagementHandler->method('getObjectFolder')->willReturn($folder);

        $result = $this->fileService->getFilesForEntity(entity: $object);

        $this->assertSame([$node], $result);
    }//end testResolvedFolderReturnsDirectoryListing()
}//end class
