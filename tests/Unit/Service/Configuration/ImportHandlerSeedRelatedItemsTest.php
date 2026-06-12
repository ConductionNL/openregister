<?php

declare(strict_types=1);

namespace Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TaskService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the seed-related-items extension to ImportHandler.
 *
 * Verifies that `_relatedItems` payloads on seed objects route to the
 * right service (FileService::addFile / NoteService::createNote /
 * TaskService::createTask), that base64 file content gets decoded, and
 * that error isolation + user-context guards behave correctly.
 *
 * Tests bypass the heavy importSeedData entry point by reflecting into
 * the private processRelatedItems() method — that's where all the
 * spec-relevant logic lives, and it can be exercised without spinning
 * up the full configuration importer.
 */
class ImportHandlerSeedRelatedItemsTest extends TestCase
{
    private SchemaMapper&MockObject $schemaMapper;
    private RegisterMapper&MockObject $registerMapper;
    private MagicMapper&MockObject $objectEntityMapper;
    private ConfigurationMapper&MockObject $configurationMapper;
    private MappingMapper&MockObject $mappingMapper;
    private Client&MockObject $client;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private UploadHandler&MockObject $uploadHandler;
    private ObjectService&MockObject $objectService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->objectEntityMapper  = $this->createMock(MagicMapper::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->mappingMapper       = $this->createMock(MappingMapper::class);
        $this->client              = $this->createMock(Client::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->uploadHandler       = $this->createMock(UploadHandler::class);
        $this->objectService       = $this->createMock(ObjectService::class);
    }

    public function testFilesAreCreatedWithBase64Decoded(): void
    {
        $fileService = $this->createMock(FileService::class);
        $captured    = [];
        $fileService->expects($this->exactly(2))
            ->method('addFile')
            ->willReturnCallback(function ($obj, string $name, string $content, bool $share, array $tags) use (&$captured) {
                $captured[] = compact('name', 'content', 'share', 'tags');
                return $this->createMock(\OCP\Files\File::class);
            });

        $handler = $this->makeHandler();
        $handler->setFileService($fileService);

        $object = $this->object('uuid-1');
        $items  = [
            'files' => [
                ['name' => 'plain.txt', 'content' => 'hello world',                  'tags' => ['note']],
                ['name' => 'b64.bin',   'content' => 'base64:' . base64_encode('binary-bytes')],
            ],
        ];

        $result = ['relatedFiles' => 0, 'relatedNotes' => 0, 'relatedTasks' => 0];
        $this->invokeProcess($handler, $object, $items, 1, 2, 'My Object', true, $result);

        $this->assertSame(2, $result['relatedFiles']);
        $this->assertSame('plain.txt', $captured[0]['name']);
        $this->assertSame('hello world', $captured[0]['content']);
        $this->assertSame(['note'], $captured[0]['tags']);
        // base64: prefix must be stripped + decoded.
        $this->assertSame('binary-bytes', $captured[1]['content']);
    }

    public function testNotesAreCreatedThroughNoteService(): void
    {
        $noteService = $this->createMock(NoteService::class);
        $captured    = [];
        $noteService->expects($this->exactly(2))
            ->method('createNote')
            ->willReturnCallback(function (string $uuid, string $message) use (&$captured) {
                $captured[] = compact('uuid', 'message');
                return ['id' => count($captured)];
            });

        $handler = $this->makeHandler();
        $handler->setNoteService($noteService);
        $handler->setUserSession($this->loggedInUserSession('alice'));

        $object = $this->object('uuid-7');
        $items  = [
            'notes' => [
                ['message' => 'first note'],
                ['message' => 'second note'],
            ],
        ];

        $result = ['relatedFiles' => 0, 'relatedNotes' => 0, 'relatedTasks' => 0];
        $this->invokeProcess($handler, $object, $items, 1, 2, 'Object', true, $result);

        $this->assertSame(2, $result['relatedNotes']);
        $this->assertSame('uuid-7', $captured[0]['uuid']);
        $this->assertSame('first note', $captured[0]['message']);
        $this->assertSame('second note', $captured[1]['message']);
    }

    public function testTasksAreCreatedWithFullData(): void
    {
        $taskService = $this->createMock(TaskService::class);
        $captured    = [];
        $taskService->expects($this->once())
            ->method('createTask')
            ->willReturnCallback(function (int $r, int $s, string $uuid, string $title, array $data) use (&$captured) {
                $captured = compact('r', 's', 'uuid', 'title', 'data');
                return ['id' => 'task-1'];
            });

        $handler = $this->makeHandler();
        $handler->setTaskService($taskService);
        $handler->setUserSession($this->loggedInUserSession('alice'));

        $object = $this->object('uuid-9');
        $items  = [
            'tasks' => [
                [
                    'summary'     => 'Beoordeel bouwtekening',
                    'description' => 'extra detail',
                    'status'      => 'in-process',
                    'priority'    => 5,
                    'due'         => '2026-05-01T09:00:00Z',
                ],
            ],
        ];

        $result = ['relatedFiles' => 0, 'relatedNotes' => 0, 'relatedTasks' => 0];
        $this->invokeProcess($handler, $object, $items, 42, 7, 'My Title', true, $result);

        $this->assertSame(1, $result['relatedTasks']);
        $this->assertSame(42, $captured['r']);
        $this->assertSame(7, $captured['s']);
        $this->assertSame('uuid-9', $captured['uuid']);
        $this->assertSame('My Title', $captured['title']);
        $this->assertSame('Beoordeel bouwtekening', $captured['data']['summary']);
        $this->assertSame('extra detail', $captured['data']['description']);
        $this->assertSame('in-process', $captured['data']['status']);
        $this->assertSame(5, $captured['data']['priority']);
        $this->assertSame('2026-05-01T09:00:00Z', $captured['data']['due']);
    }

    public function testTaskFailureDoesNotBlockOtherTypes(): void
    {
        // A failing TaskService MUST NOT prevent file + note creation from
        // succeeding. The whole point of the spec's "non-fatal" rule is
        // that one missing dependency (e.g. no calendar) doesn't sink the
        // whole seed-data import.
        $fileService = $this->createMock(FileService::class);
        $noteService = $this->createMock(NoteService::class);
        $taskService = $this->createMock(TaskService::class);

        $fileService->expects($this->once())->method('addFile')->willReturn($this->createMock(\OCP\Files\File::class));
        $noteService->expects($this->once())->method('createNote')->willReturn(['id' => 1]);
        $taskService->expects($this->once())->method('createTask')->willThrowException(new \RuntimeException('no calendar'));

        // Warning must be logged for the failed task; debug summary still runs.
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $handler = $this->makeHandler();
        $handler->setFileService($fileService);
        $handler->setNoteService($noteService);
        $handler->setTaskService($taskService);
        $handler->setUserSession($this->loggedInUserSession('alice'));

        $object = $this->object('uuid-3');
        $items  = [
            'files' => [['name' => 'doc.txt', 'content' => 'x']],
            'notes' => [['message' => 'm']],
            'tasks' => [['summary' => 'will throw']],
        ];

        $result = ['relatedFiles' => 0, 'relatedNotes' => 0, 'relatedTasks' => 0];
        $this->invokeProcess($handler, $object, $items, 1, 1, 'X', true, $result);

        $this->assertSame(1, $result['relatedFiles']);
        $this->assertSame(1, $result['relatedNotes']);
        $this->assertSame(0, $result['relatedTasks'], 'task should not count when createTask throws');
    }

    public function testTasksAndNotesSkippedWhenNoUserContextButFilesRun(): void
    {
        // At occ install time there's no user session. Tasks need a CalDAV
        // calendar (user-bound), and notes need a comment author — both
        // must skip with a warning. Files attach to the object's folder
        // and don't need an actor, so they still run.
        $fileService = $this->createMock(FileService::class);
        $noteService = $this->createMock(NoteService::class);
        $taskService = $this->createMock(TaskService::class);

        $fileService->expects($this->once())->method('addFile')->willReturn($this->createMock(\OCP\Files\File::class));
        $noteService->expects($this->never())->method('createNote');
        $taskService->expects($this->never())->method('createTask');

        $handler = $this->makeHandler();
        $handler->setFileService($fileService);
        $handler->setNoteService($noteService);
        $handler->setTaskService($taskService);
        // No user session set → hasUserContext = false (driven by caller).

        $object = $this->object('uuid-4');
        $items  = [
            'files' => [['name' => 'doc.txt', 'content' => 'x']],
            'notes' => [['message' => 'm']],
            'tasks' => [['summary' => 's']],
        ];

        $result = ['relatedFiles' => 0, 'relatedNotes' => 0, 'relatedTasks' => 0];
        $this->invokeProcess($handler, $object, $items, 1, 1, 'X', false, $result);

        $this->assertSame(1, $result['relatedFiles']);
        $this->assertSame(0, $result['relatedNotes']);
        $this->assertSame(0, $result['relatedTasks']);
    }

    public function testServiceNotInjectedSkipsTypeSilently(): void
    {
        // ImportHandler must work for apps that don't seed any related
        // items — services may not be wired up. A null service is a no-op,
        // not an error.
        $handler = $this->makeHandler();
        // No service setters called.

        $object = $this->object('uuid-5');
        $items  = [
            'files' => [['name' => 'doc.txt', 'content' => 'x']],
            'notes' => [['message' => 'm']],
            'tasks' => [['summary' => 's']],
        ];

        $result = ['relatedFiles' => 0, 'relatedNotes' => 0, 'relatedTasks' => 0];
        $this->invokeProcess($handler, $object, $items, 1, 1, 'X', true, $result);

        $this->assertSame(0, $result['relatedFiles']);
        $this->assertSame(0, $result['relatedNotes']);
        $this->assertSame(0, $result['relatedTasks']);
    }

    public function testInvalidFileEntriesAreSkipped(): void
    {
        // Files without `name` or `content` must be skipped silently —
        // the seed format isn't strict-validated, so partial entries
        // shouldn't crash the importer.
        $fileService = $this->createMock(FileService::class);
        $fileService->expects($this->once())
            ->method('addFile')
            ->willReturn($this->createMock(\OCP\Files\File::class));

        $handler = $this->makeHandler();
        $handler->setFileService($fileService);

        $object = $this->object('uuid-6');
        $items  = [
            'files' => [
                ['name' => 'good.txt', 'content' => 'content'],
                ['content' => 'no name'],
                ['name' => 'no-content.txt'],
                ['name' => 'bad-content', 'content' => null],
            ],
        ];

        $result = ['relatedFiles' => 0, 'relatedNotes' => 0, 'relatedTasks' => 0];
        $this->invokeProcess($handler, $object, $items, 1, 1, 'X', true, $result);

        $this->assertSame(1, $result['relatedFiles']);
    }

    /**
     * Build an ImportHandler with all required constructor deps mocked
     * so we can exercise the related-items logic in isolation.
     */
    private function makeHandler(): ImportHandler
    {
        return new ImportHandler(
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            objectEntityMapper: $this->objectEntityMapper,
            configurationMapper: $this->configurationMapper,
            mappingMapper: $this->mappingMapper,
            client: $this->client,
            appConfig: $this->appConfig,
            logger: $this->logger,
            appDataPath: '/tmp',
            uploadHandler: $this->uploadHandler,
            objectService: $this->objectService
        );
    }

    private function object(string $uuid): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        return $object;
    }

    private function loggedInUserSession(string $uid): IUserSession
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    /**
     * @param array<string, mixed> $items
     * @param array<string, mixed> $result
     */
    private function invokeProcess(
        ImportHandler $handler,
        ObjectEntity $object,
        array $items,
        int $registerId,
        int $schemaId,
        string $title,
        bool $hasUserContext,
        array &$result
    ): void {
        $reflection = new \ReflectionClass($handler);
        $method     = $reflection->getMethod('processRelatedItems');
        $method->setAccessible(true);
        $method->invokeArgs($handler, [
            $object,
            $items,
            $registerId,
            $schemaId,
            $title,
            $hasUserContext,
            &$result,
        ]);
    }
}
