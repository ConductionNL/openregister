<?php

/**
 * An object's folder, subfolders included, through the object's own rule.
 *
 * Driven from the caller: the real controller, the real ObjectFolderBrowser and
 * a REAL PermissionHandler deciding read and update for the acting user. Only
 * storage is faked: ObjectService::find(_rbac: true) answers with the same
 * handler's read verdict, and the object folder is a small tree of mocked nodes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-listing-an-objects-folder-and-its-subfolders-follows-the-objects-read-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ObjectFolderController;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\ObjectFolderBrowser;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * List with read, change with update, never outside the object folder.
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ObjectFolderControllerTest extends TestCase {

	private const OBJECT_UUID = 'b184fccf-0000-4000-8000-000000000002';

	private const ROOT_PATH = '/openregister/files/Open Registers/Zaken/' . self::OBJECT_UUID;

	private FileService&MockObject $fileService;

	private ObjectService&MockObject $objectService;

	private FileLockHandler&MockObject $locks;

	private IRequest&MockObject $request;

	private Schema $schema;

	private ObjectEntity $object;

	/** The object folder. */
	private Folder&MockObject $root;

	/** `Bijlagen`, a subfolder of the object folder. */
	private Folder&MockObject $sub;

	/** `besluit.pdf`, a file at the top of the object folder. */
	private File&MockObject $topFile;

	/** `brief.txt`, a file in `Bijlagen`. */
	private File&MockObject $subFile;

	protected function setUp(): void {
		parent::setUp();

		// Handlers read and update; readers only read; everyone else nothing.
		$this->schema = new Schema();
		$this->schema->setId(35);
		$this->schema->setTitle('Zaak');
		$this->schema->setAuthorization([
			'create' => ['behandelaars'],
			'read' => ['behandelaars', 'vergunningen'],
			'update' => ['behandelaars'],
		]);

		$this->object = new ObjectEntity();
		$this->object->setUuid(self::OBJECT_UUID);
		$this->object->setRegister('20');
		$this->object->setSchema('35');
		$this->object->setOwner('intake');

		$this->root = $this->folder(id: 100, path: self::ROOT_PATH, name: self::OBJECT_UUID);
		$this->sub = $this->folder(id: 200, path: self::ROOT_PATH . '/Bijlagen', name: 'Bijlagen');
		$this->topFile = $this->file(id: 101, path: self::ROOT_PATH . '/besluit.pdf', name: 'besluit.pdf');
		$this->subFile = $this->file(id: 201, path: self::ROOT_PATH . '/Bijlagen/brief.txt', name: 'brief.txt');

		$this->root->method('getDirectoryListing')->willReturn([$this->topFile, $this->sub]);
		$this->sub->method('getDirectoryListing')->willReturn([$this->subFile]);
		$this->sub->method('getParent')->willReturn($this->root);
		$this->topFile->method('getParent')->willReturn($this->root);
		$this->subFile->method('getParent')->willReturn($this->sub);
		$this->root->method('get')->willReturnCallback(
			function (string $path) {
				return match ($path) {
					'Bijlagen' => $this->sub,
					'besluit.pdf' => $this->topFile,
					default => throw new NotFoundException($path),
				};
			}
		);
		$this->root->method('nodeExists')->willReturnCallback(
			static fn (string $name): bool => in_array($name, ['Bijlagen', 'besluit.pdf'], true)
		);
		$this->sub->method('nodeExists')->willReturnCallback(static fn (string $name): bool => $name === 'brief.txt');
		$this->root->method('getFirstNodeById')->willReturnCallback(
			function (int $nodeId) {
				return match ($nodeId) {
					101 => $this->topFile,
					200 => $this->sub,
					201 => $this->subFile,
					default => null,
				};
			}
		);

		$this->locks = $this->createMock(FileLockHandler::class);

		$this->fileService = $this->createMock(FileService::class);
		$this->fileService->method('getObjectFolder')->willReturn($this->root);
		$this->fileService->method('getLockHandler')->willReturn($this->locks);

		$this->objectService = $this->createMock(ObjectService::class);

		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * A mocked folder.
	 *
	 * @param int    $id   Its id.
	 * @param string $path Its absolute path.
	 * @param string $name Its name.
	 *
	 * @return Folder&MockObject
	 */
	private function folder(int $id, string $path, string $name): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getName')->willReturn($name);
		$folder->method('getMimetype')->willReturn('httpd/unix-directory');
		$folder->method('getSize')->willReturn(12);
		$folder->method('getMTime')->willReturn(1760000000);

		return $folder;
	}//end folder()

	/**
	 * A mocked file.
	 *
	 * @param int    $id   Its id.
	 * @param string $path Its absolute path.
	 * @param string $name Its name.
	 *
	 * @return File&MockObject
	 */
	private function file(int $id, string $path, string $name): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($path);
		$file->method('getName')->willReturn($name);
		$file->method('getMimetype')->willReturn('text/plain');
		$file->method('getSize')->willReturn(6);
		$file->method('getMTime')->willReturn(1760000001);

		return $file;
	}//end file()

	/**
	 * The controller, acting as a user in the given groups.
	 *
	 * @param string        $uid    The acting user.
	 * @param array<string> $groups Their groups.
	 *
	 * @return ObjectFolderController
	 */
	private function controllerAs(string $uid, array $groups): ObjectFolderController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->schema);

		$logger = new NullLogger();
		$permissionHandler = new PermissionHandler(
			$userSession,
			$userManager,
			$groupManager,
			$schemaMapper,
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$logger,
			$this->createMock(ContainerInterface::class),
			null,
			null,
			null,
			new DenyResolver(new DenyEntryMatcher()),
			new DenyEnforcementMode($appConfig, $logger)
		);

		// An object read under RBAC answers with the handler's read verdict.
		$object = $this->object;
		$schema = $this->schema;
		$this->objectService->method('find')->willReturnCallback(
			static function () use ($permissionHandler, $schema, $object): ObjectEntity {
				$mayRead = $permissionHandler->hasPermission(
					schema: $schema,
					action: 'read',
					userId: null,
					objectOwner: $object->getOwner(),
					_rbac: true,
					object: $object
				);
				if ($mayRead === false) {
					throw new NotAuthorizedException('Not readable');
				}

				return $object;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ObjectFolderController(
			'openregister',
			$this->request,
			new ObjectFileAccess(
				objectService: $this->objectService,
				permissionHandler: $permissionHandler,
				schemaMapper: $schemaMapper
			),
			new ObjectFolderBrowser(fileService: $this->fileService),
			$l10n,
			$logger
		);
	}//end controllerAs()

	/**
	 * The entry names of a listing.
	 *
	 * @param array<string, mixed> $data The listing.
	 *
	 * @return list<string>
	 */
	private function names(array $data): array {
		return array_map(static fn (array $entry): string => $entry['name'], $data['entries']);
	}//end names()

	public function testAReaderListsTheObjectFolderWithItsSubfolder(): void {
		$response = $this->controllerAs('bea', ['vergunningen'])
			->index(register: '20', schema: '35', id: self::OBJECT_UUID);

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(['besluit.pdf', 'Bijlagen'], $this->names($data));
		$this->assertSame('folder', $data['entries'][1]['type']);
		$this->assertSame('file', $data['entries'][0]['type']);
		$this->assertSame('', $data['path']);
		$this->assertSame(100, $data['folderId']);
		$this->assertFalse($data['canChange'], 'a reader without update may not change the folder');
	}//end testAReaderListsTheObjectFolderWithItsSubfolder()

	public function testAReaderListsASubfolder(): void {
		$response = $this->controllerAs('bea', ['vergunningen'])
			->index(register: '20', schema: '35', id: self::OBJECT_UUID, path: 'Bijlagen');

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(['brief.txt'], $this->names($data));
		$this->assertSame('Bijlagen/brief.txt', $data['entries'][0]['path']);
		$this->assertSame(201, $data['entries'][0]['id']);
		$this->assertSame('Bijlagen', $data['path']);
	}//end testAReaderListsASubfolder()

	public function testAHandlerMayChangeTheFolder(): void {
		$response = $this->controllerAs('henk', ['behandelaars'])
			->index(register: '20', schema: '35', id: self::OBJECT_UUID);

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['canChange']);
	}//end testAHandlerMayChangeTheFolder()

	public function testAPersonWhoMayNotReadGets404AndNoListing(): void {
		$this->fileService->expects($this->never())->method('getObjectFolder');

		$response = $this->controllerAs('gewoon', [])
			->index(register: '20', schema: '35', id: self::OBJECT_UUID, path: 'Bijlagen');

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('entries', $response->getData());
	}//end testAPersonWhoMayNotReadGets404AndNoListing()

	/**
	 * @return array<string, array{string}>
	 */
	public static function escapingPaths(): array {
		return [
			'parent' => ['..'],
			'parent of another object' => ['../c184fccf-0000-4000-8000-000000000003'],
			'back up from a subfolder' => ['Bijlagen/../..'],
			'dot' => ['./Bijlagen'],
			'double slash' => ['Bijlagen//x'],
			'backslash' => ['Bijlagen\\..'],
		];
	}//end escapingPaths()

	/**
	 * @dataProvider escapingPaths
	 */
	public function testAPathThatCouldLeaveTheFolderIs400BeforeAnyLookup(string $path): void {
		$this->root->expects($this->never())->method('get');

		$response = $this->controllerAs('bea', ['vergunningen'])
			->index(register: '20', schema: '35', id: self::OBJECT_UUID, path: $path);

		$this->assertSame(400, $response->getStatus());
	}//end testAPathThatCouldLeaveTheFolderIs400BeforeAnyLookup()

	public function testAPathToAFileOrNothingIs404(): void {
		$controller = $this->controllerAs('bea', ['vergunningen']);

		$this->assertSame(404, $controller->index(register: '20', schema: '35', id: self::OBJECT_UUID, path: 'besluit.pdf')->getStatus());
		$this->assertSame(404, $controller->index(register: '20', schema: '35', id: self::OBJECT_UUID, path: 'Nergens')->getStatus());
	}//end testAPathToAFileOrNothingIs404()

	public function testAHandlerCreatesASubfolder(): void {
		$created = $this->folder(id: 300, path: self::ROOT_PATH . '/Bijlagen/Stukken', name: 'Stukken');
		$this->sub->expects($this->once())->method('newFolder')->with('Stukken')->willReturn($created);

		$response = $this->controllerAs('henk', ['behandelaars'])
			->create(register: '20', schema: '35', id: self::OBJECT_UUID, name: ' Stukken ', path: 'Bijlagen');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('Bijlagen/Stukken', $response->getData()['path']);
		$this->assertSame('folder', $response->getData()['type']);
	}//end testAHandlerCreatesASubfolder()

	public function testAReaderMayNotCreateAFolder(): void {
		$this->root->expects($this->never())->method('newFolder');

		$response = $this->controllerAs('bea', ['vergunningen'])
			->create(register: '20', schema: '35', id: self::OBJECT_UUID, name: 'Stukken');

		$this->assertSame(403, $response->getStatus());
	}//end testAReaderMayNotCreateAFolder()

	public function testAPersonWhoMayNotReadGets404OnCreate(): void {
		$this->root->expects($this->never())->method('newFolder');

		$response = $this->controllerAs('gewoon', [])
			->create(register: '20', schema: '35', id: self::OBJECT_UUID, name: 'Stukken');

		$this->assertSame(404, $response->getStatus());
	}//end testAPersonWhoMayNotReadGets404OnCreate()

	public function testATakenNameIs409(): void {
		$this->root->expects($this->never())->method('newFolder');

		$response = $this->controllerAs('henk', ['behandelaars'])
			->create(register: '20', schema: '35', id: self::OBJECT_UUID, name: 'Bijlagen');

		$this->assertSame(409, $response->getStatus());
	}//end testATakenNameIs409()

	/**
	 * @return array<string, array{string}>
	 */
	public static function badNames(): array {
		return [
			'empty' => [''],
			'dot dot' => ['..'],
			'slash' => ['a/b'],
			'colon' => ['a:b'],
		];
	}//end badNames()

	/**
	 * @dataProvider badNames
	 */
	public function testANameThatIsNotOneSegmentIs400(string $name): void {
		$this->root->expects($this->never())->method('newFolder');

		$response = $this->controllerAs('henk', ['behandelaars'])
			->create(register: '20', schema: '35', id: self::OBJECT_UUID, name: $name);

		$this->assertSame(400, $response->getStatus());
	}//end testANameThatIsNotOneSegmentIs400()

	public function testAHandlerRenamesASubfolderInPlace(): void {
		$moved = $this->folder(id: 200, path: self::ROOT_PATH . '/Stukken', name: 'Stukken');
		$this->sub->expects($this->once())->method('move')->with(self::ROOT_PATH . '/Stukken')->willReturn($moved);

		$response = $this->controllerAs('henk', ['behandelaars'])
			->rename(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 200, name: 'Stukken');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Stukken', $response->getData()['path']);
	}//end testAHandlerRenamesASubfolderInPlace()

	public function testAFileInASubfolderIsRenamedThroughTheFilePipeline(): void {
		$renamed = $this->file(id: 201, path: self::ROOT_PATH . '/Bijlagen/antwoord.txt', name: 'antwoord.txt');
		$this->fileService->expects($this->once())->method('renameFile')
			->with($this->object, 201, 'antwoord.txt')
			->willReturn($renamed);

		$response = $this->controllerAs('henk', ['behandelaars'])
			->rename(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 201, name: 'antwoord.txt');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Bijlagen/antwoord.txt', $response->getData()['path']);
	}//end testAFileInASubfolderIsRenamedThroughTheFilePipeline()

	public function testRenamingToATakenNameIs409(): void {
		$this->fileService->expects($this->never())->method('renameFile');

		$response = $this->controllerAs('henk', ['behandelaars'])
			->rename(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 101, name: 'Bijlagen');

		$this->assertSame(409, $response->getStatus());
	}//end testRenamingToATakenNameIs409()

	public function testANodeOfAnotherObjectIs404(): void {
		$this->fileService->expects($this->never())->method('renameFile');
		$this->fileService->expects($this->never())->method('deleteFile');
		$controller = $this->controllerAs('henk', ['behandelaars']);

		$this->assertSame(404, $controller->rename(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 999, name: 'x')->getStatus());
		$this->assertSame(404, $controller->destroy(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 999)->getStatus());
	}//end testANodeOfAnotherObjectIs404()

	public function testTheObjectFolderItselfIsNotANodeOfThisApi(): void {
		$this->root->expects($this->never())->method('delete');
		$this->root->expects($this->never())->method('move');
		$controller = $this->controllerAs('henk', ['behandelaars']);

		$this->assertSame(404, $controller->rename(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 100, name: 'x')->getStatus());
		$this->assertSame(404, $controller->destroy(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 100)->getStatus());
	}//end testTheObjectFolderItselfIsNotANodeOfThisApi()

	public function testAReaderMayNotRenameOrDelete(): void {
		$this->sub->expects($this->never())->method('move');
		$this->sub->expects($this->never())->method('delete');
		$controller = $this->controllerAs('bea', ['vergunningen']);

		$this->assertSame(403, $controller->rename(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 200, name: 'x')->getStatus());
		$this->assertSame(403, $controller->destroy(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 200)->getStatus());
	}//end testAReaderMayNotRenameOrDelete()

	public function testAHandlerDeletesASubfolder(): void {
		$this->sub->expects($this->once())->method('delete');

		$response = $this->controllerAs('henk', ['behandelaars'])
			->destroy(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 200);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['deleted' => true], $response->getData());
	}//end testAHandlerDeletesASubfolder()

	public function testAFolderHoldingAFileLockedBySomeoneElseIsNotDeleted(): void {
		$this->locks->method('assertCanModify')->willThrowException(new Exception('File is locked by anna'));
		$this->sub->expects($this->never())->method('delete');

		$response = $this->controllerAs('henk', ['behandelaars'])
			->destroy(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 200);

		$this->assertSame(409, $response->getStatus());
	}//end testAFolderHoldingAFileLockedBySomeoneElseIsNotDeleted()

	public function testAFileIsDeletedThroughTheFilePipeline(): void {
		$this->fileService->expects($this->once())->method('deleteFile')
			->with($this->subFile, $this->object)
			->willReturn(true);

		$response = $this->controllerAs('henk', ['behandelaars'])
			->destroy(register: '20', schema: '35', id: self::OBJECT_UUID, nodeId: 201);

		$this->assertSame(200, $response->getStatus());
	}//end testAFileIsDeletedThroughTheFilePipeline()

	/**
	 * Make the request carry one uploaded file under `files[]`.
	 *
	 * @param string $name    The file name.
	 * @param string $content Its bytes.
	 *
	 * @return void
	 */
	private function uploading(string $name, string $content): void {
		$tmp = tempnam(sys_get_temp_dir(), 'orfolder');
		file_put_contents($tmp, $content);
		$this->request->method('getUploadedFile')->willReturnCallback(
			static fn (string $field): ?array => $field === 'files'
				? ['name' => [$name], 'tmp_name' => [$tmp], 'error' => [UPLOAD_ERR_OK]]
				: null
		);
	}//end uploading()

	public function testAHandlerUploadsIntoASubfolderThroughTheUploadPipeline(): void {
		$this->uploading(name: 'nota.txt', content: 'aGFsbG8=');
		$stored = $this->file(id: 202, path: self::ROOT_PATH . '/Bijlagen/nota.txt', name: 'nota.txt');
		$this->fileService->expects($this->once())->method('addFile')
			->with(
				$this->object,
				'Bijlagen/nota.txt',
				$this->callback(static fn ($content): bool => is_resource($content) === true && stream_get_contents($content) === 'aGFsbG8=')
			)
			->willReturn($stored);

		$response = $this->controllerAs('henk', ['behandelaars'])
			->upload(register: '20', schema: '35', id: self::OBJECT_UUID, path: 'Bijlagen');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('Bijlagen/nota.txt', $response->getData()['stored'][0]['path']);
	}//end testAHandlerUploadsIntoASubfolderThroughTheUploadPipeline()

	public function testAnUploadOverATakenNameIs409(): void {
		$this->uploading(name: 'brief.txt', content: 'x');
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controllerAs('henk', ['behandelaars'])
			->upload(register: '20', schema: '35', id: self::OBJECT_UUID, path: 'Bijlagen');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('brief.txt', $response->getData()['rejected'][0]['name']);
	}//end testAnUploadOverATakenNameIs409()

	public function testAReaderMayNotUpload(): void {
		$this->uploading(name: 'nota.txt', content: 'x');
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controllerAs('bea', ['vergunningen'])
			->upload(register: '20', schema: '35', id: self::OBJECT_UUID, path: 'Bijlagen');

		$this->assertSame(403, $response->getStatus());
	}//end testAReaderMayNotUpload()

	public function testAnUploadWithNoFileIs400(): void {
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controllerAs('henk', ['behandelaars'])
			->upload(register: '20', schema: '35', id: self::OBJECT_UUID);

		$this->assertSame(400, $response->getStatus());
	}//end testAnUploadWithNoFileIs400()
}//end class
