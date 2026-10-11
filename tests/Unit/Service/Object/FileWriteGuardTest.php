<?php

/**
 * File writes honour the freeze and the archive (REQ-OAS-007), through the files API.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\File\FileAuditHandler;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The guard on its own, and the guard as FilesController calls it.
 */
class FileWriteGuardTest extends TestCase {

	private const OBJECT_UUID = 'b2c3d4e5-0000-4000-8000-000000000007';

	/**
	 * The write actions REQ-OAS-007 names; each must refuse on a frozen object.
	 *
	 * @var list<string>
	 */
	private const NAMED_WRITE_ACTIONS = [
		'create',
		'save',
		'createMultipart',
		'update',
		'delete',
		'rename',
		'move',
		'batch',
		'lock',
		'unlock',
	];

	private FileService&MockObject $fileService;

	private ObjectService&MockObject $objectService;

	private ObjectEntity $object;

	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('functioneel-beheer');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->object = new ObjectEntity();
		$this->object->setUuid(self::OBJECT_UUID);
		$this->object->setRegister('7');
		$this->object->setSchema('42');

		$this->fileService = $this->createMock(FileService::class);
		$this->fileService->method('getAuditHandler')->willReturn($this->createMock(FileAuditHandler::class));
		$this->fileService->method('formatFile')->willReturn(['id' => 1]);
		$this->fileService->method('formatFiles')->willReturnCallback(
			static fn (array $files): array => ['results' => $files, 'total' => count($files)]
		);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn($this->object);
	}//end setUp()

	/**
	 * A controller whose access rule lets the caller in, with the REAL guard.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return FilesController
	 */
	private function controller(array $params = []): FilesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$access = $this->createMock(ObjectFileAccess::class);
		$access->method('changeable')->willReturn($this->object);
		$access->method('readable')->willReturn($this->object);

		$userManager = $this->createMock(IUserManager::class);

		return new FilesController(
			'openregister',
			$request,
			$this->fileService,
			$this->objectService,
			$this->createMock(IRootFolder::class),
			$userManager,
			$this->createMock(IEventDispatcher::class),
			null,
			$this->createMock(FileAuditHandler::class),
			$this->userSession,
			null,
			$access,
			null,
			null,
			new FileWriteGuard()
		);
	}//end controller()

	/**
	 * Every public action that changes files catches the refusal and answers 409.
	 *
	 * Enumerated by reflection, so a write action added later without the
	 * catch fails here instead of answering 400 with the refusal as text.
	 *
	 * @return void
	 */
	public function testEveryFilesControllerWriteActionCallsTheGuard(): void {
		$class = new ReflectionClass(FilesController::class);
		$lines = file((string)$class->getFileName());
		$this->assertIsArray($lines);

		$writeActions = [];
		foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== FilesController::class) {
				continue;
			}

			$body = implode('', array_slice($lines, ($method->getStartLine() - 1), ($method->getEndLine() - $method->getStartLine() + 1)));
			$changes = preg_match_all('/ensureObjectAccess\((?![^)]*change: false)[^)]*\)/', $body);
			if ($changes === 0) {
				continue;
			}

			$writeActions[] = $method->getName();
			$this->assertStringContainsString(
				'catch (ObjectStateWriteException $e)',
				$body,
				'FilesController::' . $method->getName() . '() changes files but does not answer a frozen object with 409'
			);
		}

		foreach (self::NAMED_WRITE_ACTIONS as $action) {
			$this->assertContains($action, $writeActions, 'FilesController::' . $action . '() must go through the guard');
		}

		// The guard itself sits on the change path of ensureObjectAccess().
		$ensure = $class->getMethod('ensureObjectAccess');
		$ensureBody = implode('', array_slice($lines, ($ensure->getStartLine() - 1), ($ensure->getEndLine() - $ensure->getStartLine() + 1)));
		$this->assertSame(2, substr_count($ensureBody, 'fileWriteGuard?->assertWritable('));
	}//end testEveryFilesControllerWriteActionCallsTheGuard()

	/**
	 * An upload to a frozen object is refused with the marker in the body.
	 *
	 * @return void
	 */
	public function testAFrozenObjectRefusesAnUpload(): void {
		$this->object->freeze(userSession: $this->userSession, reason: 'ingetrokken, besluit 2026-114');
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controller(['name' => 'bijlage.pdf', 'content' => 'x'])
			->create(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(409, $response->getStatus());
		$body = $response->getData();
		$this->assertSame('frozen', $body['state']);
		$this->assertSame('functioneel-beheer', $body['by']);
		$this->assertSame('ingetrokken, besluit 2026-114', $body['reason']);
		$this->assertNotEmpty($body['at']);
		$this->assertStringContainsString('frozen by functioneel-beheer', $body['error']);
	}//end testAFrozenObjectRefusesAnUpload()

	/**
	 * An archived object refuses a lock as well: a lock is a write.
	 *
	 * @return void
	 */
	public function testAnArchivedObjectRefusesALock(): void {
		$this->object->setArchived(['by' => 'archivaris', 'at' => '2026-10-01T10:00:00+00:00', 'reason' => 'overgebracht']);
		$this->fileService->expects($this->never())->method('getLockHandler');

		$response = $this->controller()->lock(register: '7', schema: '42', id: self::OBJECT_UUID, fileId: 5);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('archived', $response->getData()['state']);
		$this->assertSame('overgebracht', $response->getData()['reason']);
	}//end testAnArchivedObjectRefusesALock()

	/**
	 * After an unfreeze the same upload goes through.
	 *
	 * @return void
	 */
	public function testAnUnfrozenObjectAcceptsFileWritesAgain(): void {
		$this->object->freeze(userSession: $this->userSession, reason: 'tijdelijk');
		$this->object->setFrozen(null);
		$this->fileService->expects($this->once())->method('addFile')->willReturn($this->createMock(File::class));

		$response = $this->controller(['name' => 'bijlage.pdf', 'content' => 'x'])
			->create(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(200, $response->getStatus());
	}//end testAnUnfrozenObjectAcceptsFileWritesAgain()

	/**
	 * Reading a frozen object's files stays allowed.
	 *
	 * @return void
	 */
	public function testReadsStayAllowed(): void {
		$this->object->freeze(userSession: $this->userSession, reason: 'ingetrokken');
		$this->fileService->expects($this->once())->method('getFiles')->willReturn([$this->createMock(File::class)]);

		$response = $this->controller()->index(register: '7', schema: '42', id: self::OBJECT_UUID);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}//end testReadsStayAllowed()

	/**
	 * The guard on its own: archived before frozen, nothing for a plain object.
	 *
	 * @return void
	 */
	public function testTheGuardNamesTheStateAndPassesAPlainObject(): void {
		$guard = new FileWriteGuard();
		$this->assertNull($guard->refusalFor(object: $this->object));
		$guard->assertWritable(object: $this->object);

		$this->object->freeze(userSession: $this->userSession, reason: 'r');
		$this->object->setArchived(['by' => 'a', 'at' => '2026-10-01T10:00:00+00:00']);
		$this->assertSame('archived', $guard->refusalFor(object: $this->object)?->getState());

		$this->expectException(ObjectStateWriteException::class);
		$guard->assertWritable(object: $this->object);
	}//end testTheGuardNamesTheStateAndPassesAPlainObject()
}//end class
