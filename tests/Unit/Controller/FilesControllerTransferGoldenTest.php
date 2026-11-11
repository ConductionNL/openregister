<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FilesControllerFactory;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\File\FileTransfer;

/**
 * Golden test for FilesController::copy() and move().
 *
 * Every case records each collaborator call (the restriction and access
 * guards with the objects they are asked about, the object service, the
 * file service, both audit rows, the event) and the response, and compares
 * the recording with fixtures/files-transfer-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Files\Controller\FilesController
 * @uses \OCA\OpenRegister\Files\Controller\Support\FilesControllerServices
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileStore
 * @uses \OCA\OpenRegister\Files\Controller\Support\FilePlatform
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileGuards
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileEditing
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileTransfer
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileAccessCheck
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileObjectRoute
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Event\FileCopiedEvent
 * @uses \OCA\OpenRegister\Event\AbstractObjectFileEvent
 * @uses \OCA\OpenRegister\Event\FileMovedEvent
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Exception\ObjectFileAccessDeniedException
 * @uses \OCA\OpenRegister\Exception\ObjectStateWriteException
 * @uses \OCA\OpenRegister\Service\WriteCause
 */
class FilesControllerTransferGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/files-transfer-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		$shapes = [
			'same register' => ['params' => ['targetObjectId' => 'target-uuid']],
			'other register' => ['params' => ['targetObjectId' => 'target-uuid', 'targetRegister' => 'reg2', 'targetSchema' => 'sch2']],
			'no target id' => ['params' => []],
			'empty target id' => ['params' => ['targetObjectId' => '']],
			'anonymous' => ['anonymous' => true, 'params' => ['targetObjectId' => 'target-uuid']],
			'legacy wiring without the access guard' => ['legacy' => true, 'params' => ['targetObjectId' => 'target-uuid']],
			'restricted file' => ['restricted' => true, 'params' => ['targetObjectId' => 'target-uuid']],
			'source not readable' => ['deny' => ['source' => ObjectFileAccessDeniedException::NOT_READABLE], 'params' => ['targetObjectId' => 'target-uuid']],
			'source not changeable' => ['deny' => ['source' => ObjectFileAccessDeniedException::NOT_CHANGEABLE], 'params' => ['targetObjectId' => 'target-uuid']],
			'target not changeable' => ['deny' => ['target-uuid' => ObjectFileAccessDeniedException::NOT_CHANGEABLE], 'params' => ['targetObjectId' => 'target-uuid']],
			'target not readable' => ['deny' => ['target-uuid' => ObjectFileAccessDeniedException::NOT_READABLE], 'params' => ['targetObjectId' => 'target-uuid']],
			'target frozen' => ['frozen' => 'target-uuid', 'params' => ['targetObjectId' => 'target-uuid']],
			'source frozen' => ['frozen' => 'source-uuid', 'params' => ['targetObjectId' => 'target-uuid']],
			'source object missing' => ['objects' => [null], 'params' => ['targetObjectId' => 'target-uuid']],
			'target object missing' => ['objects' => ['source', null], 'params' => ['targetObjectId' => 'target-uuid']],
			'service says not found' => ['throws' => 'File 42 not found', 'params' => ['targetObjectId' => 'target-uuid']],
			'service says locked' => ['throws' => 'File is locked by user-2', 'params' => ['targetObjectId' => 'target-uuid']],
			'service fails otherwise' => ['throws' => 'disk full', 'params' => ['targetObjectId' => 'target-uuid']],
			'service refuses the state' => ['stateThrows' => true, 'params' => ['targetObjectId' => 'target-uuid']],
			'service refuses access' => ['authThrows' => true, 'params' => ['targetObjectId' => 'target-uuid']],
		];
		foreach (['copy', 'move'] as $action) {
			foreach ($shapes as $name => $shape) {
				$cases[$action . ': ' . $name] = [$action, $shape];
			}
		}

		return $cases;
	}

	#[DataProvider('cases')]
	public function testTransferMatchesTheRecording(string $action, array $case): void {
		$this->calls = [];
		$controller = FilesControllerFactory::create(
			$this->request(case: $case),
			$this->fileService(case: $case),
			$this->objectService(case: $case),
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class),
			$this->dispatcher(),
			null,
			null,
			$this->userSession(case: $case),
			null,
			($case['legacy'] ?? false) === true ? null : $this->access(case: $case),
			null,
			null,
			$this->writeGuard(case: $case),
			$this->restrictions(case: $case)
		);

		$response = $controller->{$action}('reg', 'sch', 'source-uuid', 42);
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare(name: $this->dataName(), actual: $actual);
	}

	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('r-' . $uuid);
		$object->setSchema('s-' . $uuid);
		return $object;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function request(array $case): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['request.getParams' => true];
			return $case['params'];
		});
		return $request;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function userSession(array $case): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (($case['anonymous'] ?? false) === false) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function restrictions(array $case): FileRestrictionGuard {
		$guard = $this->createMock(FileRestrictionGuard::class);
		$guard->method('mayRead')->willReturnCallback(function (int $fileId) use ($case) {
			$this->calls[] = ['restrictions.mayRead' => $fileId];
			return ($case['restricted'] ?? false) === false;
		});
		return $guard;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function access(array $case): ObjectFileAccess {
		$access = $this->createMock(ObjectFileAccess::class);
		$answer = function (string $kind, array $args) use ($case): ObjectEntity {
			$this->calls[] = ['access.' . $kind => $args];
			$key = $args[2] === 'source-uuid' ? 'source' : $args[2];
			if (isset($case['deny'][$key]) === true) {
				throw new ObjectFileAccessDeniedException(httpStatus: $case['deny'][$key]);
			}

			return $this->object(uuid: 'checked-' . $args[2]);
		};
		$access->method('readable')->willReturnCallback(fn (ObjectRef $ref, ...$rest) => $answer('readable', [$ref->register, $ref->schema, $ref->id, ...$rest]));
		$access->method('changeable')->willReturnCallback(fn (ObjectRef $ref, ...$rest) => $answer('changeable', [$ref->register, $ref->schema, $ref->id, ...$rest]));
		return $access;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function writeGuard(array $case): FileWriteGuard {
		$guard = $this->createMock(FileWriteGuard::class);
		$guard->method('assertWritable')->willReturnCallback(function (ObjectEntity $object) use ($case) {
			$this->calls[] = ['writeGuard.assertWritable' => $object->getUuid()];
			$frozen = ($case['frozen'] ?? null);
			if ($frozen !== null && str_ends_with((string)$object->getUuid(), $frozen) === true) {
				throw new ObjectStateWriteException(message: 'Object is frozen');
			}
		});
		return $guard;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function objectService(array $case): ObjectService {
		$service = $this->createMock(ObjectService::class);
		foreach (['setRegister', 'setSchema', 'setObject'] as $method) {
			$service->method($method)->willReturnCallback(function (...$args) use ($method, $service) {
				$this->calls[] = ['objectService.' . $method => $args];
				return $service;
			});
		}

		$queue = $case['objects'] ?? ['source', 'target'];
		$service->method('getObject')->willReturnCallback(function () use (&$queue) {
			$next = array_shift($queue);
			$this->calls[] = ['objectService.getObject' => $next];
			return $next === null ? null : $this->object(uuid: $next . '-uuid');
		});
		$service->method('find')->willReturnCallback(function (...$args) {
			$args          = FindCallShape::find(args: $args);
			$this->calls[] = ['objectService.find' => array_map(fn ($a) => is_scalar($a) ? $a : gettype($a), $args)];
			return $this->object(uuid: 'found-' . $args[0]);
		});
		return $service;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function fileService(array $case): FileService {
		$service = $this->createMock(FileService::class);
		$transfer = function (string $method, array $args) use ($case): File {
			$this->calls[] = ['fileService.' . $method => [$args[0]->getUuid(), $args[1], $args[2]->getUuid()]];
			if (isset($case['throws']) === true) {
				throw new Exception($case['throws']);
			}

			if (($case['stateThrows'] ?? false) === true) {
				throw new ObjectStateWriteException(message: 'Target is archived');
			}

			if (($case['authThrows'] ?? false) === true) {
				throw new NotAuthorizedException(message: 'no');
			}

			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn(99);
			return $file;
		};
		$service->method('copyAttachedFile')->willReturnCallback(fn (FileTransfer $copy) => $transfer('copyFile', [$copy->source, $copy->fileId, $copy->target]));
		$service->method('moveAttachedFile')->willReturnCallback(fn (FileTransfer $move) => $transfer('moveFile', [$move->source, $move->fileId, $move->target]));
		$service->method('formatFile')->willReturnCallback(function (File $file) {
			$this->calls[] = ['fileService.formatFile' => $file->getId()];
			return ['id' => $file->getId(), 'name' => 'copied.pdf'];
		});
		$audit = $this->createMock(FileAuditHandler::class);
		$audit->method('logFileAction')->willReturnCallback(function (...$args) {
			$this->calls[] = ['audit.logFileAction' => [$args[0]->getUuid(), $args[1], $args[2], $args[3] ?? []]];
			return null;
		});
		$service->method('getAuditHandler')->willReturnCallback(function () use ($audit) {
			$this->calls[] = ['fileService.getAuditHandler' => true];
			return $audit;
		});
		return $service;
	}

	private function dispatcher(): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event) {
			$this->calls[] = ['dispatcher.dispatchTyped' => [
				get_class($event),
				$event->getObjectUuid(),
				$event->getFileId(),
				$event->getData(),
			]];
		});
		return $dispatcher;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $name, array $actual): void {
		$actual = json_decode(json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded ??= [];
			self::$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->assertTrue(true);
			return;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($name, self::$golden, 'case missing from the recording');
		$this->assertSame(self::$golden[$name], $actual);
	}
}
