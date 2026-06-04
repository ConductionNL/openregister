<?php

declare(strict_types=1);

/**
 * FolderManagementHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FolderManagementHandler
 *
 * Tests folder creation, retrieval, naming, and user resolution for
 * register and object entity folders.
 */
class FolderManagementHandlerTest extends TestCase
{
    /** @var FolderManagementHandler */
    private FolderManagementHandler $handler;

    /** @var IRootFolder&MockObject */
    private IRootFolder $rootFolder;

    /** @var MagicMapper&MockObject */
    private MagicMapper $objectEntityMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    /** @var IGroupManager&MockObject */
    private IGroupManager $groupManager;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var AuditTrailMapper&MockObject */
    private AuditTrailMapper $auditTrailMapper;

    /** @var IUser&MockObject */
    private IUser $mockUser;

    /** @var Folder&MockObject */
    private Folder $mockUserFolder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);

        // Common mock: user session returns a user
        $this->mockUser = $this->createMock(IUser::class);
        $this->mockUser->method('getUID')->willReturn('openregister');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Common mock: root folder returns a user folder
        $this->mockUserFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')
            ->with('openregister')
            ->willReturn($this->mockUserFolder);

        $this->handler = new FolderManagementHandler(
            $this->rootFolder,
            $this->objectEntityMapper,
            $this->registerMapper,
            $this->userSession,
            $this->groupManager,
            $this->logger,
            $this->auditTrailMapper
        );
    }

    /**
     * Helper to create a Register entity with basic properties.
     */
    private function createRegister(int $id, string $title, ?string $folder = null): Register
    {
        $register = new Register();
        $reflection = new \ReflectionProperty($register, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($register, $id);
        $register->setTitle($title);
        $register->setSlug('test-register');
        if ($folder !== null) {
            $register->setFolder($folder);
        }
        return $register;
    }

    /**
     * Helper to create an ObjectEntity with basic properties.
     */
    private function createObjectEntity(int $id, string $uuid, ?string $folder = null, ?int $registerId = null): ObjectEntity
    {
        $object = new ObjectEntity();
        $reflection = new \ReflectionProperty($object, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($object, $id);
        $object->setUuid($uuid);
        if ($folder !== null) {
            $object->setFolder($folder);
        }
        if ($registerId !== null) {
            $object->setRegister($registerId);
        }
        return $object;
    }

    // =========================================================================
    // getRegisterFolderName tests
    // =========================================================================

    #[Test]
    public function testGetRegisterFolderNameAppendsSuffix(): void
    {
        $register = $this->createRegister(1, 'Persons');
        $result = $this->handler->getRegisterFolderName($register);
        $this->assertEquals('Persons Register', $result);
    }

    #[Test]
    public function testGetRegisterFolderNameDoesNotDuplicateSuffix(): void
    {
        $register = $this->createRegister(1, 'Persons Register');
        $result = $this->handler->getRegisterFolderName($register);
        $this->assertEquals('Persons Register', $result);
    }

    #[Test]
    public function testGetRegisterFolderNameHandlesCaseInsensitive(): void
    {
        $register = $this->createRegister(1, 'My register');
        $result = $this->handler->getRegisterFolderName($register);
        $this->assertEquals('My register', $result);
    }

    #[Test]
    public function testGetRegisterFolderNameWithNullTitle(): void
    {
        $register = new Register();
        $result = $this->handler->getRegisterFolderName($register);
        $this->assertEquals(' Register', $result);
    }

    // =========================================================================
    // getObjectFolderName tests
    // =========================================================================

    #[Test]
    public function testGetObjectFolderNameReturnsUuid(): void
    {
        $object = $this->createObjectEntity(1, 'abc-123');
        $result = $this->handler->getObjectFolderName($object);
        $this->assertEquals('abc-123', $result);
    }

    #[Test]
    public function testGetObjectFolderNameReturnsIdWhenNoUuid(): void
    {
        $object = new ObjectEntity();
        $reflection = new \ReflectionProperty($object, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($object, 42);

        $result = $this->handler->getObjectFolderName($object);
        $this->assertEquals('42', $result);
    }

    #[Test]
    public function testGetObjectFolderNameReturnsStringWhenStringInput(): void
    {
        $result = $this->handler->getObjectFolderName('my-uuid-string');
        $this->assertEquals('my-uuid-string', $result);
    }

    // =========================================================================
    // getNodeTypeFromFolder tests
    // =========================================================================

    #[Test]
    public function testGetNodeTypeFromFolderReturnsFolder(): void
    {
        $folderNode = $this->createMock(Folder::class);
        $result = $this->handler->getNodeTypeFromFolder($folderNode);
        $this->assertEquals('folder', $result);
    }

    #[Test]
    public function testGetNodeTypeFromFolderReturnsFile(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $result = $this->handler->getNodeTypeFromFolder($fileNode);
        $this->assertEquals('file', $result);
    }

    #[Test]
    public function testGetNodeTypeFromFolderReturnsUnknown(): void
    {
        $node = $this->createMock(Node::class);
        $result = $this->handler->getNodeTypeFromFolder($node);
        $this->assertEquals('unknown', $result);
    }

    // =========================================================================
    // getOpenRegisterUserFolder tests
    // =========================================================================

    #[Test]
    public function testGetOpenRegisterUserFolderReturnsFolder(): void
    {
        $result = $this->handler->getOpenRegisterUserFolder();
        $this->assertSame($this->mockUserFolder, $result);
    }

    #[Test]
    public function testGetOpenRegisterUserFolderThrowsWhenNoUser(): void
    {
        // Override: no user in session
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $handler = new FolderManagementHandler(
            $this->rootFolder,
            $this->objectEntityMapper,
            $this->registerMapper,
            $userSession,
            $this->groupManager,
            $this->logger,
            $this->auditTrailMapper
        );

        $this->expectException(Exception::class);
        $handler->getOpenRegisterUserFolder();
    }

    // =========================================================================
    // getNodeById tests
    // =========================================================================

    #[Test]
    public function testGetNodeByIdReturnsNodeFromUserFolder(): void
    {
        $mockNode = $this->createMock(Node::class);
        $this->mockUserFolder->method('getById')
            ->with(42)
            ->willReturn([$mockNode]);

        $result = $this->handler->getNodeById(42);
        $this->assertSame($mockNode, $result);
    }

    #[Test]
    public function testGetNodeByIdFallsBackToRootFolder(): void
    {
        $this->mockUserFolder->method('getById')
            ->with(42)
            ->willReturn([]);

        $mockNode = $this->createMock(Node::class);
        $this->rootFolder->method('getById')
            ->with(42)
            ->willReturn([$mockNode]);

        $result = $this->handler->getNodeById(42);
        $this->assertSame($mockNode, $result);
    }

    #[Test]
    public function testGetNodeByIdReturnsNullWhenNotFound(): void
    {
        $this->mockUserFolder->method('getById')
            ->willReturn([]);

        $this->rootFolder->method('getById')
            ->willReturn([]);

        $result = $this->handler->getNodeById(999);
        $this->assertNull($result);
    }

    #[Test]
    public function testGetNodeByIdReturnsNullOnExceptions(): void
    {
        $this->mockUserFolder->method('getById')
            ->willThrowException(new Exception('User folder error'));

        $this->rootFolder->method('getById')
            ->willThrowException(new Exception('Root folder error'));

        $result = $this->handler->getNodeById(999);
        $this->assertNull($result);
    }

    // =========================================================================
    // createEntityFolder tests
    // =========================================================================

    #[Test]
    public function testCreateEntityFolderWithRegisterDelegatesToCreateRegisterFolder(): void
    {
        $register = $this->createRegister(1, 'Test Reg');

        $mockFolder = $this->createMock(Folder::class);
        $mockFolder->method('getId')->willReturn(100);

        // The register has no existing folder, so it will create one
        $this->mockUserFolder->method('get')
            ->willThrowException(new NotFoundException());
        $this->mockUserFolder->method('newFolder')
            ->willReturn($mockFolder);

        $this->groupManager->method('groupExists')->willReturn(true);
        $this->registerMapper->expects($this->once())
            ->method('update');

        $result = $this->handler->createEntityFolder($register);
        $this->assertNotNull($result);
    }

    #[Test]
    public function testCreateEntityFolderReturnsNullOnException(): void
    {
        $register = $this->createRegister(1, 'Bad Reg');

        // Force an exception during folder creation
        $this->mockUserFolder->method('get')
            ->willThrowException(new Exception('Disk full'));
        $this->mockUserFolder->method('newFolder')
            ->willThrowException(new Exception('Disk full'));

        $result = $this->handler->createEntityFolder($register);
        $this->assertNull($result);
    }

    // =========================================================================
    // getRegisterFolderById tests
    // =========================================================================

    #[Test]
    public function testGetRegisterFolderByIdReturnsExistingFolder(): void
    {
        $register = $this->createRegister(1, 'My Reg', '100');

        $mockFolder = $this->createMock(Folder::class);
        $this->mockUserFolder->method('getById')
            ->with(100)
            ->willReturn([$mockFolder]);

        $result = $this->handler->getRegisterFolderById($register);
        $this->assertSame($mockFolder, $result);
    }

    #[Test]
    public function testGetRegisterFolderByIdCreatesNewWhenEmpty(): void
    {
        $register = $this->createRegister(1, 'My Reg', '');

        $mockFolder = $this->createMock(Folder::class);
        $mockFolder->method('getId')->willReturn(200);

        // Will try to create folder
        $this->mockUserFolder->method('get')
            ->willReturnCallback(function ($path) use ($mockFolder) {
                if ($path === 'Open Registers') {
                    return $mockFolder;
                }
                throw new NotFoundException();
            });
        $this->mockUserFolder->method('newFolder')
            ->willReturn($mockFolder);

        $this->registerMapper->expects($this->once())
            ->method('update');

        $result = $this->handler->getRegisterFolderById($register);
        $this->assertNotNull($result);
    }

    #[Test]
    public function testGetRegisterFolderByIdCreatesNewWhenNonNumeric(): void
    {
        $register = $this->createRegister(1, 'My Reg', '/legacy/path/folder');

        $mockFolder = $this->createMock(Folder::class);
        $mockFolder->method('getId')->willReturn(300);

        $this->mockUserFolder->method('get')
            ->willReturnCallback(function ($path) use ($mockFolder) {
                if ($path === 'Open Registers') {
                    return $mockFolder;
                }
                throw new NotFoundException();
            });
        $this->mockUserFolder->method('newFolder')
            ->willReturn($mockFolder);

        $this->registerMapper->expects($this->once())
            ->method('update');

        $result = $this->handler->getRegisterFolderById($register);
        $this->assertNotNull($result);
    }

    // =========================================================================
    // createFolder / createFolderPath tests
    // =========================================================================

    #[Test]
    public function testCreateFolderReturnsExistingFolder(): void
    {
        $mockFolder = $this->createMock(Folder::class);

        // Root folder exists
        $this->mockUserFolder->method('get')
            ->willReturn($mockFolder);

        $result = $this->handler->createFolder('some/path');
        $this->assertSame($mockFolder, $result);
    }

    // =========================================================================
    // setFileService tests
    // =========================================================================

    #[Test]
    public function testSetFileServiceDoesNotThrow(): void
    {
        $fileService = $this->createMock(FileService::class);
        $this->handler->setFileService($fileService);
        $this->assertTrue(true);
    }

    // =========================================================================
    // DataProvider: folder name variations
    // =========================================================================

    /**
     * @return array<string, array{string, string}>
     */
    public static function registerFolderNameProvider(): array
    {
        return [
            'plain name' => ['Animals', 'Animals Register'],
            'already has register' => ['Animals Register', 'Animals Register'],
            'lowercase register' => ['animals register', 'animals register'],
            'has Register mid-word' => ['Registers Of Things', 'Registers Of Things Register'],
        ];
    }

    #[Test]
    #[DataProvider('registerFolderNameProvider')]
    public function testGetRegisterFolderNameVariations(string $title, string $expected): void
    {
        $register = $this->createRegister(1, $title);
        $result = $this->handler->getRegisterFolderName($register);
        $this->assertEquals($expected, $result);
    }
}
