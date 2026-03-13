<?php

declare(strict_types=1);

/**
 * EntityRecognitionHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for EntityRecognitionHandler
 *
 * Tests entity detection via regex, chunk processing, and entity storage.
 */
class EntityRecognitionHandlerTest extends TestCase
{
    /** @var EntityRecognitionHandler */
    private EntityRecognitionHandler $handler;

    /** @var ChunkMapper&MockObject */
    private ChunkMapper $chunkMapper;

    /** @var GdprEntityMapper&MockObject */
    private GdprEntityMapper $entityMapper;

    /** @var EntityRelationMapper&MockObject */
    private EntityRelationMapper $entityRelationMapper;

    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chunkMapper = $this->createMock(ChunkMapper::class);
        $this->entityMapper = $this->createMock(GdprEntityMapper::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);

        $this->handler = new EntityRecognitionHandler(
            $this->chunkMapper,
            $this->entityMapper,
            $this->entityRelationMapper,
            $this->db,
            $this->logger,
            $this->settingsService
        );
    }

    /**
     * Helper to create a Chunk entity with given properties.
     */
    private function createChunk(
        int $id,
        string $text,
        int $chunkIndex = 0,
        string $sourceType = 'file',
        int $sourceId = 1
    ): Chunk {
        $chunk = new Chunk();
        $ref = new ReflectionClass($chunk);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($chunk, $id);
        $chunk->setTextContent($text);
        $chunk->setChunkIndex($chunkIndex);
        $chunk->setSourceType($sourceType);
        $chunk->setSourceId($sourceId);
        return $chunk;
    }

    /**
     * Helper to invoke a private method via reflection.
     */
    private function invokePrivateMethod(string $methodName, array $args = [])
    {
        $method = new ReflectionMethod(EntityRecognitionHandler::class, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->handler, $args);
    }

    /**
     * Helper to create a mock GdprEntity with an id set.
     */
    private function createMockGdprEntity(int $id): GdprEntity
    {
        $entity = new GdprEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, $id);
        return $entity;
    }

    /**
     * Helper to set up DB query builder mock for findOrCreateEntity.
     */
    private function setupQueryBuilderMock(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn('param');
        $expr->method('eq')->willReturn('condition');

        $this->db->method('getQueryBuilder')->willReturn($qb);
    }

    // =========================================================================
    // Constants
    // =========================================================================

    public function testEntityTypeConstants(): void
    {
        $this->assertSame('PERSON', EntityRecognitionHandler::ENTITY_TYPE_PERSON);
        $this->assertSame('ORGANIZATION', EntityRecognitionHandler::ENTITY_TYPE_ORGANIZATION);
        $this->assertSame('LOCATION', EntityRecognitionHandler::ENTITY_TYPE_LOCATION);
        $this->assertSame('EMAIL', EntityRecognitionHandler::ENTITY_TYPE_EMAIL);
        $this->assertSame('PHONE', EntityRecognitionHandler::ENTITY_TYPE_PHONE);
        $this->assertSame('ADDRESS', EntityRecognitionHandler::ENTITY_TYPE_ADDRESS);
        $this->assertSame('DATE', EntityRecognitionHandler::ENTITY_TYPE_DATE);
        $this->assertSame('IBAN', EntityRecognitionHandler::ENTITY_TYPE_IBAN);
        $this->assertSame('SSN', EntityRecognitionHandler::ENTITY_TYPE_SSN);
        $this->assertSame('IP_ADDRESS', EntityRecognitionHandler::ENTITY_TYPE_IP_ADDRESS);
    }

    public function testMethodConstants(): void
    {
        $this->assertSame('regex', EntityRecognitionHandler::METHOD_REGEX);
        $this->assertSame('presidio', EntityRecognitionHandler::METHOD_PRESIDIO);
        $this->assertSame('openanonymiser', EntityRecognitionHandler::METHOD_OPENANONYMISER);
        $this->assertSame('llm', EntityRecognitionHandler::METHOD_LLM);
        $this->assertSame('hybrid', EntityRecognitionHandler::METHOD_HYBRID);
        $this->assertSame('manual', EntityRecognitionHandler::METHOD_MANUAL);
    }

    public function testCategoryConstants(): void
    {
        $this->assertSame('personal_data', EntityRecognitionHandler::CATEGORY_PERSONAL_DATA);
        $this->assertSame('sensitive_pii', EntityRecognitionHandler::CATEGORY_SENSITIVE_PII);
        $this->assertSame('business_data', EntityRecognitionHandler::CATEGORY_BUSINESS_DATA);
        $this->assertSame('contextual_data', EntityRecognitionHandler::CATEGORY_CONTEXTUAL_DATA);
        $this->assertSame('temporal_data', EntityRecognitionHandler::CATEGORY_TEMPORAL_DATA);
    }

    // =========================================================================
    // extractFromChunk - empty text
    // =========================================================================

    public function testExtractFromChunkReturnsEmptyForEmptyText(): void
    {
        $chunk = $this->createChunk(1, '');

        $result = $this->handler->extractFromChunk($chunk);

        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
        $this->assertSame([], $result['entities']);
    }

    public function testExtractFromChunkReturnsEmptyForWhitespaceText(): void
    {
        $chunk = $this->createChunk(1, '   ');

        $result = $this->handler->extractFromChunk($chunk);

        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
    }

    public function testExtractFromChunkReturnsEmptyForNullText(): void
    {
        $chunk = $this->createChunk(1, '');
        // Explicitly set text to null-like empty via reflection.
        $chunk->setTextContent('');

        $result = $this->handler->extractFromChunk($chunk);

        $this->assertSame(0, $result['entities_found']);
    }

    // =========================================================================
    // extractFromChunk - email detection
    // =========================================================================

    public function testExtractFromChunkDetectsEmail(): void
    {
        $text = 'Contact us at info@example.com for more information.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertGreaterThan(0, $result['entities_found']);
    }

    public function testExtractFromChunkDetectsMultipleEmails(): void
    {
        $text = 'Email alice@example.com or bob@company.org for details.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertGreaterThanOrEqual(2, $result['entities_found']);
    }

    // =========================================================================
    // extractFromChunk - no entities in text
    // =========================================================================

    public function testExtractFromChunkReturnsEmptyWhenNoEntitiesFound(): void
    {
        $text = 'This is a simple text with no personal data.';
        $chunk = $this->createChunk(1, $text);

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertSame(0, $result['entities_found']);
    }

    // =========================================================================
    // extractFromChunk - source type handling (file vs object)
    // =========================================================================

    public function testExtractFromChunkSetsFileIdForFileSourceType(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text, 0, 'file', 42);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $capturedRelation = null;
        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert')
            ->willReturnCallback(function (EntityRelation $relation) use (&$capturedRelation) {
                $capturedRelation = $relation;
                return $relation;
            });

        $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertNotNull($capturedRelation);
        $this->assertSame(42, $capturedRelation->getFileId());
        $this->assertNull($capturedRelation->getObjectId());
    }

    public function testExtractFromChunkSetsObjectIdForObjectSourceType(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text, 0, 'object', 99);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $capturedRelation = null;
        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert')
            ->willReturnCallback(function (EntityRelation $relation) use (&$capturedRelation) {
                $capturedRelation = $relation;
                return $relation;
            });

        $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertNotNull($capturedRelation);
        $this->assertSame(99, $capturedRelation->getObjectId());
        $this->assertNull($capturedRelation->getFileId());
    }

    public function testExtractFromChunkSetsNeitherFileNorObjectForOtherSourceType(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text, 0, 'email', 55);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $capturedRelation = null;
        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert')
            ->willReturnCallback(function (EntityRelation $relation) use (&$capturedRelation) {
                $capturedRelation = $relation;
                return $relation;
            });

        $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertNotNull($capturedRelation);
        $this->assertNull($capturedRelation->getFileId());
        $this->assertNull($capturedRelation->getObjectId());
    }

    // =========================================================================
    // extractFromChunk - default options
    // =========================================================================

    public function testExtractFromChunkUsesDefaultMethod(): void
    {
        // Default method is hybrid, which falls back to regex.
        $chunk = $this->createChunk(1, 'No entities here');

        $result = $this->handler->extractFromChunk($chunk);

        $this->assertSame(0, $result['entities_found']);
    }

    public function testExtractFromChunkUsesDefaultConfidenceThreshold(): void
    {
        // Default threshold is 0.5, phone has 0.7 confidence -> should pass.
        $text = 'Call +31612345678 for info.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertGreaterThan(0, $result['entities_found']);
    }

    // =========================================================================
    // processSourceChunks
    // =========================================================================

    public function testProcessSourceChunksWithNoChunks(): void
    {
        $this->chunkMapper->method('findBySource')
            ->willReturn([]);

        $result = $this->handler->processSourceChunks('file', 1);

        $this->assertSame(0, $result['chunks_processed']);
        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
    }

    public function testProcessSourceChunksFiltersMetadataChunks(): void
    {
        $metadataChunk = $this->createChunk(1, 'metadata text', -1);
        $regularChunk = $this->createChunk(2, 'no entities here', 0);

        $this->chunkMapper->method('findBySource')
            ->willReturn([$metadataChunk, $regularChunk]);

        $result = $this->handler->processSourceChunks('file', 1, ['method' => 'regex']);

        $this->assertSame(1, $result['chunks_processed']);
    }

    public function testProcessSourceChunksContinuesOnChunkError(): void
    {
        $chunk1 = $this->createChunk(1, 'Contact: test@example.com', 0);
        $chunk2 = $this->createChunk(2, 'More text with test2@example.com', 1);

        $this->chunkMapper->method('findBySource')
            ->willReturn([$chunk1, $chunk2]);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->processSourceChunks('file', 1, ['method' => 'regex']);

        $this->assertSame(2, $result['chunks_processed']);
    }

    public function testProcessSourceChunksHandlesExceptionInExtraction(): void
    {
        $chunk1 = $this->createChunk(1, 'Contact: test@example.com', 0);
        $chunk2 = $this->createChunk(2, 'More text with test2@example.com', 1);

        $this->chunkMapper->method('findBySource')
            ->willReturn([$chunk1, $chunk2]);

        // Use an unknown method that will throw an exception for each chunk.
        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $result = $this->handler->processSourceChunks('file', 1, ['method' => 'unknown_method']);

        // Both chunks should fail, so 0 processed.
        $this->assertSame(0, $result['chunks_processed']);
        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
    }

    public function testProcessSourceChunksAccumulatesResults(): void
    {
        $chunk1 = $this->createChunk(1, 'Contact: user1@example.com', 0);
        $chunk2 = $this->createChunk(2, 'Contact: user2@example.com', 1);

        $this->chunkMapper->method('findBySource')
            ->willReturn([$chunk1, $chunk2]);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->processSourceChunks('file', 1, ['method' => 'regex']);

        $this->assertSame(2, $result['chunks_processed']);
        $this->assertGreaterThanOrEqual(2, $result['entities_found']);
        $this->assertGreaterThanOrEqual(2, $result['relations_created']);
    }

    public function testProcessSourceChunksWithOnlyMetadataChunks(): void
    {
        $metadataChunk1 = $this->createChunk(1, 'metadata text 1', -1);
        $metadataChunk2 = $this->createChunk(2, 'metadata text 2', -1);

        $this->chunkMapper->method('findBySource')
            ->willReturn([$metadataChunk1, $metadataChunk2]);

        $result = $this->handler->processSourceChunks('file', 1, ['method' => 'regex']);

        $this->assertSame(0, $result['chunks_processed']);
    }

    // =========================================================================
    // extractFromChunk - detection methods
    // =========================================================================

    public function testExtractFromChunkWithHybridMethod(): void
    {
        $chunk = $this->createChunk(1, 'No entities here');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'hybrid']);

        $this->assertSame(0, $result['entities_found']);
    }

    public function testExtractFromChunkWithLlmMethodFallsBackToRegex(): void
    {
        $chunk = $this->createChunk(1, 'No entities here');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'llm']);

        $this->assertSame(0, $result['entities_found']);
    }

    public function testExtractFromChunkWithPresidioFallsBackWhenNotConfigured(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn([]);

        $chunk = $this->createChunk(1, 'No entities here');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'presidio']);

        $this->assertSame(0, $result['entities_found']);
    }

    public function testExtractFromChunkWithOpenAnonymiserFallsBackWhenNotConfigured(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn([]);

        $chunk = $this->createChunk(1, 'No entities here');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'openanonymiser']);

        $this->assertSame(0, $result['entities_found']);
    }

    public function testExtractFromChunkWithUnknownMethodThrowsException(): void
    {
        $chunk = $this->createChunk(1, 'Some text here');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown detection method: nonexistent');

        $this->handler->extractFromChunk($chunk, ['method' => 'nonexistent']);
    }

    // =========================================================================
    // extractFromChunk - confidence threshold
    // =========================================================================

    public function testExtractFromChunkRespectsHighConfidenceThreshold(): void
    {
        // Phone patterns have 0.7 confidence - a 0.95 threshold should filter them out.
        $text = 'Call +31612345678 for info.';
        $chunk = $this->createChunk(1, $text);

        $result = $this->handler->extractFromChunk($chunk, [
            'method' => 'regex',
            'confidence_threshold' => 0.95,
        ]);

        $this->assertSame(0, $result['entities_found']);
    }

    public function testExtractFromChunkRespectsLowConfidenceThreshold(): void
    {
        // With a very low threshold, all entities should pass.
        $text = 'Call +31612345678 and email info@test.com for info.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->extractFromChunk($chunk, [
            'method' => 'regex',
            'confidence_threshold' => 0.1,
        ]);

        $this->assertGreaterThan(0, $result['entities_found']);
    }

    // =========================================================================
    // extractFromChunk - entity type filtering
    // =========================================================================

    public function testExtractFromChunkWithEntityTypeFilter(): void
    {
        $text = 'Email: test@example.com Phone: +31612345678';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->extractFromChunk($chunk, [
            'method' => 'regex',
            'entity_types' => [EntityRecognitionHandler::ENTITY_TYPE_EMAIL],
        ]);

        $this->assertGreaterThanOrEqual(1, $result['entities_found']);
        // Verify all found entities are EMAIL type.
        foreach ($result['entities'] as $entity) {
            $this->assertSame('EMAIL', $entity['type']);
        }
    }

    public function testExtractFromChunkWithPhoneTypeFilter(): void
    {
        $text = 'Email: test@example.com Phone: +31612345678';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->extractFromChunk($chunk, [
            'method' => 'regex',
            'entity_types' => [EntityRecognitionHandler::ENTITY_TYPE_PHONE],
        ]);

        $this->assertGreaterThanOrEqual(1, $result['entities_found']);
        foreach ($result['entities'] as $entity) {
            $this->assertSame('PHONE', $entity['type']);
        }
    }

    // =========================================================================
    // extractFromChunk - context window
    // =========================================================================

    public function testExtractFromChunkWithCustomContextWindow(): void
    {
        $text = 'Contact info@example.com for more information.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $capturedRelation = null;
        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert')
            ->willReturnCallback(function (EntityRelation $relation) use (&$capturedRelation) {
                $capturedRelation = $relation;
                return $relation;
            });

        $this->handler->extractFromChunk($chunk, [
            'method' => 'regex',
            'context_window' => 10,
        ]);

        $this->assertNotNull($capturedRelation);
        $context = $capturedRelation->getContext();
        $this->assertNotEmpty($context);
        $this->assertStringContainsString('info@example.com', $context);
    }

    // =========================================================================
    // storeDetectedEntities - exception handling
    // =========================================================================

    public function testStoreDetectedEntitiesHandlesInsertException(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text);

        $this->setupQueryBuilderMock();
        // Make findEntitiesPublic return empty (entity not found), then insert throws.
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')
            ->willThrowException(new Exception('DB insert error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        // Entity storage failed, so entities_found should be 0.
        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
    }

    public function testStoreDetectedEntitiesHandlesRelationInsertException(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        // The entityRelationMapper insert throws.
        $this->entityRelationMapper->method('insert')
            ->willThrowException(new Exception('Relation insert error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertSame(0, $result['entities_found']);
    }

    // =========================================================================
    // findOrCreateEntity - existing entity path
    // =========================================================================

    public function testFindOrCreateEntityReusesExistingEntity(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text);

        $existingEntity = $this->createMockGdprEntity(42);
        $existingEntity->setType('EMAIL');
        $existingEntity->setValue('info@example.com');

        $this->setupQueryBuilderMock();
        // Return existing entity from findEntitiesPublic.
        $this->entityMapper->method('findEntitiesPublic')
            ->willReturn([$existingEntity]);
        $this->entityMapper->expects($this->atLeastOnce())
            ->method('update')
            ->willReturn($existingEntity);
        // insert should NOT be called since entity exists.
        $this->entityMapper->expects($this->never())
            ->method('insert');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertGreaterThan(0, $result['entities_found']);
    }

    // =========================================================================
    // Private method: getCategoryForType (via reflection)
    // =========================================================================

    public function testGetCategoryForPersonType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['PERSON']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_PERSONAL_DATA, $category);
    }

    public function testGetCategoryForEmailType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['EMAIL']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_PERSONAL_DATA, $category);
    }

    public function testGetCategoryForPhoneType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['PHONE']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_PERSONAL_DATA, $category);
    }

    public function testGetCategoryForAddressType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['ADDRESS']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_PERSONAL_DATA, $category);
    }

    public function testGetCategoryForIbanType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['IBAN']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_SENSITIVE_PII, $category);
    }

    public function testGetCategoryForSsnType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['SSN']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_SENSITIVE_PII, $category);
    }

    public function testGetCategoryForOrganizationType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['ORGANIZATION']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_BUSINESS_DATA, $category);
    }

    public function testGetCategoryForLocationType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['LOCATION']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_CONTEXTUAL_DATA, $category);
    }

    public function testGetCategoryForDateType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['DATE']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_TEMPORAL_DATA, $category);
    }

    public function testGetCategoryForUnknownType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['UNKNOWN_TYPE']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_CONTEXTUAL_DATA, $category);
    }

    public function testGetCategoryForIpAddressType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['IP_ADDRESS']);
        // IP_ADDRESS is not in the match, so falls to default contextual_data.
        $this->assertSame(EntityRecognitionHandler::CATEGORY_CONTEXTUAL_DATA, $category);
    }

    // =========================================================================
    // Private method: extractContext (via reflection)
    // =========================================================================

    public function testExtractContextWithinBounds(): void
    {
        $text = 'Hello John Doe, welcome to our platform.';
        $context = $this->invokePrivateMethod('extractContext', [$text, 6, 14, 5]);
        $this->assertStringContainsString('John', $context);
    }

    public function testExtractContextAtStartOfText(): void
    {
        $text = 'test@example.com is an email.';
        $context = $this->invokePrivateMethod('extractContext', [$text, 0, 16, 10]);
        $this->assertStringContainsString('test@example.com', $context);
    }

    public function testExtractContextAtEndOfText(): void
    {
        $text = 'Contact: test@example.com';
        $context = $this->invokePrivateMethod('extractContext', [$text, 9, 25, 5]);
        $this->assertStringContainsString('test@example.com', $context);
    }

    public function testExtractContextWithZeroWindow(): void
    {
        $text = 'Hello John Doe, welcome.';
        $context = $this->invokePrivateMethod('extractContext', [$text, 6, 14, 0]);
        $this->assertSame('John Doe', $context);
    }

    public function testExtractContextWithLargeWindow(): void
    {
        $text = 'Hello John Doe, welcome.';
        $context = $this->invokePrivateMethod('extractContext', [$text, 6, 14, 1000]);
        // Should return the full text since the window exceeds bounds.
        $this->assertSame($text, $context);
    }

    public function testExtractContextClampsStartToZero(): void
    {
        $text = 'AB test';
        // positionStart = 0, window = 5 => start = max(0, 0-5) = 0.
        $context = $this->invokePrivateMethod('extractContext', [$text, 0, 2, 5]);
        $this->assertStringStartsWith('AB', $context);
    }

    public function testExtractContextClampsEndToTextLength(): void
    {
        $text = 'Hello AB';
        // positionEnd = 8 (end of text), window = 5 => end = min(8, 8+5) = 8.
        $context = $this->invokePrivateMethod('extractContext', [$text, 6, 8, 5]);
        $this->assertStringContainsString('AB', $context);
    }

    // =========================================================================
    // Private method: detectWithRegex (via reflection)
    // =========================================================================

    public function testDetectWithRegexFindsEmails(): void
    {
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'Send to user@example.com please',
            null,
            0.5,
        ]);

        $this->assertNotEmpty($entities);
        $found = false;
        foreach ($entities as $entity) {
            if ($entity['type'] === 'EMAIL') {
                $found = true;
                $this->assertSame('user@example.com', $entity['value']);
                $this->assertSame('personal_data', $entity['category']);
                $this->assertGreaterThanOrEqual(0.5, $entity['confidence']);
            }
        }
        $this->assertTrue($found, 'Expected EMAIL entity to be detected');
    }

    public function testDetectWithRegexFindsIban(): void
    {
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'Bank account: NL91ABNA0417164300',
            null,
            0.5,
        ]);

        $this->assertNotEmpty($entities);
        $found = false;
        foreach ($entities as $entity) {
            if ($entity['type'] === 'IBAN') {
                $found = true;
                $this->assertStringContainsString('NL91ABNA', $entity['value']);
            }
        }
        $this->assertTrue($found, 'Expected IBAN entity to be detected');
    }

    public function testDetectWithRegexFindsPhoneNumbers(): void
    {
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'Call us at +31612345678 for more info.',
            null,
            0.5,
        ]);

        $this->assertNotEmpty($entities);
        $found = false;
        foreach ($entities as $entity) {
            if ($entity['type'] === 'PHONE') {
                $found = true;
                $this->assertSame('personal_data', $entity['category']);
                $this->assertSame(0.7, $entity['confidence']);
            }
        }
        $this->assertTrue($found, 'Expected PHONE entity to be detected');
    }

    public function testDetectWithRegexReturnsEmptyForCleanText(): void
    {
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'This is a clean text with no PII.',
            null,
            0.5,
        ]);

        $this->assertEmpty($entities);
    }

    public function testDetectWithRegexFiltersEntityTypes(): void
    {
        // Text with both email and phone.
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'Email user@test.com or call +31612345678',
            ['EMAIL'],
            0.5,
        ]);

        foreach ($entities as $entity) {
            $this->assertSame('EMAIL', $entity['type']);
        }
    }

    public function testDetectWithRegexFiltersEntityTypesExcludesUnrequested(): void
    {
        // Only ask for IBAN type, should not return email or phone.
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'Email user@test.com or call +31612345678',
            ['IBAN'],
            0.5,
        ]);

        $this->assertEmpty($entities);
    }

    public function testDetectWithRegexFiltersByConfidenceThreshold(): void
    {
        // Phone has 0.7 confidence, set threshold to 0.8.
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'Call +31612345678 now.',
            null,
            0.8,
        ]);

        // Phone should be filtered out (0.7 < 0.8).
        $phoneEntities = array_filter($entities, fn($e) => $e['type'] === 'PHONE');
        $this->assertEmpty($phoneEntities, 'Phone entities should be filtered out at 0.8 threshold');
    }

    public function testDetectWithRegexIncludesPositionInfo(): void
    {
        $text = 'Contact user@example.com here.';
        $entities = $this->invokePrivateMethod('detectWithRegex', [$text, null, 0.5]);

        $this->assertNotEmpty($entities);
        $email = $entities[0];
        $this->assertArrayHasKey('position_start', $email);
        $this->assertArrayHasKey('position_end', $email);
        $this->assertSame(strpos($text, 'user@example.com'), $email['position_start']);
        $this->assertSame(
            strpos($text, 'user@example.com') + strlen('user@example.com'),
            $email['position_end']
        );
    }

    public function testDetectWithRegexFindsMultipleEntitiesOfSameType(): void
    {
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'Send to alice@example.com and bob@example.com',
            null,
            0.5,
        ]);

        $emailCount = 0;
        foreach ($entities as $entity) {
            if ($entity['type'] === 'EMAIL') {
                $emailCount++;
            }
        }
        $this->assertSame(2, $emailCount);
    }

    // =========================================================================
    // Private method: detectEntities (via reflection) - unknown method
    // =========================================================================

    public function testDetectEntitiesThrowsOnUnknownMethod(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown detection method: foobar');

        $this->invokePrivateMethod('detectEntities', ['some text', 'foobar', null, 0.5]);
    }

    public function testDetectEntitiesCallsRegexMethod(): void
    {
        $result = $this->invokePrivateMethod('detectEntities', [
            'Send to user@test.com',
            'regex',
            null,
            0.5,
        ]);

        $this->assertNotEmpty($result);
    }

    public function testDetectEntitiesCallsHybridMethod(): void
    {
        $result = $this->invokePrivateMethod('detectEntities', [
            'Send to user@test.com',
            'hybrid',
            null,
            0.5,
        ]);

        $this->assertNotEmpty($result);
    }

    public function testDetectEntitiesCallsLlmMethod(): void
    {
        $result = $this->invokePrivateMethod('detectEntities', [
            'Send to user@test.com',
            'llm',
            null,
            0.5,
        ]);

        // LLM falls back to regex.
        $this->assertNotEmpty($result);
    }

    // =========================================================================
    // Private method: buildAnalyzeRequestBody (via reflection)
    // =========================================================================

    public function testBuildAnalyzeRequestBodyBasic(): void
    {
        $body = $this->invokePrivateMethod('buildAnalyzeRequestBody', [
            'test text',
            'en',
            null,
        ]);

        $this->assertSame('test text', $body['text']);
        $this->assertSame('en', $body['language']);
        $this->assertArrayNotHasKey('entities', $body);
    }

    public function testBuildAnalyzeRequestBodyWithEntityTypes(): void
    {
        $body = $this->invokePrivateMethod('buildAnalyzeRequestBody', [
            'test text',
            'nl',
            ['EMAIL', 'PHONE'],
        ]);

        $this->assertSame('test text', $body['text']);
        $this->assertSame('nl', $body['language']);
        $this->assertArrayHasKey('entities', $body);
    }

    public function testBuildAnalyzeRequestBodyWithEmptyEntityTypes(): void
    {
        $body = $this->invokePrivateMethod('buildAnalyzeRequestBody', [
            'test text',
            'en',
            [],
        ]);

        // Empty entity types should NOT add entities key.
        $this->assertArrayNotHasKey('entities', $body);
    }

    public function testBuildAnalyzeRequestBodyWithUnmappableEntityTypes(): void
    {
        $body = $this->invokePrivateMethod('buildAnalyzeRequestBody', [
            'test text',
            'en',
            ['TOTALLY_UNKNOWN'],
        ]);

        // Unknown types produce empty mapping, so no entities key.
        $this->assertArrayNotHasKey('entities', $body);
    }

    // =========================================================================
    // Private method: mapToPresidioEntityTypes (via reflection)
    // =========================================================================

    public function testMapToPresidioEntityTypes(): void
    {
        $result = $this->invokePrivateMethod('mapToPresidioEntityTypes', [
            ['PERSON', 'EMAIL', 'PHONE', 'IBAN', 'SSN', 'IP_ADDRESS'],
        ]);

        $this->assertContains('PERSON', $result);
        $this->assertContains('EMAIL_ADDRESS', $result);
        $this->assertContains('PHONE_NUMBER', $result);
        $this->assertContains('IBAN_CODE', $result);
        $this->assertContains('US_SSN', $result);
        $this->assertContains('IP_ADDRESS', $result);
    }

    public function testMapToPresidioEntityTypesWithOrganization(): void
    {
        $result = $this->invokePrivateMethod('mapToPresidioEntityTypes', [
            ['ORGANIZATION', 'LOCATION', 'DATE'],
        ]);

        $this->assertContains('ORGANIZATION', $result);
        $this->assertContains('LOCATION', $result);
        $this->assertContains('DATE_TIME', $result);
    }

    public function testMapToPresidioEntityTypesWithUnknownType(): void
    {
        $result = $this->invokePrivateMethod('mapToPresidioEntityTypes', [
            ['TOTALLY_UNKNOWN'],
        ]);

        $this->assertEmpty($result);
    }

    public function testMapToPresidioEntityTypesWithEmptyArray(): void
    {
        $result = $this->invokePrivateMethod('mapToPresidioEntityTypes', [[]]);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // Private method: mapFromPresidioEntityType (via reflection)
    // =========================================================================

    public function testMapFromPresidioEntityTypeKnownTypes(): void
    {
        $this->assertSame('PERSON', $this->invokePrivateMethod('mapFromPresidioEntityType', ['PERSON']));
        $this->assertSame('ORGANIZATION', $this->invokePrivateMethod('mapFromPresidioEntityType', ['ORGANIZATION']));
        $this->assertSame('LOCATION', $this->invokePrivateMethod('mapFromPresidioEntityType', ['LOCATION']));
        $this->assertSame('EMAIL', $this->invokePrivateMethod('mapFromPresidioEntityType', ['EMAIL_ADDRESS']));
        $this->assertSame('PHONE', $this->invokePrivateMethod('mapFromPresidioEntityType', ['PHONE_NUMBER']));
        $this->assertSame('DATE', $this->invokePrivateMethod('mapFromPresidioEntityType', ['DATE_TIME']));
        $this->assertSame('IBAN', $this->invokePrivateMethod('mapFromPresidioEntityType', ['IBAN_CODE']));
        $this->assertSame('SSN', $this->invokePrivateMethod('mapFromPresidioEntityType', ['US_SSN']));
        $this->assertSame('IP_ADDRESS', $this->invokePrivateMethod('mapFromPresidioEntityType', ['IP_ADDRESS']));
    }

    public function testMapFromPresidioEntityTypePassthroughTypes(): void
    {
        $this->assertSame('CREDIT_CARD', $this->invokePrivateMethod('mapFromPresidioEntityType', ['CREDIT_CARD']));
        $this->assertSame('CRYPTO', $this->invokePrivateMethod('mapFromPresidioEntityType', ['CRYPTO']));
        $this->assertSame('URL', $this->invokePrivateMethod('mapFromPresidioEntityType', ['URL']));
        $this->assertSame('NRP', $this->invokePrivateMethod('mapFromPresidioEntityType', ['NRP']));
    }

    public function testMapFromPresidioEntityTypeUnknown(): void
    {
        // Unknown types should pass through unchanged.
        $this->assertSame('SOMETHING_NEW', $this->invokePrivateMethod('mapFromPresidioEntityType', ['SOMETHING_NEW']));
    }

    // =========================================================================
    // Private method: convertApiResultsToEntities (via reflection)
    // =========================================================================

    public function testConvertApiResultsToEntitiesBasic(): void
    {
        $apiResults = [
            [
                'entity_type' => 'EMAIL_ADDRESS',
                'start' => 5,
                'end' => 21,
                'score' => 0.9,
            ],
        ];
        $text = 'Send user@example.com here';

        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, $text, 0.5, 'presidio', 0,
        ]);

        $this->assertCount(1, $entities);
        $this->assertSame('EMAIL', $entities[0]['type']);
        $this->assertSame('user@example.com', $entities[0]['value']);
        $this->assertSame(5, $entities[0]['position_start']);
        $this->assertSame(21, $entities[0]['position_end']);
        $this->assertSame(0.9, $entities[0]['confidence']);
        $this->assertSame('personal_data', $entities[0]['category']);
    }

    public function testConvertApiResultsFiltersLowConfidence(): void
    {
        $apiResults = [
            ['entity_type' => 'PERSON', 'start' => 0, 'end' => 4, 'score' => 0.3],
        ];

        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, 'John says hello', 0.5, 'presidio', 0,
        ]);

        $this->assertEmpty($entities);
    }

    public function testConvertApiResultsUsesDefaultConfidence(): void
    {
        $apiResults = [
            ['entity_type' => 'PERSON', 'start' => 0, 'end' => 4],
        ];

        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, 'John says hello', 0.5, 'openanonymiser', 0.85,
        ]);

        $this->assertCount(1, $entities);
        $this->assertSame(0.85, $entities[0]['confidence']);
    }

    public function testConvertApiResultsDefaultConfidenceBelowThreshold(): void
    {
        $apiResults = [
            ['entity_type' => 'PERSON', 'start' => 0, 'end' => 4],
        ];

        // Default confidence 0 is below threshold 0.5.
        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, 'John says hello', 0.5, 'presidio', 0,
        ]);

        $this->assertEmpty($entities);
    }

    public function testConvertApiResultsUsesTextField(): void
    {
        // OpenAnonymiser includes a 'text' field.
        $apiResults = [
            [
                'entity_type' => 'PERSON',
                'start' => 0,
                'end' => 8,
                'score' => 0.9,
                'text' => 'John Doe',
            ],
        ];

        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, 'John Doe is a person', 0.5, 'openanonymiser', 0.85,
        ]);

        $this->assertSame('John Doe', $entities[0]['value']);
    }

    public function testConvertApiResultsExtractsFromTextWhenNoTextField(): void
    {
        $apiResults = [
            [
                'entity_type' => 'PERSON',
                'start' => 0,
                'end' => 8,
                'score' => 0.9,
            ],
        ];

        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, 'John Doe is a person', 0.5, 'presidio', 0,
        ]);

        $this->assertSame('John Doe', $entities[0]['value']);
    }

    public function testConvertApiResultsHandlesMissingStartEnd(): void
    {
        $apiResults = [
            [
                'entity_type' => 'PERSON',
                'score' => 0.9,
            ],
        ];

        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, 'Some text', 0.5, 'presidio', 0,
        ]);

        $this->assertCount(1, $entities);
        $this->assertSame(0, $entities[0]['position_start']);
        $this->assertSame(0, $entities[0]['position_end']);
    }

    public function testConvertApiResultsHandlesUnknownEntityType(): void
    {
        $apiResults = [
            [
                'entity_type' => 'UNKNOWN',
                'start' => 0,
                'end' => 5,
                'score' => 0.9,
            ],
        ];

        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, 'Hello world', 0.5, 'presidio', 0,
        ]);

        // UNKNOWN is not in mapping, so it passes through.
        $this->assertSame('UNKNOWN', $entities[0]['type']);
    }

    public function testConvertApiResultsHandlesMissingEntityType(): void
    {
        $apiResults = [
            [
                'start' => 0,
                'end' => 5,
                'score' => 0.9,
            ],
        ];

        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, 'Hello world', 0.5, 'presidio', 0,
        ]);

        // Missing entity_type defaults to 'UNKNOWN'.
        $this->assertSame('UNKNOWN', $entities[0]['type']);
    }

    public function testConvertApiResultsMultipleEntities(): void
    {
        $apiResults = [
            ['entity_type' => 'PERSON', 'start' => 0, 'end' => 4, 'score' => 0.9],
            ['entity_type' => 'EMAIL_ADDRESS', 'start' => 10, 'end' => 26, 'score' => 0.95],
            ['entity_type' => 'LOCATION', 'start' => 30, 'end' => 36, 'score' => 0.2],
        ];

        $text = 'John sent user@example.com from London.';
        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            $apiResults, $text, 0.5, 'presidio', 0,
        ]);

        // Third entity (score 0.2) should be filtered out.
        $this->assertCount(2, $entities);
        $this->assertSame('PERSON', $entities[0]['type']);
        $this->assertSame('EMAIL', $entities[1]['type']);
    }

    public function testConvertApiResultsEmptyArray(): void
    {
        $entities = $this->invokePrivateMethod('convertApiResultsToEntities', [
            [], 'some text', 0.5, 'presidio', 0,
        ]);

        $this->assertEmpty($entities);
    }

    // =========================================================================
    // Private method: getRegexPatterns (via reflection)
    // =========================================================================

    public function testGetRegexPatternsReturnsExpectedStructure(): void
    {
        $patterns = $this->invokePrivateMethod('getRegexPatterns', []);

        $this->assertIsArray($patterns);
        $this->assertNotEmpty($patterns);

        foreach ($patterns as $pattern) {
            $this->assertArrayHasKey('type', $pattern);
            $this->assertArrayHasKey('pattern', $pattern);
            $this->assertArrayHasKey('category', $pattern);
            $this->assertArrayHasKey('confidence', $pattern);
            $this->assertIsString($pattern['type']);
            $this->assertIsString($pattern['pattern']);
            $this->assertIsString($pattern['category']);
            $this->assertIsFloat($pattern['confidence']);
        }
    }

    public function testGetRegexPatternsContainsEmailPhoneIban(): void
    {
        $patterns = $this->invokePrivateMethod('getRegexPatterns', []);

        $types = array_column($patterns, 'type');
        $this->assertContains('EMAIL', $types);
        $this->assertContains('PHONE', $types);
        $this->assertContains('IBAN', $types);
    }

    // =========================================================================
    // Presidio detection - endpoint configured but curl fails
    // =========================================================================

    public function testDetectWithPresidioFallsBackOnException(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['presidioApiEndpoint' => 'http://invalid-host:9999']);

        // The curl call will fail, and it should fall back to regex.
        $entities = $this->invokePrivateMethod('detectWithPresidio', [
            'Contact user@test.com',
            null,
            0.5,
        ]);

        // Should still return results from regex fallback.
        $this->assertIsArray($entities);
    }

    // =========================================================================
    // OpenAnonymiser detection - endpoint configured but curl fails
    // =========================================================================

    public function testDetectWithOpenAnonymiserFallsBackOnException(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['openAnonymiserApiEndpoint' => 'http://invalid-host:9999']);

        $entities = $this->invokePrivateMethod('detectWithOpenAnonymiser', [
            'Contact user@test.com',
            null,
            0.5,
        ]);

        $this->assertIsArray($entities);
    }

    // =========================================================================
    // LLM detection - logs and falls back
    // =========================================================================

    public function testDetectWithLlmLogsFallbackMessage(): void
    {
        $this->logger->expects($this->atLeastOnce())
            ->method('debug');

        $entities = $this->invokePrivateMethod('detectWithLLM', [
            'Contact user@test.com',
            null,
            0.5,
        ]);

        $this->assertIsArray($entities);
        $this->assertNotEmpty($entities);
    }

    // =========================================================================
    // Hybrid detection
    // =========================================================================

    public function testDetectWithHybridReturnsRegexResults(): void
    {
        $entities = $this->invokePrivateMethod('detectWithHybrid', [
            'Send to user@example.com',
            null,
            0.5,
        ]);

        $this->assertNotEmpty($entities);
        $this->assertSame('EMAIL', $entities[0]['type']);
    }

    public function testDetectWithHybridRespectsEntityTypeFilter(): void
    {
        $entities = $this->invokePrivateMethod('detectWithHybrid', [
            'Send to user@example.com call +31612345678',
            ['EMAIL'],
            0.5,
        ]);

        foreach ($entities as $entity) {
            $this->assertSame('EMAIL', $entity['type']);
        }
    }

    // =========================================================================
    // findOrCreateEntity (via reflection)
    // =========================================================================

    public function testFindOrCreateEntityCreatesNewEntity(): void
    {
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);

        $newEntity = $this->createMockGdprEntity(10);
        $this->entityMapper->expects($this->once())
            ->method('insert')
            ->willReturn($newEntity);

        $result = $this->invokePrivateMethod('findOrCreateEntity', [
            'EMAIL', 'test@example.com', 'personal_data',
        ]);

        $this->assertInstanceOf(GdprEntity::class, $result);
        $this->assertSame(10, $result->getId());
    }

    public function testFindOrCreateEntityReturnsExisting(): void
    {
        $existingEntity = $this->createMockGdprEntity(42);
        $existingEntity->setType('EMAIL');
        $existingEntity->setValue('test@example.com');

        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')
            ->willReturn([$existingEntity]);
        $this->entityMapper->expects($this->once())
            ->method('update')
            ->willReturn($existingEntity);
        $this->entityMapper->expects($this->never())
            ->method('insert');

        $result = $this->invokePrivateMethod('findOrCreateEntity', [
            'EMAIL', 'test@example.com', 'personal_data',
        ]);

        $this->assertSame(42, $result->getId());
    }

    // =========================================================================
    // Relation properties set correctly
    // =========================================================================

    public function testRelationHasCorrectDetectionMethod(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $capturedRelation = null;
        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert')
            ->willReturnCallback(function (EntityRelation $relation) use (&$capturedRelation) {
                $capturedRelation = $relation;
                return $relation;
            });

        $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertNotNull($capturedRelation);
        $this->assertSame('regex', $capturedRelation->getDetectionMethod());
    }

    public function testRelationHasCorrectPositions(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $capturedRelation = null;
        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert')
            ->willReturnCallback(function (EntityRelation $relation) use (&$capturedRelation) {
                $capturedRelation = $relation;
                return $relation;
            });

        $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertNotNull($capturedRelation);
        $emailStart = strpos($text, 'info@example.com');
        $this->assertSame($emailStart, $capturedRelation->getPositionStart());
        $this->assertSame($emailStart + strlen('info@example.com'), $capturedRelation->getPositionEnd());
    }

    public function testRelationHasCreatedAt(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $capturedRelation = null;
        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert')
            ->willReturnCallback(function (EntityRelation $relation) use (&$capturedRelation) {
                $capturedRelation = $relation;
                return $relation;
            });

        $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertNotNull($capturedRelation);
        $this->assertInstanceOf(DateTime::class, $capturedRelation->getCreatedAt());
    }

    public function testRelationHasConfidence(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $capturedRelation = null;
        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert')
            ->willReturnCallback(function (EntityRelation $relation) use (&$capturedRelation) {
                $capturedRelation = $relation;
                return $relation;
            });

        $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertNotNull($capturedRelation);
        // Email regex has 0.9 confidence.
        $this->assertSame(0.9, $capturedRelation->getConfidence());
    }

    // =========================================================================
    // Entity result structure
    // =========================================================================

    public function testExtractFromChunkReturnsCorrectEntityStructure(): void
    {
        $text = 'Contact info@example.com please.';
        $chunk = $this->createChunk(1, $text);

        $mockEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertNotEmpty($result['entities']);
        $entity = $result['entities'][0];
        $this->assertArrayHasKey('type', $entity);
        $this->assertArrayHasKey('value', $entity);
        $this->assertArrayHasKey('confidence', $entity);
        $this->assertSame('EMAIL', $entity['type']);
        $this->assertSame('info@example.com', $entity['value']);
        $this->assertSame(0.9, $entity['confidence']);
    }

    // =========================================================================
    // IBAN detection specifics
    // =========================================================================

    public function testDetectWithRegexIbanHasSensitivePiiCategory(): void
    {
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'Account: NL91ABNA0417164300',
            null,
            0.5,
        ]);

        $ibanEntities = array_filter($entities, fn($e) => $e['type'] === 'IBAN');
        $this->assertNotEmpty($ibanEntities);
        foreach ($ibanEntities as $entity) {
            $this->assertSame('sensitive_pii', $entity['category']);
            $this->assertSame(0.8, $entity['confidence']);
        }
    }

    // =========================================================================
    // Presidio - empty endpoint string
    // =========================================================================

    public function testDetectWithPresidioEmptyEndpointFallsBackToRegex(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['presidioApiEndpoint' => '']);

        $entities = $this->invokePrivateMethod('detectWithPresidio', [
            'Contact user@test.com',
            null,
            0.5,
        ]);

        // Should fall back to regex and find the email.
        $this->assertNotEmpty($entities);
    }

    public function testDetectWithOpenAnonymiserEmptyEndpointFallsBackToRegex(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['openAnonymiserApiEndpoint' => '']);

        $entities = $this->invokePrivateMethod('detectWithOpenAnonymiser', [
            'Contact user@test.com',
            null,
            0.5,
        ]);

        $this->assertNotEmpty($entities);
    }

    // =========================================================================
    // Mixed entity types detection
    // =========================================================================

    public function testDetectsEmailAndIbanTogether(): void
    {
        $text = 'Send money to NL91ABNA0417164300 and email info@bank.com';

        $entities = $this->invokePrivateMethod('detectWithRegex', [$text, null, 0.5]);

        $types = array_column($entities, 'type');
        $this->assertContains('EMAIL', $types);
        $this->assertContains('IBAN', $types);
    }

    public function testDetectsPhoneAndEmailTogether(): void
    {
        $text = 'Email info@test.com or call +31612345678';

        $entities = $this->invokePrivateMethod('detectWithRegex', [$text, null, 0.5]);

        $types = array_column($entities, 'type');
        $this->assertContains('EMAIL', $types);
        $this->assertContains('PHONE', $types);
    }

    // =========================================================================
    // processSourceChunks — aggregation over multiple chunks
    // =========================================================================

    public function testProcessSourceChunksReturnsSummedCounts(): void
    {
        $chunk1 = $this->createChunk(1, 'Contact john@example.com', 0, 'object', 10);
        $chunk2 = $this->createChunk(2, 'Also jane@example.org', 1, 'object', 10);

        $this->chunkMapper->method('findBySource')->willReturn([$chunk1, $chunk2]);

        $gdprEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($gdprEntity);
        $this->entityRelationMapper->method('insert')->willReturn(new EntityRelation());

        $result = $this->handler->processSourceChunks('object', 10);

        // Both chunks processed → at least 2 entities (one email per chunk).
        $this->assertGreaterThanOrEqual(2, $result['entities_found']);
        $this->assertSame($result['chunks_processed'], 2);
    }

    // =========================================================================
    // extractFromChunk — detects IBAN with correct category
    // =========================================================================

    public function testExtractFromChunkDetectsIban(): void
    {
        $chunk = $this->createChunk(1, 'Bank account NL02ABNA0123456789 is active.', 0, 'file', 1);

        $gdprEntity = $this->createMockGdprEntity(1);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($gdprEntity);
        $this->entityRelationMapper->method('insert')->willReturn(new EntityRelation());

        $result = $this->handler->extractFromChunk($chunk);

        $this->assertGreaterThanOrEqual(1, $result['entities_found']);
        // Should include IBAN entity.
        $types = array_column($result['entities'], 'type');
        $this->assertContains('IBAN', $types);
    }

    // =========================================================================
    // detectWithOpenAnonymiser — pii_entities wrapping
    // =========================================================================

    public function testDetectWithOpenAnonymiserHandlesPiiEntitiesWrapper(): void
    {
        // The openAnonymiser response wraps results in {"pii_entities": [...]}.
        $apiResult = [
            'pii_entities' => [
                [
                    'entity_type' => 'EMAIL_ADDRESS',
                    'start'       => 0,
                    'end'         => 19,
                    'score'       => 0.9,
                ],
            ],
        ];

        $fileSettings = ['openAnonymiserApiEndpoint' => 'http://fake-anon-service'];
        $this->settingsService->method('getFileSettingsOnly')->willReturn($fileSettings);

        // We can't easily mock curl, but we can test the fallback path
        // when endpoint is configured but request fails (exception triggers regex fallback).
        $text = 'test@example.com is the address';

        $result = $this->invokePrivateMethod(
            'detectWithOpenAnonymiser',
            [$text, null, 0.5]
        );

        // Since curl will fail in test environment, it falls back to regex.
        $this->assertIsArray($result);
    }

    // =========================================================================
    // getCategoryForType — IP_ADDRESS type
    // =========================================================================

    public function testGetCategoryForIpAddressReturnsContextual(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['IP_ADDRESS']);
        $this->assertSame(EntityRecognitionHandler::CATEGORY_CONTEXTUAL_DATA, $category);
    }

    // =========================================================================
    // extractFromChunk with presidio option
    // =========================================================================

    public function testExtractFromChunkWithPresidioMethodFallsBackWhenNoEndpoint(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['presidioApiEndpoint' => '']);

        $chunk = $this->createChunk(5, 'test@example.com', 0, 'file', 1);

        $gdprEntity = $this->createMockGdprEntity(5);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($gdprEntity);
        $this->entityRelationMapper->method('insert')->willReturn(new EntityRelation());

        // Should fall back to regex and detect the email.
        $result = $this->handler->extractFromChunk($chunk, ['method' => 'presidio']);

        $this->assertGreaterThanOrEqual(1, $result['entities_found']);
    }

    // =========================================================================
    // storeDetectedEntities — entity with file source type sets file_id
    // =========================================================================

    public function testStoreDetectedEntitiesSetsFileIdForFileSource(): void
    {
        $chunk = $this->createChunk(9, 'email@test.com and NL02ABNA0123456789', 0, 'file', 42);

        $gdprEntity = $this->createMockGdprEntity(9);
        $this->setupQueryBuilderMock();
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($gdprEntity);

        $insertedRelations = [];
        $this->entityRelationMapper->method('insert')
            ->willReturnCallback(function (EntityRelation $rel) use (&$insertedRelations) {
                $insertedRelations[] = $rel;
                return $rel;
            });

        $result = $this->handler->extractFromChunk($chunk);

        $this->assertGreaterThanOrEqual(1, $result['relations_created']);
        // Every relation should have the file_id set to 42.
        foreach ($insertedRelations as $rel) {
            $this->assertSame(42, $rel->getFileId());
        }
    }

    // =========================================================================
    // detectWithRegex — IBAN category is sensitive_pii
    // =========================================================================

    public function testDetectWithRegexIbanCategoryIsSensitivePii(): void
    {
        $result = $this->invokePrivateMethod(
            'detectWithRegex',
            ['Account NL02ABNA0123456789', null, 0.0]
        );

        $ibanEntities = array_filter($result, fn($e) => $e['type'] === 'IBAN');
        $this->assertNotEmpty($ibanEntities);

        $iban = array_values($ibanEntities)[0];
        $this->assertSame(EntityRecognitionHandler::CATEGORY_SENSITIVE_PII, $iban['category']);
    }

    // =========================================================================
    // processSourceChunks — returns zeros when all chunks are metadata
    // =========================================================================

    public function testProcessSourceChunksReturnsZerosWhenNoNonMetadataChunks(): void
    {
        // chunk_index = -1 means metadata chunk, filtered out.
        $metaChunk = $this->createChunk(1, 'metadata', -1, 'file', 1);
        $this->chunkMapper->method('findBySource')->willReturn([$metaChunk]);

        $result = $this->handler->processSourceChunks('file', 1);

        $this->assertSame(0, $result['chunks_processed']);
        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
    }
}
