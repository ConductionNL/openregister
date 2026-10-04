<?php

/**
 * Object files follow the object's access rules, through the controller.
 *
 * The decision is made by a REAL PermissionHandler for the acting user. Only
 * the storage is faked: ObjectService::find(_rbac: true) answers with the
 * same handler's read verdict, which is what the magic mapper's RBAC filter
 * applies to an object read.
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
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\File\FileAuditHandler;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\OfficeSession;
use OCA\OpenRegister\Service\File\OfficeSessionService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Read with read, change with update, refuse with 404 and 403.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */
class FilesControllerObjectAccessTest extends TestCase {

	private const OBJECT_UUID = 'a184fccf-0000-4000-8000-000000000001';

	private FileService&MockObject $fileService;

	private ObjectService&MockObject $objectService;

	private ?OfficeSessionService $officeService = null;

	private Schema $schema;

	private ObjectEntity $object;

	protected function setUp(): void {
		parent::setUp();

		// The intake case: the intake group creates, the handler group reads
		// and updates, a reader-only group reads, admins do anything.
		$this->schema = new Schema();
		$this->schema->setId(42);
		$this->schema->setTitle('Submission');
		$this->schema->setAuthorization([
			'create' => ['openformulieren-intake'],
			'read' => ['openformulieren-behandelaars', 'lezers'],
			'update' => ['openformulieren-behandelaars'],
		]);

		$this->object = new ObjectEntity();
		$this->object->setUuid(self::OBJECT_UUID);
		$this->object->setRegister('7');
		$this->object->setSchema('42');
		$this->object->setOwner('of-intake');

		$this->fileService = $this->createMock(FileService::class);
		$this->fileService->method('getAuditHandler')->willReturn($this->createMock(FileAuditHandler::class));
		$this->fileService->method('formatFiles')->willReturnCallback(
			static fn (array $files): array => ['results' => $files, 'total' => count($files)]
		);
		$this->fileService->method('formatFile')->willReturn(['id' => 1]);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn($this->object);
	}//end setUp()

