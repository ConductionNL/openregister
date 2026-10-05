<?php

declare(strict_types=1);

/**
 * Reading an object's files never saves the object.
 *
 * The first file listing on an object that has no files folder yet makes the
 * folder and records its id. That record used to go through
 * MagicMapper::update(), which dispatches ObjectUpdatingEvent and
 * ObjectUpdatedEvent, so a plain GET ran every save-time listener as the
 * reader (dossiq case calculations were nulled by a portal listing its
 * documents). These tests drive the real handler through its read entry point,
 * `getObjectFolder()`, against a fake root with no folders, and a MagicMapper
 * double whose save methods (`update`, `updateObjectEntity`) must never run.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/specs/file-actions/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

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
 * The object folder id is recorded as bookkeeping, never through a save.
 */
class FolderManagementHandlerObjectFolderBookkeepingTest extends TestCase {

	private const REGISTER_PATH = 'Open Registers/Cases Register';

	/** @var array<string, Folder&MockObject> Every folder in the fake root, by path below the user folder. */
	private array $folders = [];

	private int $nextId = 500;

	/** @var MagicMapper&MockObject */
	private MagicMapper $objectMapper;

	private FolderManagementHandler $handler;

	protected function setUp(): void {
		$userFolder = $this->fakeFolder(path: '', id: 1);

		$systemUser = $this->createMock(IUser::class);
		$systemUser->method('getUID')->willReturn('openregister');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('openregister')->willReturn($userFolder);
		$rootFolder->method('getById')->willReturn([]);

		// A portal request: no Nextcloud session.
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getUser')->willReturn($systemUser);

		$register = new Register();
		(new ReflectionProperty($register, 'id'))->setValue($register, 7);
		$register->setTitle('Cases');
		$register->setSlug('cases');
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$recorder = $this->createMock(RegisterFolderRecorder::class);
		$recorder->method('record')->willReturn(true);

		// The save path must not run: update() dispatches the updating and
		// updated events, updateObjectEntity() the updating event.
		$this->objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['update', 'updateObjectEntity', 'recordFolder'])
			->getMock();
		$this->objectMapper->expects($this->never())->method('update');
		$this->objectMapper->expects($this->never())->method('updateObjectEntity');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(true);

		$this->handler = new FolderManagementHandler(
			rootFolder: $rootFolder,
			objectEntityMapper: $this->objectMapper,
			registerMapper: $registerMapper,
			userSession: $userSession,
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			mountCache: $this->createMock(IUserMountCache::class),
			folderRecorder: $recorder
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

				$this->folders[$full] = $this->fakeFolder(path: $full, id: $this->nextId++);

				return $this->folders[$full];
			}
		);

		return $folder;
	}//end fakeFolder()

	/**
	 * An object of register 7 with the given stored folder value.
	 *
	 * @param string $uuid The object's uuid.
	 * @param string|null $folder The stored folder value.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, ?string $folder = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('3');
		$object->setFolder($folder);

		return $object;
	}//end object()

	/**
	 * The first listing records the new folder id through recordFolder() and never through a save.
	 *
	 * @return void
	 */
	public function testTheFirstListingRecordsTheFolderWithoutSavingTheObject(): void {
		$object = $this->object(uuid: 'case-1');

		$this->objectMapper->expects($this->once())
			->method('recordFolder')
			->with($this->identicalTo($object), null, '502')
			->willReturn(true);

		$folder = $this->handler->getObjectFolder(objectEntity: $object);

		$this->assertSame($this->folders[self::REGISTER_PATH . '/case-1'], $folder);
		$this->assertSame('502', $object->getFolder());
	}//end testTheFirstListingRecordsTheFolderWithoutSavingTheObject()

	/**
	 * A legacy path value is what the compare-and-set expects to replace.
	 *
	 * @return void
	 */
	public function testALegacyPathIsTheValueTheRecordExpectsToReplace(): void {
		$object = $this->object(uuid: 'case-2', folder: 'Open Registers/Cases Register/case-2');

		$this->objectMapper->expects($this->once())
			->method('recordFolder')
			->with($this->identicalTo($object), 'Open Registers/Cases Register/case-2', '502')
			->willReturn(true);

		$this->handler->getObjectFolder(objectEntity: $object);

		$this->assertSame('502', $object->getFolder());
	}//end testALegacyPathIsTheValueTheRecordExpectsToReplace()

	/**
	 * When another request recorded a folder first, the listing still gets its folder and nothing is saved.
	 *
	 * @return void
	 */
	public function testAFolderRecordedByAnotherRequestFirstStillReturnsTheFolder(): void {
		$object = $this->object(uuid: 'case-3');

		$this->objectMapper->expects($this->once())->method('recordFolder')->willReturn(false);

		$folder = $this->handler->getObjectFolder(objectEntity: $object);

		$this->assertSame($this->folders[self::REGISTER_PATH . '/case-3'], $folder);
	}//end testAFolderRecordedByAnotherRequestFirstStillReturnsTheFolder()

	/**
	 * An object given by uuid alone has no entity to record on: nothing is written.
	 *
	 * @return void
	 */
	public function testAnObjectGivenByUuidRecordsNothing(): void {
		$this->objectMapper->expects($this->never())->method('recordFolder');

		$folder = $this->handler->getObjectFolder(objectEntity: 'case-4', registerId: 7);

		$this->assertSame($this->folders[self::REGISTER_PATH . '/case-4'], $folder);
	}//end testAnObjectGivenByUuidRecordsNothing()
}//end class
