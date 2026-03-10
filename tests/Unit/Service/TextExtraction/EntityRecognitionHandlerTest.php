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
    private function createChunk(int $id, string $text, int $chunkIndex = 0, string $sourceType = 'file', int $sourceId = 1): Chunk
    {
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

    // =========================================================================
    // extractFromChunk - email detection
    // =========================================================================

    public function testExtractFromChunkDetectsEmail(): void
    {
        $text = 'Contact us at info@example.com for more information.';
        $chunk = $this->createChunk(1, $text);

        // Mock entity mapper to return a new entity on insert.
        $mockEntity = new GdprEntity();
        $ref = new ReflectionClass($mockEntity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($mockEntity, 1);

        // Mock the findEntitiesPublic to throw DoesNotExist (entity not found).
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
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $this->entityRelationMapper->expects($this->atLeastOnce())
            ->method('insert');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertGreaterThan(0, $result['entities_found']);
    }

    // =========================================================================
    // extractFromChunk - no entities in text
    // =========================================================================

    public function testExtractFromChunkReturnsEmptyWhenNoEntitiesFound(): void
    {
        // Text with no emails, phones, or IBANs.
        $text = 'This is a simple text with no personal data.';
        $chunk = $this->createChunk(1, $text);

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertSame(0, $result['entities_found']);
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
        // Metadata chunk with index -1 should be filtered out.
        $metadataChunk = $this->createChunk(1, 'metadata text', -1);
        $regularChunk = $this->createChunk(2, 'no entities here', 0);

        $this->chunkMapper->method('findBySource')
            ->willReturn([$metadataChunk, $regularChunk]);

        $result = $this->handler->processSourceChunks('file', 1, ['method' => 'regex']);

        // Only the regular chunk (index != -1) should be processed.
        $this->assertSame(1, $result['chunks_processed']);
    }

    public function testProcessSourceChunksContinuesOnChunkError(): void
    {
        $chunk1 = $this->createChunk(1, 'Contact: test@example.com', 0);
        $chunk2 = $this->createChunk(2, 'More text with test2@example.com', 1);

        $this->chunkMapper->method('findBySource')
            ->willReturn([$chunk1, $chunk2]);

        // Mock entity mapper to return entities.
        $mockEntity = new GdprEntity();
        $ref = new ReflectionClass($mockEntity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($mockEntity, 1);

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
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->processSourceChunks('file', 1, ['method' => 'regex']);

        $this->assertSame(2, $result['chunks_processed']);
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

        // Phone entities should be filtered out due to high threshold.
        $this->assertSame(0, $result['entities_found']);
    }

    // =========================================================================
    // extractFromChunk - entity type filtering
    // =========================================================================

    public function testExtractFromChunkWithEntityTypeFilter(): void
    {
        // Text containing both email and phone.
        $text = 'Email: test@example.com Phone: +31612345678';
        $chunk = $this->createChunk(1, $text);

        // Only request EMAIL entities.
        $mockEntity = new GdprEntity();
        $ref = new ReflectionClass($mockEntity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($mockEntity, 1);

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
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($mockEntity);

        $result = $this->handler->extractFromChunk($chunk, [
            'method' => 'regex',
            'entity_types' => [EntityRecognitionHandler::ENTITY_TYPE_EMAIL],
        ]);

        // Should find email but not phone.
        $this->assertGreaterThanOrEqual(1, $result['entities_found']);
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

    public function testGetCategoryForIbanType(): void
    {
        $category = $this->invokePrivateMethod('getCategoryForType', ['IBAN']);

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

    // =========================================================================
    // Private method: extractContext (via reflection)
    // =========================================================================

    public function testExtractContextWithinBounds(): void
    {
        $text = 'Hello John Doe, welcome to our platform.';

        $context = $this->invokePrivateMethod('extractContext', [$text, 6, 14, 5]);

        // Should extract context around "John Doe" with 5-char window.
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

    public function testDetectWithRegexReturnsEmptyForCleanText(): void
    {
        $entities = $this->invokePrivateMethod('detectWithRegex', [
            'This is a clean text with no PII.',
            null,
            0.5,
        ]);

        $this->assertEmpty($entities);
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
}