	/**
	 * A controller acting as a user in the given groups.
	 *
	 * @param string        $uid    The acting user.
	 * @param array<string> $groups Their groups.
	 *
	 * @return FilesController
	 */
	private function controllerAs(string $uid, array $groups): FilesController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));

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

		$access = new ObjectFileAccess(
			objectService: $this->objectService,
			permissionHandler: $permissionHandler,
			schemaMapper: $schemaMapper
		);

		return new FilesController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->fileService,
			$this->objectService,
			$this->createMock(IRootFolder::class),
			$userManager,
			$this->createMock(IEventDispatcher::class),
			null,
			$this->createMock(FileAuditHandler::class),
			$userSession,
			null,
			$access,
			$this->officeService
		);
	}//end controllerAs()

	public function testAHandlerGroupMemberListsTheIntakesAttachments(): void {
		$file = $this->createMock(File::class);
		$this->fileService->expects($this->once())->method('getFiles')->willReturn([$file, $file]);

		$response = $this->controllerAs('behandelaar', ['openformulieren-behandelaars'])
			->index(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['total']);
	}//end testAHandlerGroupMemberListsTheIntakesAttachments()

	public function testANonMemberGets404AndNoFileList(): void {
		$this->fileService->expects($this->never())->method('getFiles');

		$response = $this->controllerAs('gewoon', [])
			->index(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('total', $response->getData());
		$this->assertArrayNotHasKey('results', $response->getData());
	}//end testANonMemberGets404AndNoFileList()

	public function testAnAdminListsTheAttachments(): void {
		$this->fileService->expects($this->once())->method('getFiles')->willReturn([$this->createMock(File::class)]);

		$response = $this->controllerAs('admin', ['admin'])
			->index(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(200, $response->getStatus());
	}//end testAnAdminListsTheAttachments()

	public function testTheSavingAccountListsThroughTheOwnerRule(): void {
		$this->fileService->expects($this->once())->method('getFiles')->willReturn([$this->createMock(File::class)]);

		$response = $this->controllerAs('of-intake', ['openformulieren-intake'])
			->index(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(200, $response->getStatus());
	}//end testTheSavingAccountListsThroughTheOwnerRule()

	public function testAReaderWhoMayNotUpdateGets403OnUpload(): void {
		$this->fileService->expects($this->never())->method('addFile');

		$controller = $this->controllerAs('lezer', ['lezers']);
		$response = $controller->create(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(403, $response->getStatus());
	}//end testAReaderWhoMayNotUpdateGets403OnUpload()

	public function testANonMemberGets404OnUpload(): void {
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controllerAs('gewoon', [])
			->create(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(404, $response->getStatus());
	}//end testANonMemberGets404OnUpload()

	public function testANonMemberCannotFetchAManagedFileById(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(901);
		$this->fileService->method('getFileById')->willReturn($file);
		$this->fileService->method('isManagedFile')->willReturn(true);
		$this->fileService->method('findObjectForFile')->willReturn($this->object);
		$this->fileService->expects($this->never())->method('streamFile');

		$response = $this->controllerAs('gewoon', [])->downloadById(fileId: 901);

		$this->assertSame(404, $response->getStatus());
	}//end testANonMemberCannotFetchAManagedFileById()

	public function testAManagedFileWithNoObjectIsRefusedById(): void {
		$file = $this->createMock(File::class);
		$this->fileService->method('getFileById')->willReturn($file);
		$this->fileService->method('isManagedFile')->willReturn(true);
		$this->fileService->method('findObjectForFile')->willReturn(null);
		$this->fileService->expects($this->never())->method('streamFile');

		$response = $this->controllerAs('admin', ['admin'])->downloadById(fileId: 902);

		$this->assertSame(404, $response->getStatus());
	}//end testAManagedFileWithNoObjectIsRefusedById()

	/**
	 * An Office service whose token issuer records what it was asked for.
	 *
	 * @param array<int, bool> $issued Receives the canWrite flag of every issued token.
	 *
	 * @return void
	 */
	private function arrangeOffice(array &$issued): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(77);
		$file->method('getName')->willReturn('report.odt');
		$file->method('getMimeType')->willReturn('application/vnd.oasis.opendocument.text');
		$this->fileService->method('getFile')->willReturn($file);

		$service = $this->createMock(OfficeSessionService::class);
		$service->method('open')->willReturnCallback(
			static function (File $file, string $editorUid, string $displayName, bool $canWrite) use (&$issued): OfficeSession {
				$issued[] = $canWrite;
				return new OfficeSession(
					urlSrc: 'https://office.test/browser/cool.html?',
					wopiSrc: 'https://nc.test/index.php/apps/richdocuments/wopi/files/77_oc',
					token: 'tok',
					tokenTtl: 1,
					readOnly: ($canWrite === false)
				);
			}
		);
		$this->officeService = $service;
	}//end arrangeOffice()

	public function testAnUpdateHolderOpensTheDocumentForEditing(): void {
		$issued = [];
		$this->arrangeOffice($issued);

		$response = $this->controllerAs('behandelaar', ['openformulieren-behandelaars'])
			->office(register: '7', schema: '42', id: self::OBJECT_UUID, fileId: 77);

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($response->getData()['readOnly']);
		$this->assertSame([true], $issued);
	}//end testAnUpdateHolderOpensTheDocumentForEditing()

	public function testAReadOnlyHolderOpensTheDocumentReadOnly(): void {
		$issued = [];
		$this->arrangeOffice($issued);

		$response = $this->controllerAs('lezer', ['lezers'])
			->office(register: '7', schema: '42', id: self::OBJECT_UUID, fileId: 77);

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['readOnly']);
		$this->assertSame([false], $issued);
	}//end testAReadOnlyHolderOpensTheDocumentReadOnly()

	public function testAPersonWithoutAccessGetsNoToken(): void {
		$issued = [];
		$this->arrangeOffice($issued);

		$response = $this->controllerAs('gewoon', [])
			->office(register: '7', schema: '42', id: self::OBJECT_UUID, fileId: 77);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame([], $issued);
	}//end testAPersonWithoutAccessGetsNoToken()
}//end class
