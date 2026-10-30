<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileAuditHandler;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\File\FileReadScope;
use OCA\OpenRegister\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\ObjectFolderBrowser;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A reader of the object who is not admitted to the restricted file gets it from no path; an admitted one from every path.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
 */
class FileRestrictionPathsTest extends TestCase {

	private const RESTRICTED = 42;

	private const OPEN = 43;

	private const OBJECT_UUID = 'c0ffee00-0000-4000-8000-000000000001';

	private File&MockObject $restricted;

	private File&MockObject $open;

	private ObjectEntity $object;

	private FileService&MockObject $fileService;

	protected function setUp(): void {
		parent::setUp();
		$this->restricted = $this->fileNode(id: self::RESTRICTED, name: 'Offerte.pdf');
		$this->open = $this->fileNode(id: self::OPEN, name: 'Brief.pdf');

		$this->object = new ObjectEntity();
		$this->object->setUuid(self::OBJECT_UUID);
		$this->object->setRegister('7');
		$this->object->setSchema('42');

		$this->fileService = $this->createMock(FileService::class);
		$this->fileService->method('getAuditHandler')->willReturn($this->createMock(FileAuditHandler::class));
		$this->fileService->method('formatFiles')->willReturnCallback(
			static fn (array $files): array => ['results' => $files, 'total' => count($files)]
		);
	}//end setUp()

	/**
	 * A file node.
	 *
	 * @param int    $id   The id.
	 * @param string $name The name.
	 *
	 * @return File&MockObject
	 */
	private function fileNode(int $id, string $name): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/openregister/files/Open Registers/R/' . self::OBJECT_UUID . '/' . $name);
		$file->method('getMimetype')->willReturn('application/pdf');

