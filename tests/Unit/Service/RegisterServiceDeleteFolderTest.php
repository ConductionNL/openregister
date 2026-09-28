<?php

declare(strict_types=1);

/**
 * Deleting a register removes its folder (openregister#4107).
 *
 * Before this change `RegisterService::delete()` removed only the row. The
 * register's folder under "Open Registers" stayed with every file in it, and a
 * register created later with the same title was handed that folder, because
 * `createFolderPath()` returns an existing folder at the path "<title> Register".
 *
 * The walk here is the real one below the service: the real `FileService`
 * facade and the real `FolderManagementHandler`, over a Nextcloud root that
 * holds the register's folder. Only the mapper, the root and the recorder are
 * doubles, each with its real method names.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/specs/file-actions/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * A register delete and the register's folder.
 */
class RegisterServiceDeleteFolderTest extends TestCase {

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	/** @var IRootFolder&MockObject */
	private IRootFolder $rootFolder;

	/** @var RegisterFolderRecorder&MockObject */
	private RegisterFolderRecorder $recorder;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private RegisterService $service;

	private Register $register;

	/** Whether the mapper refuses the delete, as it does while objects are attached. */
	private bool $refuseDelete = false;

	protected function setUp(): void {
		$this->register = new Register();
		(new ReflectionProperty($this->register, 'id'))->setValue($this->register, 7);
		$this->register->setTitle('Test');
		$this->register->setSlug('test');
		$this->register->setFolder('501');

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->registerMapper->method('delete')->willReturnCallback(
			function (Register $register): Register {
				if ($this->refuseDelete === true) {
					throw new ValidationException(message: 'Cannot delete register: objects are still attached.');
				}

				return $register;
			}
		);

		// The admin deleting the register: the folder is owned by the OpenRegister
		// user, so the admin's own files do not hold it and the root lookup does.
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($admin);

		$adminFolder = $this->createMock(Folder::class);
		$adminFolder->method('getById')->willReturn([]);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($adminFolder);

		$this->recorder = $this->createMock(RegisterFolderRecorder::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$handler = new FolderManagementHandler(
			rootFolder: $this->rootFolder,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			registerMapper: $this->registerMapper,
			userSession: $userSession,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->logger,
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			mountCache: $this->createMock(IUserMountCache::class),
			folderRecorder: $this->recorder
		);

		$fileService = (new ReflectionClass(FileService::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(FileService::class, 'folderManagementHandler'))->setValue($fileService, $handler);
		(new ReflectionProperty(FileService::class, 'logger'))->setValue($fileService, $this->logger);
		$handler->setFileService($fileService);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$this->service = new RegisterService(
			registerMapper: $this->registerMapper,
			schemaMapper: $schemaMapper,
			db: $this->createMock(IDBConnection::class),
			fileService: $fileService,
			organisationService: $this->createMock(OrganisationService::class),
			logger: $this->logger,
			registerSerializer: new RegisterSerializer($schemaMapper, $this->logger)
		);
	}//end setUp()

	/**
	 * A folder in the Nextcloud root, found by its id.
	 *
	 * @param int $id The folder's node id.
	 * @param string $path The folder's node path, "/<uid>/files/<path in the home>".
	 *
	 * @return Folder&MockObject
	 */
	private function folderInTheRoot(int $id, string $path): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn($path);
		$this->rootFolder->method('getById')->willReturnCallback(
			static fn (int $nodeId): array => $nodeId === $id ? [$folder] : []
		);

		return $folder;
	}//end folderInTheRoot()

	/**
	 * Deleting a register removes the folder it recorded, so a later register of that title starts empty.
	 *
	 * @return void
	 */
	public function testDeletingARegisterRemovesItsFolder(): void {
		$folder = $this->folderInTheRoot(id: 501, path: '/openregister/files/Open Registers/Test Register');
		$folder->expects($this->once())->method('delete');

		$this->assertSame($this->register, $this->service->delete($this->register));
	}//end testDeletingARegisterRemovesItsFolder()

	/**
	 * A folder another register still records stays: two registers of one title are handed one folder.
	 *
	 * @return void
	 */
	public function testAFolderAnotherRegisterStillRecordsIsKept(): void {
		$folder = $this->folderInTheRoot(id: 501, path: '/openregister/files/Open Registers/Test Register');
		$folder->expects($this->never())->method('delete');
		$this->recorder->expects($this->once())
			->method('isRecordedByAnotherRegister')
			->with('501', 7)
			->willReturn(true);

		$this->service->delete($this->register);
	}//end testAFolderAnotherRegisterStillRecordsIsKept()

	/**
	 * Folders that are not a register's: the root, an object's folder, and a user's own folder.
	 *
	 * @return array<string, array{string}>
	 */
	public static function foldersThatAreNotARegisterFolder(): array {
		return [
			'the Open Registers root' => ['/openregister/files/Open Registers'],
			'an object folder' => ['/openregister/files/Open Registers/Other Register/0b8e9f1c-object'],
			'a user folder outside the tree' => ['/alice/files/Documents'],
		];
	}//end foldersThatAreNotARegisterFolder()

	/**
	 * Only a folder directly below "Open Registers" is removed as a register's folder.
	 *
	 * @param string $path The node path the register's folder id resolves to.
	 *
	 * @return void
	 */
	#[DataProvider('foldersThatAreNotARegisterFolder')]
	public function testAFolderThatIsNotARegisterFolderIsKept(string $path): void {
		$folder = $this->folderInTheRoot(id: 501, path: $path);
		$folder->expects($this->never())->method('delete');

		$this->assertSame($this->register, $this->service->delete($this->register));
	}//end testAFolderThatIsNotARegisterFolderIsKept()

	/**
	 * A register that never recorded a folder id removes nothing, and nothing is looked up by path.
	 *
	 * @return void
	 */
	public function testARegisterWithoutARecordedFolderRemovesNothing(): void {
		$this->register->setFolder(null);
		$this->rootFolder->expects($this->never())->method('getById');
		$this->rootFolder->expects($this->never())->method('get');

		$this->assertSame($this->register, $this->service->delete($this->register));
	}//end testARegisterWithoutARecordedFolderRemovesNothing()

	/**
	 * A delete the mapper refuses (objects still attached) leaves the folder where it is.
	 *
	 * @return void
	 */
	public function testARefusedDeleteKeepsTheFolder(): void {
		$this->refuseDelete = true;

		$folder = $this->folderInTheRoot(id: 501, path: '/openregister/files/Open Registers/Test Register');
		$folder->expects($this->never())->method('delete');

		$this->expectException(ValidationException::class);
		$this->service->delete($this->register);
	}//end testARefusedDeleteKeepsTheFolder()

	/**
	 * A folder Nextcloud will not delete is logged; the register delete itself still succeeds.
	 *
	 * @return void
	 */
	public function testAFolderThatCannotBeRemovedDoesNotFailTheDelete(): void {
		$folder = $this->folderInTheRoot(id: 501, path: '/openregister/files/Open Registers/Test Register');
		$folder->method('delete')->willThrowException(new NotPermittedException('read-only storage'));
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame($this->register, $this->service->delete($this->register));
	}//end testAFolderThatCannotBeRemovedDoesNotFailTheDelete()
}//end class
