<?php

/**
 * Uploads into a register whose documents are moving are refused with 503.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Controller\FilesController;
use OCA\OpenRegister\Files\Controller\Support\FileEditing;
use OCA\OpenRegister\Files\Controller\Support\FileGuards;
use OCA\OpenRegister\Files\Controller\Support\FilePlatform;
use OCA\OpenRegister\Files\Controller\Support\FilesControllerServices;
use OCA\OpenRegister\Files\Controller\Support\FileStore;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\File\DocumentStoreMoveGuard;
use OCA\OpenRegister\Service\File\DocumentStoreMoving;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Drives the controller with the register's move flag set.
 */
#[CoversClass(DocumentStoreMoveGuard::class)]
#[CoversClass(DocumentStoreMoving::class)]
class FilesControllerMoveGuardTest extends TestCase {

	/**
	 * The file service; must not be written to while the register moves.
	 *
	 * @var FileServiceInterface&MockObject
	 */
	private FileServiceInterface&MockObject $fileService;

	/**
	 * The object, in register 5.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	protected function setUp(): void {
		$this->fileService = $this->createMock(FileServiceInterface::class);
		$this->object = new ObjectEntity();
		$this->object->setId(11);
		$this->object->setUuid('obj-uuid');
		$this->object->setRegister('5');
		$this->config = [];
	}

	private function guard(): DocumentStoreMoveGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		return new DocumentStoreMoveGuard($config);
	}

	private function controller(array $params = []): FilesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('getObject')->willReturn($this->object);

		$access = $this->createMock(ObjectFileAccess::class);
		$access->method('changeable')->willReturn($this->object);
		$access->method('readable')->willReturn($this->object);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new FilesController(
			$request,
			new FilesControllerServices(
				store: new FileStore(
					fileService: $this->fileService,
					objectService: $objects,
					rootFolder: $this->createMock(IRootFolder::class),
				),
				platform: new FilePlatform(
					userManager: $this->createMock(IUserManager::class),
					eventDispatcher: $this->createMock(IEventDispatcher::class),
					userSession: $session,
				),
				guards: new FileGuards(objectFileAccess: $access, moveGuard: $this->guard()),
				editing: new FileEditing(),
			)
		);
	}

	public function testAnUploadDuringAMoveIs503(): void {
		$this->guard()->begin(registerId: 5);
		$this->fileService->expects($this->never())->method('addFile');

		$response = $this->controller(['name' => 'besluit.pdf', 'content' => 'aGVsbG8='])->create('5', '7', 'obj-uuid');

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'document-store-move'], $response->getData());
	}

	public function testEveryUploadPathAnswers503DuringAMove(): void {
		$this->guard()->begin(registerId: 5);
		$this->fileService->expects($this->never())->method('addFile');
		$this->fileService->expects($this->never())->method('overwriteFile');

		$controller = $this->controller(['name' => 'besluit.pdf', 'content' => 'aGVsbG8=']);
		$answers = [
			'create' => $controller->create('5', '7', 'obj-uuid'),
			'save' => $controller->save('5', '7', 'obj-uuid'),
			'createMultipart' => $controller->createMultipart('5', '7', 'obj-uuid'),
			'update' => $controller->update('5', '7', 'obj-uuid', 42),
		];

		foreach ($answers as $method => $response) {
			$this->assertSame(503, $response->getStatus(), $method . ' wrote into a register being moved.');
			$this->assertSame(['error' => 'document-store-move'], $response->getData(), $method);
		}
	}

	public function testAnUploadAfterTheMoveGoesThrough(): void {
		$guard = $this->guard();
		$guard->begin(registerId: 5);
		$guard->end(registerId: 5);
		$this->fileService->expects($this->once())->method('addFile')->willThrowException(new \RuntimeException('stored'));

		$response = $this->controller(['name' => 'besluit.pdf', 'content' => 'aGVsbG8='])->create('5', '7', 'obj-uuid');

		$this->assertNotSame(503, $response->getStatus());
	}

	public function testAnotherRegistersMoveDoesNotHoldThisOneOff(): void {
		$this->guard()->begin(registerId: 6);

		$this->assertFalse($this->guard()->isMoving(registerId: '5'));
		$this->assertTrue($this->guard()->isMoving(registerId: '6'));
		$this->assertFalse($this->guard()->isMoving(registerId: 'not-an-id'));
	}

	public function testAReadDuringAMoveStillWorks(): void {
		$this->guard()->begin(registerId: 5);

		$response = $this->controller()->index('5', '7', 'obj-uuid');

		$this->assertNotSame(503, $response->getStatus());
	}

	public function testEveryUploadPathChecksTheFlag(): void {
		$source = (string)file_get_contents((string)(new ReflectionClass(FilesController::class))->getFileName());
		$guarded = [];
		foreach (['create', 'save', 'createMultipart', 'update'] as $method) {
			$body = $this->bodyOf(source: $source, method: new ReflectionMethod(FilesController::class, $method));
			if (str_contains($body, 'writableObject(') === true) {
				$guarded[] = $method;
			}
		}

		$this->assertSame(['create', 'save', 'createMultipart', 'update'], $guarded);

		$reads = [];
		foreach (['index', 'show', 'downloadById', 'listVersions', 'preview'] as $method) {
			if (str_contains($this->bodyOf(source: $source, method: new ReflectionMethod(FilesController::class, $method)), 'writableObject(') === true) {
				$reads[] = $method;
			}
		}

		$this->assertSame([], $reads, 'A read path is held off during a move.');
	}

	private function bodyOf(string $source, ReflectionMethod $method): string {
		$lines = explode("\n", $source);
		return implode("\n", array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
	}
}//end class
