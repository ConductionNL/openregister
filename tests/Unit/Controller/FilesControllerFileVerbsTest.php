<?php

/**
 * File rights apart from object rights, through the controller.
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
 * @spec openspec/specs/file-actions/spec.md
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
 * readFiles to list, addFiles to upload, deleteFiles to delete, on top of read.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class FilesControllerFileVerbsTest extends TestCase {

	private const OBJECT_UUID = 'a184fccf-0000-4000-8000-000000000001';

	private FileService&MockObject $fileService;

	private ObjectService&MockObject $objectService;

	private ?OfficeSessionService $officeService = null;

	private Schema $schema;

	private ObjectEntity $object;

	protected function setUp(): void {
		parent::setUp();

		// Inspectors read files, intakers also add them, handlers also delete;
		// one group reads the object and none of its files.
		$this->schema = new Schema();
		$this->schema->setId(42);
		$this->schema->setTitle('Submission');
		$this->schema->setAuthorization([
			'read' => ['inspecteurs', 'intakers', 'behandelaars', 'zonder-bestanden'],
			'update' => ['behandelaars'],
			'readFiles' => ['inspecteurs', 'intakers', 'behandelaars'],
			'addFiles' => ['intakers', 'behandelaars'],
			'deleteFiles' => ['behandelaars'],
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

	public function testAReaderOfFilesListsThemAndCannotUpload(): void {
		$this->fileService->method('getFiles')->willReturn([$this->createMock(File::class)]);
		$this->fileService->expects($this->never())->method('addFile');

		$controller = $this->controllerAs('inspecteur', ['inspecteurs']);

		$this->assertSame(200, $controller->index(register: '7', schema: '42', id: self::OBJECT_UUID)->getStatus());
		$this->assertSame(403, $controller->create(register: '7', schema: '42', id: self::OBJECT_UUID)->getStatus());
	}//end testAReaderOfFilesListsThemAndCannotUpload()

	public function testReadingTheObjectIsNotReadingItsFiles(): void {
		$this->fileService->expects($this->never())->method('getFiles');

		$response = $this->controllerAs('lezer', ['zonder-bestanden'])
			->index(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(404, $response->getStatus());
	}//end testReadingTheObjectIsNotReadingItsFiles()

	public function testAddingWithoutUpdateOnTheObject(): void {
		$controller = $this->controllerAs('intaker', ['intakers']);

		$this->assertNotSame(403, $controller->create(register: '7', schema: '42', id: self::OBJECT_UUID)->getStatus(), 'addFiles is enough to add');
		$this->assertSame(403, $controller->delete(register: '7', schema: '42', id: self::OBJECT_UUID, fileId: 1)->getStatus(), 'adding is not deleting');
	}//end testAddingWithoutUpdateOnTheObject()

	public function testDeleteFilesDeletes(): void {
		$this->fileService->method('deleteFile')->willReturn(true);

		$response = $this->controllerAs('behandelaar', ['behandelaars'])
			->delete(register: '7', schema: '42', id: self::OBJECT_UUID, fileId: 1);

		$this->assertNotSame(403, $response->getStatus());
		$this->assertNotSame(404, $response->getStatus());
	}//end testDeleteFilesDeletes()
}//end class
