<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for FileTextController::anonymizeFile bases-related behaviour.
 *
 * Covers: shape validation, persist-before-call ordering, audit trail emission,
 * retry-omit semantics, and authorization passthrough.
 *
 * @spec openspec/changes/entity-relation-grondslagen/tasks.md#task-4.2
 * @spec openspec/changes/entity-relation-grondslagen/tasks.md#task-4.3
 */
class FileTextControllerAnonymizeTest extends TestCase
{

    private FileTextController $controller;

    /** @var IRequest&MockObject */
    private IRequest $request;

    /** @var FileService&MockObject */
    private FileService $fileService;

    /** @var EntityRelationMapper&MockObject */
    private EntityRelationMapper $entityRelationMapper;

    /** @var AuditTrailMapper&MockObject */
    private AuditTrailMapper $auditTrailMapper;

    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var TextExtractionService&MockObject */
    private TextExtractionService $textExtractor;

    /** @var IndexService&MockObject */
    private IndexService $indexService;

    /** @var IAppConfig&MockObject */
    private IAppConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request              = $this->createMock(IRequest::class);
        $this->fileService          = $this->createMock(FileService::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->auditTrailMapper     = $this->createMock(AuditTrailMapper::class);
        $this->userSession          = $this->createMock(IUserSession::class);
        $this->logger               = $this->createMock(LoggerInterface::class);
        $this->textExtractor        = $this->createMock(TextExtractionService::class);
        $this->indexService         = $this->createMock(IndexService::class);
        $this->config               = $this->createMock(IAppConfig::class);

        $this->controller = new FileTextController(
            appName: 'openregister',
            request: $this->request,
            textExtractor: $this->textExtractor,
            indexService: $this->indexService,
            fileService: $this->fileService,
            entityRelationMapper: $this->entityRelationMapper,
            auditTrailMapper: $this->auditTrailMapper,
            userSession: $this->userSession,
            logger: $this->logger,
            config: $this->config
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock File node with a given name (non-anonymized by default).
     */
    private function mockFileNode(string $name = 'document.docx'): File&MockObject
    {
        $node = $this->createMock(File::class);
        $node->method('getName')->willReturn($name);
        $node->method('getId')->willReturn(999);
        $node->method('getPath')->willReturn('/user/files/'.$name);
        return $node;
    }

    /**
     * Build a minimal entity data row (from findEntitiesForFile join).
     */
    private function entityRow(int $relationId, string $value = 'John Doe', string $type = 'PERSON'): array
    {
        return [
            'relation_id'  => $relationId,
            'entity_id'    => $relationId * 10,
            'entity_value' => $value,
            'entity_type'  => $type,
            'category'     => 'personal_data',
        ];
    }

    /**
     * Build a mock EntityRelation with a given ID and optional bases.
     */
    private function mockRelation(int $id, ?array $bases = null): EntityRelation&MockObject
    {
        $relation = $this->createMock(EntityRelation::class);
        $relation->method('getId')->willReturn($id);
        $relation->method('getBases')->willReturn($bases);
        return $relation;
    }

    // -------------------------------------------------------------------------
    // Shape-validation tests (task 4.2)
    // -------------------------------------------------------------------------

    public function testRejectsBasesAsString(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => 'uuid-a']]],
        ]);

        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }

    public function testRejectsArrayWithNonStringElement(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => ['uuid-a', 42]]]],
        ]);

        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('index', $data);
        $this->assertSame(0, $data['index']);
    }

    public function testAcceptsBasesAsNullValue(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('findByFileId')->willReturn([]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => null]]],
        ]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testAcceptsBasesAsStringArray(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: null);
        $relation->expects($this->once())->method('setBases')->with(['uuid-a']);
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnArgument(0);
        $this->auditTrailMapper->method('insert')->willReturnArgument(0);
        $this->userSession->method('getUser')->willReturn(null);

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => ['uuid-a']]]],
        ]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testAcceptsBasesAsEmptyArray(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: ['uuid-old']);
        $relation->expects($this->once())->method('setBases')->with([]);
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnArgument(0);
        $this->auditTrailMapper->method('insert')->willReturnArgument(0);
        $this->userSession->method('getUser')->willReturn(null);

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => []]]],
        ]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testAcceptsArbitraryStringsInBases(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: null);
        $relation->expects($this->once())->method('setBases')->with(['not-a-uuid', '12345', '']);
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnArgument(0);
        $this->auditTrailMapper->method('insert')->willReturnArgument(0);
        $this->userSession->method('getUser')->willReturn(null);

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => ['not-a-uuid', '12345', '']]]],
        ]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testErrorBodyIdentifiesOffendingEntityIndex(): void
    {
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([
            $this->entityRow(41),
            $this->entityRow(42),
        ]);

        // Entity at index 1 has invalid bases.
        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [
                ['id' => 41, 'bases' => ['uuid-a']],
                ['id' => 42, 'bases' => ['uuid-b', 99]],
            ]],
        ]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(1, $data['index']);
    }

    // -------------------------------------------------------------------------
    // Persist-before-call ordering (task 4.2)
    // -------------------------------------------------------------------------

    public function testBasesPersistedBeforeAnonymizeDocumentCall(): void
    {
        $callOrder = [];

        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: null);
        $relation->method('setBases')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'setBases';
        });
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'update';
            return func_get_arg(0);
        });
        $this->auditTrailMapper->method('insert')->willReturnArgument(0);
        $this->userSession->method('getUser')->willReturn(null);

        $this->fileService->expects($this->once())
            ->method('anonymizeDocument')
            ->willReturnCallback(function () use (&$callOrder, $anonymizedNode) {
                $callOrder[] = 'anonymizeDocument';
                return $anonymizedNode;
            });

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => ['uuid-a']]]],
        ]);

        $this->controller->anonymizeFile(1);

        $updatePos         = array_search('update', $callOrder);
        $anonymizePos      = array_search('anonymizeDocument', $callOrder);
        $this->assertLessThan($anonymizePos, $updatePos, 'Bases must be persisted before anonymizeDocument is called.');
    }

    public function testAnonymizeDocumentReceivesNoBasesField(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: null);
        $relation->method('setBases')->willReturnSelf();
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnArgument(0);
        $this->auditTrailMapper->method('insert')->willReturnArgument(0);
        $this->userSession->method('getUser')->willReturn(null);

        $capturedEntities = null;
        $this->fileService->expects($this->once())
            ->method('anonymizeDocument')
            ->willReturnCallback(function ($node, $entities) use (&$capturedEntities, $anonymizedNode) {
                $capturedEntities = $entities;
                return $anonymizedNode;
            });

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => ['uuid-a']]]],
        ]);

        $this->controller->anonymizeFile(1);

        $this->assertNotNull($capturedEntities);
        foreach ($capturedEntities as $entity) {
            $this->assertArrayNotHasKey('bases', $entity, 'bases must be stripped before forwarding.');
        }
    }

    // -------------------------------------------------------------------------
    // Audit trail tests (task 4.3)
    // -------------------------------------------------------------------------

    public function testFirstTimeSetEmitsAuditEntryWithPreviousNull(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: null);
        $relation->method('setBases')->willReturnSelf();
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $user->method('getDisplayName')->willReturn('User One');
        $this->userSession->method('getUser')->willReturn($user);

        $capturedAudit = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (AuditTrail $audit) use (&$capturedAudit) {
                $capturedAudit = $audit;
                return $audit;
            });

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => ['uuid-a']]]],
        ]);

        $this->controller->anonymizeFile(1);

        $this->assertNotNull($capturedAudit);
        $this->assertSame('entity_relation_bases_set', $capturedAudit->getAction());
        $changed = $capturedAudit->getChanged();
        $this->assertNull($changed['bases']['old']);
        $this->assertSame(['uuid-a'], $changed['bases']['new']);
    }

    public function testUpdateBasesEmitsAuditEntryWithOldAndNew(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: ['uuid-a']);
        $relation->method('setBases')->willReturnSelf();
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnArgument(0);

        $this->userSession->method('getUser')->willReturn(null);

        $capturedAudit = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (AuditTrail $audit) use (&$capturedAudit) {
                $capturedAudit = $audit;
                return $audit;
            });

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => ['uuid-a', 'uuid-b']]]],
        ]);

        $this->controller->anonymizeFile(1);

        $this->assertNotNull($capturedAudit);
        $changed = $capturedAudit->getChanged();
        $this->assertSame(['uuid-a'], $changed['bases']['old']);
        $this->assertSame(['uuid-a', 'uuid-b'], $changed['bases']['new']);
    }

    public function testAuditEntryUsesUidNotDisplayName(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: null);
        $relation->method('setBases')->willReturnSelf();
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('uid-123');
        $user->method('getDisplayName')->willReturn('Display Name');
        $this->userSession->method('getUser')->willReturn($user);

        $capturedAudit = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (AuditTrail $audit) use (&$capturedAudit) {
                $capturedAudit = $audit;
                return $audit;
            });

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => ['uuid-a']]]],
        ]);

        $this->controller->anonymizeFile(1);

        $this->assertNotNull($capturedAudit);
        $this->assertSame('uid-123', $capturedAudit->getUser());
    }

    // -------------------------------------------------------------------------
    // Retry-omit semantics (task 4.3)
    // -------------------------------------------------------------------------

    public function testRetryWithoutBasesPreservesPersistedValue(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: ['uuid-a']);
        // setBases must NOT be called when 'bases' key is absent.
        $relation->expects($this->never())->method('setBases');
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);

        // No audit entries for absent-key retry.
        $this->auditTrailMapper->expects($this->never())->method('insert');
        $this->userSession->method('getUser')->willReturn(null);

        // Request has entity entry but WITHOUT bases key (absent).
        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42]]],
        ]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testRetryWithExplicitNullClearsPersistedBases(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: ['uuid-a']);
        $relation->expects($this->once())->method('setBases')->with(null);
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnArgument(0);
        $this->auditTrailMapper->method('insert')->willReturnArgument(0);
        $this->userSession->method('getUser')->willReturn(null);

        // Request has bases: null (explicit clear).
        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => null]]],
        ]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testRetryWithNewBasesOverwritesPersistedValue(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        $relation = $this->mockRelation(id: 42, bases: ['uuid-a']);
        $relation->expects($this->once())->method('setBases')->with(['uuid-b']);
        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);
        $this->entityRelationMapper->method('update')->willReturnArgument(0);
        $this->auditTrailMapper->method('insert')->willReturnArgument(0);
        $this->userSession->method('getUser')->willReturn(null);

        $this->request->method('getParam')->willReturnMap([
            ['entities', null, [['id' => 42, 'bases' => ['uuid-b']]]],
        ]);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    // -------------------------------------------------------------------------
    // Authorization tests (task 4.3)
    // -------------------------------------------------------------------------

    public function testReturns404WhenFileNotFound(): void
    {
        $this->fileService->method('getFileById')->willReturn(null);
        $this->request->method('getParam')->willReturn(null);

        $response = $this->controller->anonymizeFile(9999);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testNoBasisPersistWhenFileNotFound(): void
    {
        $this->fileService->method('getFileById')->willReturn(null);
        $this->request->method('getParam')->willReturn(null);

        $this->entityRelationMapper->expects($this->never())->method('update');
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $this->controller->anonymizeFile(9999);
    }

    // -------------------------------------------------------------------------
    // Backward-compatibility (no bases in request = old behaviour)
    // -------------------------------------------------------------------------

    public function testPreChangeBehaviourWhenNoBasisInRequest(): void
    {
        $anonymizedNode = $this->mockFileNode('document_anonymized.docx');
        $this->fileService->method('getFileById')->willReturn($this->mockFileNode());
        $this->fileService->method('anonymizeDocument')->willReturn($anonymizedNode);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([$this->entityRow(42)]);
        $this->entityRelationMapper->method('markAsAnonymized')->willReturn(1);

        // No bases-related calls expected when request has no entities.
        $this->entityRelationMapper->expects($this->never())->method('findByFileId');
        $this->entityRelationMapper->expects($this->never())->method('update');
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $this->request->method('getParam')->willReturn(null);

        $response = $this->controller->anonymizeFile(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }
}//end class