		return $file;
	}//end fileNode()

	/**
	 * A guard that admits everything, or everything but the restricted file.
	 *
	 * @param bool $admitted Whether the caller is admitted to the restricted file.
	 *
	 * @return FileRestrictionGuard&MockObject
	 */
	private function guard(bool $admitted): FileRestrictionGuard&MockObject {
		$guard = $this->createMock(FileRestrictionGuard::class);
		$guard->method('mayRead')->willReturnCallback(
			static fn (int $fileId): bool => $admitted === true || $fileId !== self::RESTRICTED
		);
		$guard->method('readableNodes')->willReturnCallback(
			static fn (array $nodes): array => array_values(
				array_filter($nodes, static fn ($node): bool => $admitted === true || $node->getId() !== self::RESTRICTED)
			)
		);

		return $guard;
	}//end guard()

	/**
	 * A files controller for a signed-in reader of the object.
	 *
	 * @param bool $admitted Whether the reader is admitted to the restricted file.
	 *
	 * @return FilesController
	 */
	private function controller(bool $admitted): FilesController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('setObject')->willReturnSelf();
		$objectService->method('getObject')->willReturn($this->object);

		$access = $this->createMock(ObjectFileAccess::class);
		$access->method('readable')->willReturn($this->object);
		$access->method('changeable')->willReturn($this->object);
		$access->method('mayRead')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new FilesController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->fileService,
			$objectService,
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IEventDispatcher::class),
			null,
			$this->createMock(FileAuditHandler::class),
			$session,
			$l10n,
			$access,
			null,
			null,
			null,
			$this->guard(admitted: $admitted)
		);
	}//end controller()

	public function testTheObjectFolderListingLeavesTheRestrictedFileOut(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/openregister/files/Open Registers/R/' . self::OBJECT_UUID);
		$root->method('getId')->willReturn(7);
		$root->method('getDirectoryListing')->willReturn([$this->restricted, $this->open]);
		$this->fileService->method('getObjectFolder')->willReturn($root);

		$refused = (new ObjectFolderBrowser($this->fileService, $this->guard(admitted: false)))->listFolder($this->object);
		$admitted = (new ObjectFolderBrowser($this->fileService, $this->guard(admitted: true)))->listFolder($this->object);

		$this->assertSame(['Brief.pdf'], array_column($refused['entries'], 'name'));
		$this->assertSame(['Offerte.pdf', 'Brief.pdf'], array_column($admitted['entries'], 'name'));
	}//end testTheObjectFolderListingLeavesTheRestrictedFileOut()

	public function testRenamingTheRestrictedFileThroughTheFolderBrowserIs404(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/openregister/files/Open Registers/R/' . self::OBJECT_UUID);
		$root->method('getId')->willReturn(7);
		$root->method('getFirstNodeById')->willReturn($this->restricted);
		$this->fileService->method('getObjectFolder')->willReturn($root);
		$this->fileService->expects($this->never())->method('renameFile');

		$this->expectException(NotFoundException::class);
		(new ObjectFolderBrowser($this->fileService, $this->guard(admitted: false)))->rename($this->object, self::RESTRICTED, 'x.pdf');
	}//end testRenamingTheRestrictedFileThroughTheFolderBrowserIs404()

	public function testTheFilesListLeavesTheRestrictedFileOut(): void {
		$this->fileService->method('getFiles')->willReturn([$this->restricted, $this->open]);

		$this->assertSame(1, $this->controller(admitted: false)->index('7', '42', self::OBJECT_UUID)->getData()['total']);
		$this->assertSame(2, $this->controller(admitted: true)->index('7', '42', self::OBJECT_UUID)->getData()['total']);
	}//end testTheFilesListLeavesTheRestrictedFileOut()

	public function testShowingTheRestrictedFileIs404BeforeItIsRead(): void {
		$this->fileService->expects($this->never())->method('getFile');

		$this->assertSame(404, $this->controller(admitted: false)->show('7', '42', self::OBJECT_UUID, self::RESTRICTED)->getStatus());
	}//end testShowingTheRestrictedFileIs404BeforeItIsRead()

	public function testEveryOtherPerFileEndpointIs404(): void {
		$controller = $this->controller(admitted: false);
		$this->fileService->expects($this->never())->method('getFile');

		$this->assertSame(404, $controller->listVersions('7', '42', self::OBJECT_UUID, self::RESTRICTED)->getStatus());
		$this->assertSame(404, $controller->preview('7', '42', self::OBJECT_UUID, self::RESTRICTED)->getStatus());
		$this->assertSame(404, $controller->rename('7', '42', self::OBJECT_UUID, self::RESTRICTED)->getStatus());
		$this->assertSame(404, $controller->delete('7', '42', self::OBJECT_UUID, self::RESTRICTED)->getStatus());
		$this->assertSame(404, $controller->copy('7', '42', self::OBJECT_UUID, self::RESTRICTED)->getStatus());
		$this->assertSame(404, $controller->lock('7', '42', self::OBJECT_UUID, self::RESTRICTED)->getStatus());
		$this->assertSame(404, $controller->office('7', '42', self::OBJECT_UUID, self::RESTRICTED)->getStatus());
	}//end testEveryOtherPerFileEndpointIs404()

	public function testDownloadByIdOfTheRestrictedFileIs404(): void {
		$this->fileService->method('getFileById')->willReturn($this->restricted);
		$this->fileService->method('isManagedFile')->willReturn(true);
		$this->fileService->method('findObjectForFile')->willReturn($this->object);
		$this->fileService->expects($this->never())->method('streamFile');

		$this->assertSame(404, $this->controller(admitted: false)->downloadById(self::RESTRICTED)->getStatus());
	}//end testDownloadByIdOfTheRestrictedFileIs404()

	public function testTheZipLeavesTheRestrictedFileOut(): void {
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturn($this->guard(admitted: false));
		$this->fileService->method('getFiles')->willReturn([$this->restricted]);
		$handler = new FilePublishingHandler(
			$this->createMock(MagicMapper::class),
			$this->createMock(FileMapper::class),
			new NullLogger(),
			$container
		);
		$handler->setFileService($this->fileService);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No files found for this object');
		$handler->createObjectFilesZip($this->object);
	}//end testTheZipLeavesTheRestrictedFileOut()

	public function testSearchDropsTheRestrictedHit(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn($this->open);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$scope = new FileReadScope($rootFolder, $session, new NullLogger(), null, null, $this->guard(admitted: false));
		$hits = [
			['entity_type' => 'file', 'entity_id' => self::RESTRICTED],
			['entity_type' => 'file', 'entity_id' => (string)self::OPEN],
		];

		$this->assertSame([$hits[1]], $scope->readableResults($hits));
	}//end testSearchDropsTheRestrictedHit()

	public function testTheIntegrationFilesLeafLeavesTheRestrictedFileOut(): void {
		$mapper = new class($this->object) {
			public function __construct(private readonly ObjectEntity $object) {
			}

			public function find(string $id): ObjectEntity {
				return $this->object;
			}
		};
		$guard = $this->guard(admitted: false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $id === FileRestrictionGuard::class ? $guard : $mapper
		);
		$this->fileService->method('getFilesForEntity')->willReturn([$this->restricted, $this->open]);

		$rows = (new FilesProvider($this->fileService, $container, $this->createMock(IL10N::class)))
			->list('7', '42', self::OBJECT_UUID);

		$this->assertSame([self::OPEN], array_column($rows, 'id'));
	}//end testTheIntegrationFilesLeafLeavesTheRestrictedFileOut()
}//end class
