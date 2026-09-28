<?php

declare(strict_types=1);

/**
 * The first upload into a register on a fresh instance (portaliq#29).
 *
 * The root here is a fake with no folders at all: `get()` finds only what an
 * earlier `newFolder()` made, and `newFolder()` refuses a path that exists, as
 * Nextcloud does. The request has no session, so file operations run as the
 * OpenRegister system user, and the register mapper refuses `update()` the way
 * it does for that request ("Access denied: You do not have permission to
 * update register entities."). Before this change that refusal failed every
 * first upload; the tests below walk the real upload entry point,
 * `getObjectFolder()`, and assert the folder is made and recorded without it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/changes/register-folder-on-first-upload/specs/file-actions/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * First upload into a register whose folder does not exist yet.
 */
class FolderManagementHandlerFirstUploadTest extends TestCase {

	private const REGISTER_PATH = 'Open Registers/Portal Register';

	/** @var array<string, Folder&MockObject> Every folder in the fake root, by path below the user folder. */
	private array $folders = [];

	/** @var list<string> Paths newFolder() created, in order. */
	private array $created = [];

	private int $nextId = 500;

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	/** @var RegisterFolderRecorder&MockObject */
	private RegisterFolderRecorder $recorder;

	private FolderManagementHandler $handler;

	private Register $register;

	protected function setUp(): void {
		$userFolder = $this->fakeFolder(path: '', id: 1);

		$systemUser = $this->createMock(IUser::class);
		$systemUser->method('getUID')->willReturn('openregister');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('openregister')->willReturn($userFolder);
		$rootFolder->method('getById')->willReturn([]);

		// No Nextcloud session: the portal's request.
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getUser')->willReturn($systemUser);

		$this->register = new Register();
		(new ReflectionProperty($this->register, 'id'))->setValue($this->register, 7);
		$this->register->setTitle('Portal');
		$this->register->setSlug('portal');

		// The mapper answers as it does for a session-less request: it finds the
		// register, and it refuses to update it.
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->registerMapper->method('find')->willReturn($this->register);
		$this->registerMapper->expects($this->never())
			->method('update')
			->willThrowException(new Exception('Access denied: You do not have permission to update register entities.', 403));

		$this->recorder = $this->createMock(RegisterFolderRecorder::class);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(true);

		$this->handler = new FolderManagementHandler(
			rootFolder: $rootFolder,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			registerMapper: $this->registerMapper,
			userSession: $userSession,
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			mountCache: $this->createMock(IUserMountCache::class),
			folderRecorder: $this->recorder
		);
		$this->handler->setFileService($fileService);
	}//end setUp()

	/**
	 * A folder in the fake root: get() finds only what exists, newFolder() refuses what exists.
	 *
	 * @param string $path The folder's path below the user folder, '' for the user folder.
	 * @param int $id The folder's node id.
	 *
	 * @return Folder&MockObject
	 */
	private function fakeFolder(string $path, int $id): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('get')->willReturnCallback(
			function (string $child) use ($path): Folder {
				$full = ltrim($path . '/' . $child, '/');
				if (isset($this->folders[$full]) === false) {
					throw new NotFoundException($full);
				}

				return $this->folders[$full];
			}
		);
		$folder->method('newFolder')->willReturnCallback(
			function (string $child) use ($path): Folder {
				$full = ltrim($path . '/' . $child, '/');
				if (isset($this->folders[$full]) === true) {
					throw new NotPermittedException('Could not create folder "' . $full . '"');
				}

				$this->created[] = $full;
				$this->folders[$full] = $this->fakeFolder(path: $full, id: $this->nextId++);

				return $this->folders[$full];
			}
		);
		$folder->method('getById')->willReturnCallback(
			function (int $nodeId): array {
				foreach ($this->folders as $candidate) {
					if ($candidate->getId() === $nodeId) {
						return [$candidate];
					}
				}

				return [];
			}
		);

