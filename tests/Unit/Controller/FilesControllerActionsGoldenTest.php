<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Service\File\FileBatchRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Files\Controller\FilesController;
use OCA\OpenRegister\Files\Db\File as FileEntity;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\AttachNodeHandler;
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\FileBatchHandler;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FilePreviewHandler;
use OCA\OpenRegister\Service\File\FileVersioningHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FilesControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\Response;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Golden test for what each file action answers and does, in every world it can meet.
 *
 * Every case runs one public action of FilesController against a faked world
 * (who asks, what the access rule says, what the services do) and compares the
 * response and the ordered calls with fixtures/files-actions-golden.json, one
 * case per line. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Files\Controller\FilesController
 * @covers \OCA\OpenRegister\Files\Controller\Support\FileAccessCheck
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileActionRefusal
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileActionTarget
 * @uses \OCA\OpenRegister\Files\Controller\Support\FilesControllerServices
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileStore
 * @uses \OCA\OpenRegister\Files\Controller\Support\FilePlatform
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileGuards
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileEditing
 * @uses \OCA\OpenRegister\Files\Controller\Support\FileObjectRoute
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class FilesControllerActionsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/files-actions-golden.json';

	private const UPLOAD = __DIR__ . '/fixtures/files-uploads-golden.json';

	private const OBJECT_UUID = 'a184fccf-0000-4000-8000-000000000001';

	/** The service call each action leans on, made to fail in the failure worlds. */
	private const WORK = [
		'attach' => 'attach',
		'saveChat' => 'addFile',
		'create' => 'addFile',
		'save' => 'saveFile',
		'createMultipart' => 'formatFiles',
		'update' => 'updateFile',
		'delete' => 'deleteFile',
		'rename' => 'renameFile',
		'listVersions' => 'listVersions',
		'restoreVersion' => 'restoreVersion',
		'lock' => 'lockFile',
		'unlock' => 'unlockFile',
		'batch' => 'executeBatch',
		'preview' => 'getPreview',
		'updateMetadata' => 'updateFileMetadata',
		'saveMetadataForm' => 'formatFile',
		'updateLabels' => 'updateFile',
		'show' => 'getFile',
		'index' => 'getFiles',
		'downloadById' => 'getFileById',
	];

	/** The request each action gets when nothing else is said. */
	private const PARAMS = [
		'attach' => ['nodeId' => 5],
		'saveChat' => ['conversation' => 'Team', 'messages' => [['actorDisplayName' => 'Ann', 'message' => 'hi', 'timestamp' => 86400]]],
		'create' => ['name' => 'a.txt', 'content' => 'body'],
		'save' => ['name' => 'a.txt', 'content' => 'body'],
		'update' => ['tags' => ['t'], 'content' => 'new'],
		'rename' => ['name' => 'b.txt', 'oldName' => 'a.txt'],
		'batch' => ['action' => 'label', 'fileIds' => [7, 8], 'labels' => ['x']],
		'updateMetadata' => ['description' => 'd', 'category' => 'c', 'labels' => ['l']],
		'saveMetadataForm' => ['files' => []],
		'updateLabels' => ['labels' => ['a', 'b']],
	];

	/** Worlds every action meets. */
	private const WORLDS = [
		'as is' => [],
		'anonymous' => ['anonymous' => true],
		'anonymous published' => ['anonymous' => true, 'published' => true],
		'anonymous, no mapper' => ['anonymous' => true, 'noMapper' => true],
		'unreadable object' => ['denied' => 'unreadable'],
		'unchangeable object' => ['denied' => 'unchangeable'],
		'plain refusal' => ['denied' => 'plain'],
		'frozen object' => ['frozen' => true],
		'restricted file' => ['restricted' => true],
		'no object' => ['noObject' => true],
		'object lookup fails' => ['setObjectFails' => true],
		'unknown schema' => ['contextFails' => true],
		'access rule says gone' => ['denied' => 'gone'],
		'no file' => ['noFile' => true],
		'legacy wiring' => ['legacy' => true],
		'legacy, nothing found' => ['legacy' => true, 'legacyFinds' => 'nothing'],
		'legacy frozen' => ['legacy' => true, 'frozen' => true],
		'no l10n guard' => ['noRestrictions' => true],
		'work: plain failure' => ['workFails' => 'it broke'],
		'work: already exists' => ['workFails' => 'file already exists'],
		'work: invalid characters' => ['workFails' => 'name has invalid characters'],
		'work: required' => ['workFails' => 'name is required'],
		'work: locked' => ['workFails' => 'file is locked'],
		'work: required and locked' => ['workFails' => 'unlock required, file is locked'],
		'work: not found' => ['workFails' => 'version not found'],
		'work: lock owner' => ['workFails' => 'Only the lock owner may do this'],
		'work: administrators' => ['workFails' => 'Only administrators may do this'],
		'work: gone' => ['workFails' => 'gone', 'failure' => DoesNotExistException::class],
		'work: folder gone' => ['workFails' => 'folder gone', 'failure' => NotFoundException::class],
		'work: frozen' => ['workFails' => 'frozen', 'failure' => ObjectStateWriteException::class],
		'work: refused' => ['workFails' => 'refused', 'failure' => NotAuthorizedException::class],
	];

	/** Worlds only one action meets: [action, world]. */
	private const VARIANTS = [
		'attach, no node id' => ['attach', ['params' => []]],
		'attach, node not found' => ['attach', ['noNode' => true]],
		'attach, folder lookup fails' => ['attach', ['nodeLookupFails' => true]],
		'attach, anonymous node' => ['attach', ['noNode' => true, 'anonymous' => true]],
		'chat, no messages' => ['saveChat', ['params' => ['conversation' => 'Team']]],
		'create, filename' => ['create', ['params' => ['filename' => 'f.txt', 'content' => 'x']]],
		'create, no name' => ['create', ['params' => ['content' => 'x']]],
		'create, empty name' => ['create', ['params' => ['name' => '', 'content' => 'x']]],
		'create, no content' => ['create', ['params' => ['name' => 'a']]],
		'create, empty content' => ['create', ['params' => ['name' => 'a', 'content' => '']]],
		'create, share and tags' => ['create', ['params' => ['name' => 'a', 'content' => 'x', 'share' => 'yes', 'tags' => ' a, b ']]],
		'create, share number, tag list' => ['create', ['params' => ['name' => 'a', 'content' => 'x', 'share' => 1, 'tags' => [' a ']]]],
		'create, odd share, odd tags' => ['create', ['params' => ['name' => 'a', 'content' => 'x', 'share' => [1], 'tags' => 5]]],
		'save, no name' => ['save', ['params' => ['filename' => 'f', 'content' => 'x']]],
		'save, no content' => ['save', ['params' => ['name' => 'a']]],
		'save, empty content' => ['save', ['params' => ['name' => 'a', 'content' => '']]],
		'save, zero content' => ['save', ['params' => ['name' => 'a', 'content' => '0']]],
		'save, share true' => ['save', ['params' => ['name' => 'a', 'content' => 'x', 'share' => true]]],
		'save, share text' => ['save', ['params' => ['name' => 'a', 'content' => 'x', 'share' => 'true']]],
		'save, tag text' => ['save', ['params' => ['name' => 'a', 'content' => 'x', 'tags' => ' a, b ']]],
		'save, tag list' => ['save', ['params' => ['name' => 'a', 'content' => 'x', 'tags' => [' a ']]]],
		'multipart, nothing uploaded' => ['createMultipart', ['uploads' => []]],
		'multipart, all refused' => ['createMultipart', ['uploads' => ['bad']]],
		'multipart, some refused' => ['createMultipart', ['uploads' => ['good', 'bad', 'missing']]],
		'multipart, store fails' => ['createMultipart', ['uploads' => ['good'], 'storeFails' => true]],
		'update, nothing sent' => ['update', ['params' => []]],
		'delete, service says no' => ['delete', ['deleted' => false]],
		'unlock, force' => ['unlock', ['params' => ['force' => 'true']]],
		'batch, delete' => ['batch', ['params' => ['action' => 'delete', 'fileIds' => [7]]]],
		'batch, one fails' => ['batch', ['batchFailed' => 1]],
		'batch, nothing sent' => ['batch', ['params' => []]],
		'batch, one id' => ['batch', ['params' => ['action' => 'label', 'fileIds' => 7]]],
		'preview, sized' => ['preview', ['params' => ['width' => '64', 'height' => '32']]],
		'metadata, nothing sent' => ['updateMetadata', ['params' => []]],
		'metadata, empty' => ['updateMetadata', ['params' => ['description' => '', 'category' => '', 'labels' => []]]],
		'metadata, null values' => ['updateMetadata', ['params' => ['description' => null, 'category' => null, 'labels' => null]]],
		'metadata, no object' => ['updateMetadata', ['noObject' => true, 'params' => []]],
		'form, no rows' => ['saveMetadataForm', ['params' => []]],
		'form, text' => ['saveMetadataForm', ['params' => ['files' => 'x']]],
		'labels, none' => ['updateLabels', ['params' => []]],
		'labels, text' => ['updateLabels', ['params' => ['labels' => 'a']]],
		'show, fallback in the object folder' => ['show', ['noFile' => true, 'fallback' => self::OBJECT_UUID]],
		'show, fallback in the object id folder' => ['show', ['noFile' => true, 'fallback' => '11']],
		'show, fallback in another folder' => ['show', ['noFile' => true, 'fallback' => 'other']],
		'show, fallback has no parent' => ['show', ['noFile' => true, 'fallback' => null]],
		'show, fallback lookup fails' => ['show', ['noFile' => true, 'fallbackFails' => true]],
		'show, no owner' => ['show', ['noFile' => true, 'ownerless' => true]],
		'show, unknown owner' => ['show', ['noFile' => true, 'noUsers' => true]],
		'show, odd name' => ['show', ['fileName' => "a\"b\\c\né.txt"]],
		'show, anonymous, published fallback' => ['show', ['anonymous' => true, 'published' => true, 'noFile' => true, 'fallback' => self::OBJECT_UUID]],
		'show, counter and audit fail' => ['show', ['recordFails' => true]],
		'index, anonymous, found by slug' => ['index', ['anonymous' => true, 'scopeFinds' => 'object']],
		'index, anonymous, nothing' => ['index', ['anonymous' => true, 'scopeFinds' => 'nothing']],
		'index, anonymous, two objects' => ['index', ['anonymous' => true, 'scopeFinds' => 'two']],
		'index, legacy, nothing' => ['index', ['legacy' => true, 'legacyFinds' => 'nothing']],
		'download, managed, readable' => ['downloadById', ['managed' => 'object']],
		'download, managed, unreadable' => ['downloadById', ['managed' => 'object', 'mayRead' => false]],
		'download, managed, no owner' => ['downloadById', ['managed' => 'none']],
		'download, managed, legacy' => ['downloadById', ['managed' => 'object', 'legacy' => true]],
		'download, managed, anonymous published' => ['downloadById', ['managed' => 'object', 'anonymous' => true, 'published' => true]],
		'download, managed, no audit' => ['downloadById', ['managed' => 'object', 'recordFails' => true]],
	];

	/** @var array<string, string>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var list<string> The calls made, in order. */
	private array $log = [];

	/** @var array<string, mixed> The world of the running case. */
	private array $world = [];

	private string $action = '';

	private ?File $file = null;

	private ObjectEntity $object;

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::WORK) as $action) {
			foreach (self::WORLDS as $name => $world) {
				$cases[$action . ' / ' . $name] = [$action, $world];
			}
		}

		return ($cases + self::VARIANTS);
	}//end cases()

	#[DataProvider('cases')]
	public function testTheActionAnswersAsRecorded(string $action, array $world): void {
		$name = (string)$this->dataName();
		$answer = json_encode($this->outcome(action: $action, world: $world), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded[$name] = $answer;
			$this->addToAssertionCount(1);
			return;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		}

		$this->assertArrayHasKey($name, self::$golden);
		$this->assertSame(json_encode(self::$golden[$name], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $answer);
	}//end testTheActionAnswersAsRecorded()

	public static function tearDownAfterClass(): void {
		if (self::$recorded === null) {
			return;
		}

		$lines = [];
		foreach (self::$recorded as $name => $answer) {
			$lines[] = json_encode($name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ': ' . $answer;
		}

		file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
	}//end tearDownAfterClass()

	/**
	 * Run one action and describe what came of it.
	 *
	 * @param string               $action The public action.
	 * @param array<string, mixed> $world  The world.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $action, array $world): array {
		$this->log = [];
		$this->world = $world;
		$this->action = $action;
		$this->object = new ObjectEntity();
		$this->object->setId(11);
		$this->object->setUuid(self::OBJECT_UUID);
		$this->object->setRegister('7');
		$this->object->setSchema('42');
		if (($world['ownerless'] ?? false) === false) {
			$this->object->setOwner('owner');
		}

		$this->file = $this->fileNode(id: 7, parent: self::OBJECT_UUID);
		$controller = $this->controller();

		try {
			$response = $controller->{$action}(...$this->routeArguments());
		} catch (Throwable $e) {
			return ['thrown' => get_class($e), 'message' => ($e instanceof Exception) ? $e->getMessage() : '', 'log' => $this->log];
		}

		return $this->described(response: $response) + ['log' => $this->log];
	}//end outcome()

	/**
	 * @return array<int, mixed> The route arguments of the running action.
	 */
	private function routeArguments(): array {
		return match ($this->action) {
			'attach', 'saveChat', 'create', 'save', 'createMultipart', 'batch', 'saveMetadataForm', 'index' => ['reg', 'sch', 'obj'],
			'restoreVersion' => ['reg', 'sch', 'obj', 7, 'v1'],
			'downloadById' => [7],
			default => ['reg', 'sch', 'obj', 7],
		};
	}//end routeArguments()

	/**
	 * @return array<string, mixed> The type, status, data and headers a client sees.
	 */
	private function described(Response $response): array {
		$headers = array_intersect_key($response->getHeaders(), array_flip(['Content-Type', 'Content-Disposition', 'Content-Length', 'Cache-Control']));
		$answer = ['type' => (new \ReflectionClass($response))->getShortName(), 'status' => $response->getStatus(), 'headers' => $headers];
		if (method_exists($response, 'getData') === true) {
			$answer['data'] = $response->getData();
		}

		return $answer;
	}//end described()

	private function note(string $call, mixed $detail = null): void {
		$this->log[] = $call . ' ' . json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end note()

	/**
	 * Fail when this call is the running action's work and the world says so.
	 */
	private function work(string $call): void {
		if (self::WORK[$this->action] !== $call || isset($this->world['workFails']) === false) {
			return;
		}

		throw $this->failure(message: $this->world['workFails']);
	}//end work()

	private function failure(string $message): Exception {
		$class = ($this->world['failure'] ?? Exception::class);
		if ($class === ObjectStateWriteException::class) {
			return ObjectStateWriteException::frozen($this->object);
		}

		return new $class($message);
	}//end failure()

	private function fileNode(int $id, ?string $parent): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn(($this->world['fileName'] ?? 'a.txt'));
		$file->method('getMimeType')->willReturn('text/plain');
		$file->method('getSize')->willReturn(4);
		$file->method('fopen')->willReturn('body');
		if ($parent === null) {
			$file->method('getParent')->willThrowException(new NotFoundException('no parent'));
			return $file;
		}

		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn($parent);
		$file->method('getParent')->willReturn($folder);
		return $file;
	}//end fileNode()

	private function controller(): FilesController {
		$world = $this->world;
		$mapper = $this->fileMapper();
		$access = $this->access();
		$restrictions = $this->restrictions();
		if (array_key_exists('noMapper', $world) === true) {
			$mapper = null;
		}

		if (array_key_exists('legacy', $world) === true) {
			$access = null;
		}

		if (array_key_exists('noRestrictions', $world) === true) {
			$restrictions = null;
		}

		return new FilesController(...FilesControllerFactory::args(
			request: $this->request(),
			fileService: $this->fileService(),
			objectService: $this->objectService(),
			rootFolder: $this->rootFolder(),
			userManager: $this->userManager(),
			eventDispatcher: $this->events(),
			fileMapper: $mapper,
			fileAuditHandler: $this->audit(name: 'recordAudit', fails: ($world['recordFails'] ?? false)),
			userSession: $this->userSession(),
			objectFileAccess: $access,
			attachNodeHandler: $this->attachHandler(),
			fileWriteGuard: $this->writeGuard(),
			fileRestrictions: $restrictions,
		));
	}//end controller()

	private function request(): IRequest {
		$params = ($this->world['params'] ?? self::PARAMS[$this->action] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getUploadedFile')->willReturnCallback(fn (string $key): mixed => $this->uploaded(key: $key));
		return $request;
	}//end request()

	/**
	 * @return array<string, mixed>|null The uploads of the world under a request key.
	 */
	private function uploaded(string $key): ?array {
		$uploads = ($this->world['uploads'] ?? ['good']);
		if ($key !== 'files' || $uploads === []) {
			return null;
		}

		$rows = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
		foreach ($uploads as $upload) {
			$rows['name'][] = $upload . '.txt';
			$rows['type'][] = 'text/plain';
			$rows['tmp_name'][] = self::UPLOAD;
			$rows['error'][] = UPLOAD_ERR_OK;
			$rows['size'][] = 4;
			if ($upload === 'bad') {
				$rows['error'][array_key_last($rows['error'])] = UPLOAD_ERR_PARTIAL;
			}

			if ($upload === 'missing') {
				$rows['tmp_name'][array_key_last($rows['tmp_name'])] = self::UPLOAD . '.gone';
			}
		}

		return $rows;
	}//end uploaded()

	private function userSession(): IUserSession {
		$user = $this->user();
		$session = $this->createMock(IUserSession::class);
		if (($this->world['anonymous'] ?? false) === true) {
			$user = null;
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end userSession()

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		return $user;
	}//end user()

	private function userManager(): IUserManager {
		$manager = $this->createMock(IUserManager::class);
		$manager->method('get')->willReturnCallback(function (string $uid): ?IUser {
			$this->note(call: 'user', detail: $uid);
			if (array_key_exists('noUsers', $this->world) === true && $uid === 'owner') {
				return null;
			}

			return $this->user();
		});
		return $manager;
	}//end userManager()

	private function rootFolder(): IRootFolder {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(function (string $uid): Folder {
			$this->note(call: 'userFolder', detail: $uid);
			if (array_key_exists('nodeLookupFails', $this->world) === true || array_key_exists('fallbackFails', $this->world) === true) {
				throw new NotFoundException('no folder');
			}

			$folder = $this->createMock(Folder::class);
			$folder->method('getById')->willReturn($this->nodesInFolder());
			return $folder;
		});
		return $root;
	}//end rootFolder()

	/**
	 * @return array<int, File> What a user's folder holds under an id.
	 */
	private function nodesInFolder(): array {
		if (array_key_exists('noNode', $this->world) === true) {
			return [];
		}

		if (array_key_exists('fallback', $this->world) === false && $this->action === 'show') {
			return [];
		}

		return [$this->fileNode(id: 9, parent: ($this->world['fallback'] ?? 'other'))];
	}//end nodesInFolder()

	private function events(): IEventDispatcher {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->note(call: 'event', detail: [(new \ReflectionClass($event))->getShortName(), get_object_vars($event)]);
		});
		return $events;
	}//end events()

	private function fileMapper(): FileMapper {
		$mapper = $this->createMock(FileMapper::class);
		$mapper->method('isFilePublished')->willReturnCallback(function (int $fileId): bool {
			$this->note(call: 'isFilePublished', detail: $fileId);
			return ($this->world['published'] ?? false);
		});
		$mapper->method('incrementDownloadCount')->willReturnCallback(function (int $fileId): void {
			$this->note(call: 'incrementDownloadCount', detail: $fileId);
			if (array_key_exists('recordFails', $this->world) === true) {
				throw new Exception('counter down');
			}
		});
		return $mapper;
	}//end fileMapper()

	private function audit(string $name, bool $fails = false): FileAuditHandler {
		$audit = $this->createMock(FileAuditHandler::class);
		$audit->method('logFileAction')->willReturnCallback(function (ObjectEntity $object, int $fileId, string $action, array $data = []) use ($name, $fails): void {
			$this->note(call: $name, detail: [$object->getUuid(), $fileId, $action, $data]);
			if ($fails === true) {
				throw new Exception('audit down');
			}
		});
		return $audit;
	}//end audit()

	private function refusal(): void {
		$denied = ($this->world['denied'] ?? null);
		if ($denied === 'unreadable') {
			throw new ObjectFileAccessDeniedException(httpStatus: ObjectFileAccessDeniedException::NOT_READABLE);
		}

		if ($denied === 'unchangeable') {
			throw new ObjectFileAccessDeniedException(httpStatus: ObjectFileAccessDeniedException::NOT_CHANGEABLE);
		}

		if ($denied === 'plain') {
			throw new NotAuthorizedException(message: 'no');
		}

		if ($denied === 'gone') {
			throw new DoesNotExistException('gone');
		}
	}//end refusal()

	private function access(): ObjectFileAccess {
		$access = $this->createMock(ObjectFileAccess::class);
		$access->method('readable')->willReturnCallback(function (string $register, string $schema, string $id): ObjectEntity {
			$this->note(call: 'readable', detail: [$register, $schema, $id]);
			$this->refusal();
			return $this->object;
		});
		$access->method('changeable')->willReturnCallback(function (string $register, string $schema, string $id, string $verb): ObjectEntity {
			$this->note(call: 'changeable', detail: [$register, $schema, $id, $verb]);
			$this->refusal();
			return $this->object;
		});
		$access->method('mayRead')->willReturnCallback(function (ObjectEntity $object): bool {
			$this->note(call: 'mayRead', detail: $object->getUuid());
			return ($this->world['mayRead'] ?? true);
		});
		return $access;
	}//end access()

	private function writeGuard(): FileWriteGuard {
		$guard = $this->createMock(FileWriteGuard::class);
		$guard->method('assertWritable')->willReturnCallback(function (ObjectEntity $object): void {
			$this->note(call: 'assertWritable', detail: $object->getUuid());
			if (array_key_exists('frozen', $this->world) === true) {
				throw ObjectStateWriteException::frozen($object);
			}
		});
		return $guard;
	}//end writeGuard()

	private function restrictions(): FileRestrictionGuard {
		$guard = $this->createMock(FileRestrictionGuard::class);
		$guard->method('mayRead')->willReturnCallback(function (int $fileId): bool {
			$this->note(call: 'restrictionMayRead', detail: $fileId);
			return (array_key_exists('restricted', $this->world) === false);
		});
		$guard->method('readableNodes')->willReturnCallback(function (array $nodes): array {
			$this->note(call: 'readableNodes', detail: count($nodes));
			return $nodes;
		});
		return $guard;
	}//end restrictions()

	private function attachHandler(): AttachNodeHandler {
		$handler = $this->createMock(AttachNodeHandler::class);
		$handler->method('attach')->willReturnCallback(function (): array {
			$this->note(call: 'attach');
			$this->work(call: 'attach');
			return ['attached' => [$this->file], 'skipped' => [['name' => 'x', 'reason' => 'y']]];
		});
		return $handler;
	}//end attachHandler()

	private function objectService(): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnCallback(function (string $register) use ($service): ObjectService {
			$this->note(call: 'setRegister', detail: $register);
			return $service;
		});
		$service->method('setSchema')->willReturnCallback(function (string $schema) use ($service): ObjectService {
			$this->note(call: 'setSchema', detail: $schema);
			if (array_key_exists('contextFails', $this->world) === true) {
				throw new DoesNotExistException('no schema');
			}

			return $service;
		});
		$service->method('setObject')->willReturnCallback(function (string $id) use ($service): ObjectService {
			$this->note(call: 'setObject', detail: $id);
			if (array_key_exists('setObjectFails', $this->world) === true) {
				throw new DoesNotExistException('no such object');
			}

			return $service;
		});
		$service->method('getObject')->willReturnCallback(function (): ?ObjectEntity {
			$this->note(call: 'getObject');
			if (array_key_exists('noObject', $this->world) === true) {
				return null;
			}

			return $this->object;
		});
		$service->method('find')->willReturnCallback(fn (string $id, mixed ...$rest): ?ObjectEntity => $this->found(id: $id, rest: $rest));
		return $service;
	}//end objectService()

	/**
	 * What ObjectService::find answers.
	 *
	 * @param string       $id   The identifier.
	 * @param array<mixed> $rest The named options.
	 *
	 * @return ObjectEntity|null
	 */
	private function found(string $id, array $rest): ?ObjectEntity {
		$this->note(call: 'find', detail: [$id, $rest]);
		$answer = ($this->world['scopeFinds'] ?? $this->world['legacyFinds'] ?? 'object');
		if ($answer === 'two') {
			throw new MultipleObjectsReturnedException('two');
		}

		if ($answer === 'nothing') {
			return null;
		}

		return $this->object;
	}//end found()

	private function fileService(): FileService {
		$service = $this->createMock(FileService::class);
		$this->stubStorage(service: $service);
		$this->stubReads(service: $service);
		$this->stubHandlers(service: $service);
		return $service;
	}//end fileService()

	/**
	 * @param FileService&\PHPUnit\Framework\MockObject\MockObject $service The fake.
	 */
	private function stubStorage(FileService $service): void {
		$service->method('addFile')->willReturnCallback(function (ObjectEntity|string $objectEntity, string $fileName, mixed $content, bool $share = false, array $tags = []): File {
			if ($this->action === 'saveChat') {
				$fileName = (string)preg_replace('/\d/', '#', $fileName);
				$content = (string)preg_replace('/^Saved by.*$/m', 'Saved by', (string)$content);
			}

			$this->note(call: 'addFile', detail: [$fileName, substr((string)$content, 0, 40), $share, $tags]);
			$this->work(call: 'addFile');
			if (array_key_exists('storeFails', $this->world) === true) {
				throw new Exception('store is full');
			}

			return $this->file;
		});
		$service->method('saveFile')->willReturnCallback(function (ObjectEntity $objectEntity, string $fileName, mixed $content, bool $share = false, array $tags = []): File {
			$this->note(call: 'saveFile', detail: [$fileName, $content, $share, $tags]);
			$this->work(call: 'saveFile');
			return $this->file;
		});
		$service->method('updateFile')->willReturnCallback(function (string|int $filePath, mixed $content = null, array $tags = [], ?ObjectEntity $object = null): File {
			$this->note(call: 'updateFile', detail: [$filePath, $content, $tags, $object?->getUuid()]);
			$this->work(call: 'updateFile');
			return $this->file;
		});
		$service->method('deleteFile')->willReturnCallback(function (mixed $file, ?ObjectEntity $object = null): bool {
			$this->note(call: 'deleteFile', detail: [$file, $object?->getUuid()]);
			$this->work(call: 'deleteFile');
			return ($this->world['deleted'] ?? true);
		});
		$service->method('renameFile')->willReturnCallback(function (ObjectEntity $object, int $fileId, string $newName): File {
			$this->note(call: 'renameFile', detail: [$object->getUuid(), $fileId, $newName]);
			$this->work(call: 'renameFile');
			return $this->file;
		});
		$service->method('updateFileMetadata')->willReturnCallback(function (int $fileId, ?string $description = null, ?string $category = null, ?array $labels = null): FileEntity {
			$this->note(call: 'updateFileMetadata', detail: [$fileId, $description, $category, $labels]);
			$this->work(call: 'updateFileMetadata');
			$entity = new FileEntity();
			$entity->setDescription($description);
			return $entity;
		});
	}//end stubStorage()

	/**
	 * @param FileService&\PHPUnit\Framework\MockObject\MockObject $service The fake.
	 */
	private function stubReads(FileService $service): void {
		$service->method('formatFile')->willReturnCallback(function (mixed $file): array {
			$this->note(call: 'formatFile', detail: $file->getId());
			$this->work(call: 'formatFile');
			return ['id' => $file->getId()];
		});
		$service->method('formatFiles')->willReturnCallback(function (array $files, ?array $requestParams = []): array {
			$this->note(call: 'formatFiles', detail: [count($files), $requestParams]);
			$this->work(call: 'formatFiles');
			return ['results' => array_map(static fn (File $file): int => $file->getId(), $files), 'total' => count($files)];
		});
		$service->method('getFile')->willReturnCallback(function (mixed $object = null, string|int $file = ''): ?File {
			$this->note(call: 'getFile', detail: [$object?->getUuid(), $file]);
			$this->work(call: 'getFile');
			if (array_key_exists('noFile', $this->world) === true) {
				return null;
			}

			return $this->file;
		});
		$service->method('getFiles')->willReturnCallback(function (mixed $object, ?bool $sharedFilesOnly = false): array {
			$this->note(call: 'getFiles', detail: [is_string($object) ? $object : $object->getUuid(), $sharedFilesOnly]);
			$this->work(call: 'getFiles');
			return [$this->file];
		});
		$service->method('getFileById')->willReturnCallback(function (int $fileId): ?File {
			$this->note(call: 'getFileById', detail: $fileId);
			$this->work(call: 'getFileById');
			if (array_key_exists('noFile', $this->world) === true) {
				return null;
			}

			return $this->file;
		});
		$service->method('isManagedFile')->willReturnCallback(fn (): bool => array_key_exists('managed', $this->world));
		$service->method('findObjectForFile')->willReturnCallback(function (): ?ObjectEntity {
			if (($this->world['managed'] ?? null) === 'object') {
				return $this->object;
			}

			return null;
		});
		$service->method('streamFile')->willReturnCallback(function (File $file): \OCP\AppFramework\Http\StreamResponse {
			$this->note(call: 'streamFile', detail: $file->getId());
			return new \OCP\AppFramework\Http\StreamResponse('body');
		});
	}//end stubReads()

	/**
	 * @param FileService&\PHPUnit\Framework\MockObject\MockObject $service The fake.
	 */
	private function stubHandlers(FileService $service): void {
		$service->method('getAuditHandler')->willReturnCallback(fn (): FileAuditHandler => $this->audit(name: 'audit'));
		$service->method('getVersioningHandler')->willReturnCallback(function (): FileVersioningHandler {
			$handler = $this->createMock(FileVersioningHandler::class);
			$handler->method('listVersions')->willReturnCallback(function (File $file): array {
				$this->note(call: 'listVersions', detail: $file->getId());
				$this->work(call: 'listVersions');
				return ['versions' => ['v1']];
			});
			$handler->method('restoreVersion')->willReturnCallback(function (File $file, string $versionId): bool {
				$this->note(call: 'restoreVersion', detail: [$file->getId(), $versionId]);
				$this->work(call: 'restoreVersion');
				return true;
			});
			return $handler;
		});
		$service->method('getLockHandler')->willReturnCallback(function (): FileLockHandler {
			$handler = $this->createMock(FileLockHandler::class);
			$handler->method('lockFile')->willReturnCallback(function (int $fileId): array {
				$this->note(call: 'lockFile', detail: $fileId);
				$this->work(call: 'lockFile');
				return ['locked' => true, 'fileId' => $fileId];
			});
			$handler->method('unlockFile')->willReturnCallback(function (int $fileId, bool $force = false): array {
				$this->note(call: 'unlockFile', detail: [$fileId, $force]);
				$this->work(call: 'unlockFile');
				return ['locked' => false];
			});
			return $handler;
		});
		$service->method('getBatchHandler')->willReturnCallback(function (): FileBatchHandler {
			$handler = $this->createMock(FileBatchHandler::class);
			$handler->method('runBatch')->willReturnCallback(function (FileBatchRequest $request, array|string|int $fileIds): array {
				$this->note(call: 'executeBatch', detail: [$request->object->getUuid(), $request->action, $fileIds, $request->params]);
				$this->work(call: 'executeBatch');
				return ['summary' => ['failed' => ($this->world['batchFailed'] ?? 0)]];
			});
			return $handler;
		});
		$service->method('getPreviewHandler')->willReturnCallback(function (): FilePreviewHandler {
			$handler = $this->createMock(FilePreviewHandler::class);
			$handler->method('getPreview')->willReturnCallback(function (File $file, mixed $size = null): ISimpleFile {
				$this->note(call: 'getPreview', detail: [$file->getId(), $size]);
				$this->work(call: 'getPreview');
				$preview = $this->createMock(ISimpleFile::class);
				$preview->method('read')->willReturn('thumb');
				$preview->method('getMimeType')->willReturn('image/png');
				$preview->method('getSize')->willReturn(5);
				return $preview;
			});
			return $handler;
		});
	}//end stubHandlers()
}//end class