		return $folder;
	}//end fakeFolder()

	/**
	 * An object of the register, with no folder of its own yet.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');

		return $object;
	}//end object()

	/**
	 * The first upload makes the register folder in the system user's files and records its id without update().
	 *
	 * @return void
	 */
	public function testTheFirstUploadWithoutASessionCreatesAndRecordsTheRegisterFolder(): void {
		$this->recorder->expects($this->once())
			->method('record')
			->with(7, null, '501')
			->willReturn(true);

		$folder = $this->handler->getObjectFolder(objectEntity: $this->object(uuid: 'object-1'));

		$this->assertInstanceOf(Folder::class, $folder);
		$this->assertSame(['Open Registers', self::REGISTER_PATH, self::REGISTER_PATH . '/object-1'], $this->created);
		$this->assertSame('501', $this->register->getFolder());
	}//end testTheFirstUploadWithoutASessionCreatesAndRecordsTheRegisterFolder()

	/**
	 * A second upload into the register reuses the recorded folder: no new register folder, no second record.
	 *
	 * @return void
	 */
	public function testASecondUploadReusesTheRecordedFolder(): void {
		$this->recorder->expects($this->once())->method('record')->willReturn(true);

		$this->handler->getObjectFolder(objectEntity: $this->object(uuid: 'object-1'));
		$this->handler->getObjectFolder(objectEntity: $this->object(uuid: 'object-2'));

		$this->assertSame(
			['Open Registers', self::REGISTER_PATH, self::REGISTER_PATH . '/object-1', self::REGISTER_PATH . '/object-2'],
			$this->created
		);
	}//end testASecondUploadReusesTheRecordedFolder()

	/**
	 * When another request recorded a folder first, the compare-and-set declines and the upload still gets its folder.
	 *
	 * @return void
	 */
	public function testAFolderRecordedByAnotherRequestFirstIsLeftAloneAndTheUploadProceeds(): void {
		$this->recorder->expects($this->once())->method('record')->willReturn(false);

		$folder = $this->handler->getObjectFolder(objectEntity: $this->object(uuid: 'object-1'));

		$this->assertInstanceOf(Folder::class, $folder);
		$this->assertContains(self::REGISTER_PATH . '/object-1', $this->created);
	}//end testAFolderRecordedByAnotherRequestFirstIsLeftAloneAndTheUploadProceeds()

	/**
	 * Two first uploads racing: the other one creates the folder between this one's lookup and its creation.
	 *
	 * @return void
	 */
	public function testTwoFirstUploadsRacingShareOneFolder(): void {
		$userFolder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$existing = $this->createMock(Folder::class);
		$existing->method('getId')->willReturn(777);
		$lookups = 0;
		$userFolder->method('get')->willReturnCallback(
			function (string $path) use ($existing, &$lookups): Folder {
				if ($path === 'Open Registers') {
					return $existing;
				}

				// The first lookup misses; by the second the other upload has made the folder.
				$lookups++;
				if ($lookups === 1) {
					throw new NotFoundException($path);
				}

				return $existing;
			}
		);
		$userFolder->method('newFolder')->willThrowException(new NotPermittedException('Could not create folder'));

		$handler = new FolderManagementHandler(
			rootFolder: $rootFolder,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			registerMapper: $this->registerMapper,
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			mountCache: $this->createMock(IUserMountCache::class),
			folderRecorder: $this->recorder
		);
		$fileService = $this->createMock(FileService::class);
		$systemUser = $this->createMock(IUser::class);
		$systemUser->method('getUID')->willReturn('openregister');
		$fileService->method('getUser')->willReturn($systemUser);
		$fileService->expects($this->never())->method('transferFolderOwnershipIfNeeded');
		$handler->setFileService($fileService);

		$this->assertSame($existing, $handler->createFolderPath(folderPath: self::REGISTER_PATH));
		$this->assertSame(2, $lookups);
	}//end testTwoFirstUploadsRacingShareOneFolder()

	/**
	 * A folder that cannot be created and does not exist still fails loudly, as before.
	 *
	 * @return void
	 */
	public function testAFolderThatCannotBeCreatedAndDoesNotExistStillFails(): void {
		$userFolder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$userFolder->method('get')->willThrowException(new NotFoundException('missing'));
		$userFolder->method('newFolder')->willThrowException(new NotPermittedException('read-only storage'));

		$handler = new FolderManagementHandler(
			rootFolder: $rootFolder,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			registerMapper: $this->registerMapper,
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			mountCache: $this->createMock(IUserMountCache::class),
			folderRecorder: $this->recorder
		);
		$fileService = $this->createMock(FileService::class);
		$systemUser = $this->createMock(IUser::class);
		$systemUser->method('getUID')->willReturn('openregister');
		$fileService->method('getUser')->willReturn($systemUser);
		$handler->setFileService($fileService);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Can't create folder " . self::REGISTER_PATH);

		$handler->createFolderPath(folderPath: self::REGISTER_PATH);
	}//end testAFolderThatCannotBeCreatedAndDoesNotExistStillFails()
}//end class
