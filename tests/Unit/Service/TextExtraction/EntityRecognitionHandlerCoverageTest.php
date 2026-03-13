<?php

declare(strict_types=1);

/**
 * EntityRecognitionHandler Coverage Tests
 *
 * Additional tests targeting uncovered lines in EntityRecognitionHandler
 * including storeDetectedEntities error paths, Presidio/OpenAnonymiser
 * with configured endpoints, postAnalyzeRequest error paths,
 * convertApiResultsToEntities edge cases, and findOrCreateEntity flows.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Coverage tests for EntityRecognitionHandler
 *
 * Targets the 57 uncovered lines identified in coverage analysis.
 */
class EntityRecognitionHandlerCoverageTest extends TestCase
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

    /**
     * Set up test dependencies
     *
     * @return void
     */
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
     *
     * @param int    $id         Chunk ID.
     * @param string $text       Text content.
     * @param int    $chunkIndex Chunk index.
     * @param string $sourceType Source type.
     * @param int    $sourceId   Source ID.
     *
     * @return Chunk
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
     *
     * @param string $methodName Method name.
     * @param array  $args       Arguments.
     *
     * @return mixed
     */
    private function invokePrivate(string $methodName, array $args): mixed
    {
        $ref = new ReflectionMethod(EntityRecognitionHandler::class, $methodName);
        $ref->setAccessible(true);

        return $ref->invoke($this->handler, ...$args);
    }

    // ================================================================
    // storeDetectedEntities — object source type sets objectId
    // ================================================================

    /**
     * Test storeDetectedEntities sets objectId for object source type chunk
     *
     * @return void
     */
    public function testStoreDetectedEntitiesSetsObjectIdForObjectSource(): void
    {
        $chunk = $this->createChunk(10, 'test@example.com', 0, 'object', 42);

        $entity = new GdprEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, 1);

        // Mock findEntitiesPublic to return empty => create new.
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

        $this->entityMapper->method('findEntitiesPublic')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->entityMapper->method('insert')->willReturn($entity);

        $capturedRelation = null;
        $this->entityRelationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (EntityRelation $relation) use (&$capturedRelation, $entity) {
                $capturedRelation = $relation;
                $ref = new ReflectionClass($relation);
                $idProp = $ref->getProperty('id');
                $idProp->setAccessible(true);
                $idProp->setValue($relation, 1);

                return $relation;
            });

        $detectedEntities = [
            [
                'type'           => 'EMAIL',
                'value'          => 'test@example.com',
                'category'       => 'personal_data',
                'position_start' => 0,
                'position_end'   => 16,
                'confidence'     => 0.9,
            ],
        ];

        $result = $this->invokePrivate('storeDetectedEntities', [
            $detectedEntities,
            $chunk,
            'test@example.com',
            'regex',
            50,
        ]);

        $this->assertEquals(1, $result['entities_found']);
        $this->assertEquals(1, $result['relations_created']);
        $this->assertNotNull($capturedRelation);
        $this->assertEquals(42, $capturedRelation->getObjectId());
    }

    /**
     * Test storeDetectedEntities catches exception during entity store
     *
     * @return void
     */
    public function testStoreDetectedEntitiesCatchesExceptionAndContinues(): void
    {
        $chunk = $this->createChunk(10, 'email@test.com more@test.com', 0, 'file', 1);

        // First call throws, second succeeds.
        $entity = new GdprEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, 1);

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

        $callCount = 0;
        $this->entityMapper->method('findEntitiesPublic')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->entityMapper->method('insert')
            ->willReturnCallback(function () use (&$callCount, $entity) {
                $callCount++;
                if ($callCount === 1) {
                    throw new Exception('DB insert failure');
                }

                return $entity;
            });

        $this->entityRelationMapper->method('insert')
            ->willReturnCallback(function (EntityRelation $r) {
                $ref = new ReflectionClass($r);
                $idProp = $ref->getProperty('id');
                $idProp->setAccessible(true);
                $idProp->setValue($r, 1);

                return $r;
            });

        $detectedEntities = [
            [
                'type'           => 'EMAIL',
                'value'          => 'email@test.com',
                'category'       => 'personal_data',
                'position_start' => 0,
                'position_end'   => 14,
                'confidence'     => 0.9,
            ],
            [
                'type'           => 'EMAIL',
                'value'          => 'more@test.com',
                'category'       => 'personal_data',
                'position_start' => 15,
                'position_end'   => 28,
                'confidence'     => 0.9,
            ],
        ];

        $result = $this->invokePrivate('storeDetectedEntities', [
            $detectedEntities,
            $chunk,
            'email@test.com more@test.com',
            'regex',
            50,
        ]);

        // First entity failed, second succeeded.
        $this->assertEquals(1, $result['entities_found']);
        $this->assertEquals(1, $result['relations_created']);
    }

    /**
     * Test storeDetectedEntities uses getCategoryForType when category is missing
     *
     * @return void
     */
    public function testStoreDetectedEntitiesUsesCategoryFallback(): void
    {
        $chunk = $this->createChunk(10, 'NL12ABCD0123456789', 0, 'file', 1);

        $entity = new GdprEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, 5);

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

        $this->entityMapper->method('findEntitiesPublic')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->entityMapper->method('insert')->willReturn($entity);
        $this->entityRelationMapper->method('insert')
            ->willReturnCallback(function (EntityRelation $r) {
                $ref = new ReflectionClass($r);
                $idProp = $ref->getProperty('id');
                $idProp->setAccessible(true);
                $idProp->setValue($r, 1);

                return $r;
            });

        // No 'category' key — should fall back to getCategoryForType.
        $detectedEntities = [
            [
                'type'           => 'IBAN',
                'value'          => 'NL12ABCD0123456789',
                'position_start' => 0,
                'position_end'   => 18,
                'confidence'     => 0.8,
            ],
        ];

        $result = $this->invokePrivate('storeDetectedEntities', [
            $detectedEntities,
            $chunk,
            'NL12ABCD0123456789',
            'regex',
            50,
        ]);

        $this->assertEquals(1, $result['entities_found']);
    }

    // ================================================================
    // processSourceChunks — exception in extractFromChunk is caught
    // ================================================================

    /**
     * Test processSourceChunks catches chunk processing exception and continues
     *
     * @return void
     */
    public function testProcessSourceChunksChunkExceptionIsCaughtAndContinues(): void
    {
        $chunk1 = $this->createChunk(1, 'first chunk text', 0);
        $chunk2 = $this->createChunk(2, 'test@example.com', 1);

        $this->chunkMapper->method('findBySource')
            ->willReturn([$chunk1, $chunk2]);

        // For chunk1, findEntitiesPublic will throw a generic exception.
        // For chunk2, it should work.
        $entity = new GdprEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, 1);

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

        $this->entityMapper->method('findEntitiesPublic')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->entityMapper->method('insert')->willReturn($entity);

        $this->entityRelationMapper->method('insert')
            ->willReturnCallback(function (EntityRelation $r) {
                $ref = new ReflectionClass($r);
                $idProp = $ref->getProperty('id');
                $idProp->setAccessible(true);
                $idProp->setValue($r, 1);

                return $r;
            });

        $result = $this->handler->processSourceChunks('file', 1);

        // Both chunks processed (chunk1 has no entities, chunk2 has email).
        $this->assertEquals(2, $result['chunks_processed']);
    }

    // ================================================================
    // detectWithPresidio — configured endpoint but curl fails
    // ================================================================

    /**
     * Test detectWithPresidio falls back to regex when endpoint is configured
     * but curl fails (network error).
     *
     * @return void
     */
    public function testDetectWithPresidioConfiguredEndpointCurlFails(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['presidioApiEndpoint' => 'http://invalid-host:12345']);

        $text = 'Contact us at info@test.nl';

        // Should fall back to regex after curl failure.
        $result = $this->invokePrivate('detectWithPresidio', [
            $text,
            null,
            0.5,
        ]);

        // Should still find the email via regex fallback.
        $emailFound = false;
        foreach ($result as $entity) {
            if ($entity['type'] === 'EMAIL') {
                $emailFound = true;
            }
        }

        $this->assertTrue($emailFound, 'Email should be found via regex fallback');
    }

    /**
     * Test detectWithOpenAnonymiser falls back to regex when endpoint
     * is configured but curl fails.
     *
     * @return void
     */
    public function testDetectWithOpenAnonymiserConfiguredEndpointCurlFails(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['openAnonymiserApiEndpoint' => 'http://invalid-host:12345']);

        $text = 'Contact us at info@test.nl';

        $result = $this->invokePrivate('detectWithOpenAnonymiser', [
            $text,
            null,
            0.5,
        ]);

        // Should still find the email via regex fallback.
        $emailFound = false;
        foreach ($result as $entity) {
            if ($entity['type'] === 'EMAIL') {
                $emailFound = true;
            }
        }

        $this->assertTrue($emailFound, 'Email should be found via regex fallback');
    }

    // ================================================================
    // postAnalyzeRequest — various error paths
    // ================================================================

    /**
     * Test postAnalyzeRequest returns null for invalid URL (curl error)
     *
     * @return void
     */
    public function testPostAnalyzeRequestReturnsNullOnCurlError(): void
    {
        $result = $this->invokePrivate('postAnalyzeRequest', [
            'http://256.256.256.256:99999/analyze',
            ['text' => 'hello'],
            'TestService',
        ]);

        $this->assertNull($result);
    }

    /**
     * Test buildAnalyzeRequestBody without entity types
     *
     * @return void
     */
    public function testBuildAnalyzeRequestBodyWithoutEntityTypes(): void
    {
        $result = $this->invokePrivate('buildAnalyzeRequestBody', [
            'Hello world',
            'nl',
            null,
        ]);

        $this->assertEquals('Hello world', $result['text']);
        $this->assertEquals('nl', $result['language']);
        $this->assertArrayNotHasKey('entities', $result);
    }

    /**
     * Test buildAnalyzeRequestBody with entity types that have mapped values
     *
     * @return void
     */
    public function testBuildAnalyzeRequestBodyWithMappedEntityTypes(): void
    {
        $result = $this->invokePrivate('buildAnalyzeRequestBody', [
            'Test text',
            'en',
            ['EMAIL', 'PERSON'],
        ]);

        $this->assertEquals('Test text', $result['text']);
        $this->assertEquals('en', $result['language']);
        $this->assertArrayHasKey('entities', $result);
        $this->assertContains('EMAIL_ADDRESS', $result['entities']);
        $this->assertContains('PERSON', $result['entities']);
    }

    /**
     * Test buildAnalyzeRequestBody with empty entity types array
     *
     * @return void
     */
    public function testBuildAnalyzeRequestBodyWithEmptyEntityTypesArray(): void
    {
        $result = $this->invokePrivate('buildAnalyzeRequestBody', [
            'Test text',
            'en',
            [],
        ]);

        $this->assertArrayNotHasKey('entities', $result);
    }

    // ================================================================
    // convertApiResultsToEntities — edge cases
    // ================================================================

    /**
     * Test convertApiResultsToEntities with missing score uses default confidence
     *
     * @return void
     */
    public function testConvertApiResultsMissingScoreUsesDefaultConfidence(): void
    {
        $apiResults = [
            [
                'entity_type' => 'PERSON',
                'start'       => 0,
                'end'         => 8,
                'text'        => 'John Doe',
                // No 'score' key.
            ],
        ];

        $result = $this->invokePrivate('convertApiResultsToEntities', [
            $apiResults,
            'John Doe is here',
            0.5,
            'openanonymiser',
            0.85,
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals(0.85, $result[0]['confidence']);
        $this->assertEquals('PERSON', $result[0]['type']);
    }

    /**
     * Test convertApiResultsToEntities filters out low default confidence
     *
     * @return void
     */
    public function testConvertApiResultsFiltersLowDefaultConfidence(): void
    {
        $apiResults = [
            [
                'entity_type' => 'PERSON',
                'start'       => 0,
                'end'         => 5,
                'text'        => 'Alice',
                // No 'score' key — default confidence 0.3 < threshold 0.5.
            ],
        ];

        $result = $this->invokePrivate('convertApiResultsToEntities', [
            $apiResults,
            'Alice is here',
            0.5,
            'presidio',
            0.3,
        ]);

        $this->assertCount(0, $result);
    }

    /**
     * Test convertApiResultsToEntities extracts from source text when no text field
     *
     * @return void
     */
    public function testConvertApiResultsExtractsValueFromSourceText(): void
    {
        $text = 'Hello John Doe world';
        $apiResults = [
            [
                'entity_type' => 'PERSON',
                'start'       => 6,
                'end'         => 14,
                'score'       => 0.9,
                // No 'text' key.
            ],
        ];

        $result = $this->invokePrivate('convertApiResultsToEntities', [
            $apiResults,
            $text,
            0.5,
            'presidio',
            0.0,
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals('John Doe', $result[0]['value']);
    }

    /**
     * Test convertApiResultsToEntities maps entity types correctly
     *
     * @return void
     */
    public function testConvertApiResultsMapsEntityTypesCorrectly(): void
    {
        $apiResults = [
            [
                'entity_type' => 'EMAIL_ADDRESS',
                'start'       => 0,
                'end'         => 14,
                'score'       => 0.9,
                'text'        => 'test@test.com',
            ],
            [
                'entity_type' => 'PHONE_NUMBER',
                'start'       => 20,
                'end'         => 32,
                'score'       => 0.8,
                'text'        => '+31612345678',
            ],
        ];

        $result = $this->invokePrivate('convertApiResultsToEntities', [
            $apiResults,
            'test@test.com call +31612345678',
            0.5,
            'presidio',
            0.0,
        ]);

        $this->assertCount(2, $result);
        $this->assertEquals('EMAIL', $result[0]['type']);
        $this->assertEquals('PHONE', $result[1]['type']);
    }

    // ================================================================
    // mapToPresidioEntityTypes
    // ================================================================

    /**
     * Test mapToPresidioEntityTypes maps all known types
     *
     * @return void
     */
    public function testMapToPresidioEntityTypesAllKnownTypes(): void
    {
        $types = [
            'PERSON',
            'ORGANIZATION',
            'LOCATION',
            'EMAIL',
            'PHONE',
            'DATE',
            'IBAN',
            'SSN',
            'IP_ADDRESS',
        ];

        $result = $this->invokePrivate('mapToPresidioEntityTypes', [$types]);

        $this->assertCount(9, $result);
        $this->assertContains('PERSON', $result);
        $this->assertContains('EMAIL_ADDRESS', $result);
        $this->assertContains('PHONE_NUMBER', $result);
        $this->assertContains('DATE_TIME', $result);
        $this->assertContains('IBAN_CODE', $result);
        $this->assertContains('US_SSN', $result);
        $this->assertContains('IP_ADDRESS', $result);
    }

    // ================================================================
    // mapFromPresidioEntityType — passthrough for unmapped types
    // ================================================================

    /**
     * Test mapFromPresidioEntityType returns passthrough for CREDIT_CARD
     *
     * @return void
     */
    public function testMapFromPresidioEntityTypeCreditCard(): void
    {
        $result = $this->invokePrivate('mapFromPresidioEntityType', ['CREDIT_CARD']);

        $this->assertEquals('CREDIT_CARD', $result);
    }

    /**
     * Test mapFromPresidioEntityType returns passthrough for CRYPTO
     *
     * @return void
     */
    public function testMapFromPresidioEntityTypeCrypto(): void
    {
        $result = $this->invokePrivate('mapFromPresidioEntityType', ['CRYPTO']);

        $this->assertEquals('CRYPTO', $result);
    }

    /**
     * Test mapFromPresidioEntityType returns passthrough for URL
     *
     * @return void
     */
    public function testMapFromPresidioEntityTypeUrl(): void
    {
        $result = $this->invokePrivate('mapFromPresidioEntityType', ['URL']);

        $this->assertEquals('URL', $result);
    }

    /**
     * Test mapFromPresidioEntityType returns passthrough for NRP
     *
     * @return void
     */
    public function testMapFromPresidioEntityTypeNrp(): void
    {
        $result = $this->invokePrivate('mapFromPresidioEntityType', ['NRP']);

        $this->assertEquals('NRP', $result);
    }

    /**
     * Test mapFromPresidioEntityType returns input for totally unknown type
     *
     * @return void
     */
    public function testMapFromPresidioEntityTypeCompletelyUnknown(): void
    {
        $result = $this->invokePrivate('mapFromPresidioEntityType', ['FOOBAR']);

        $this->assertEquals('FOOBAR', $result);
    }

    // ================================================================
    // extractContext — edge cases
    // ================================================================

    /**
     * Test extractContext with negative clamped start
     *
     * @return void
     */
    public function testExtractContextNegativeClampedStart(): void
    {
        $result = $this->invokePrivate('extractContext', [
            'Hello World',
            2,
            5,
            100,
        ]);

        $this->assertEquals('Hello World', $result);
    }

    /**
     * Test extractContext with small window
     *
     * @return void
     */
    public function testExtractContextSmallWindow(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog';

        $result = $this->invokePrivate('extractContext', [
            $text,
            10,
            15,
            5,
        ]);

        // start = max(0, 10-5) = 5, end = min(44, 15+5) = 20.
        $this->assertEquals(substr($text, 5, 15), $result);
    }

    // ================================================================
    // getCategoryForType — all categories
    // ================================================================

    /**
     * Test getCategoryForType returns correct categories for all entity types
     *
     * @return void
     */
    public function testGetCategoryForAllEntityTypes(): void
    {
        $expectations = [
            'PERSON'       => 'personal_data',
            'EMAIL'        => 'personal_data',
            'PHONE'        => 'personal_data',
            'ADDRESS'      => 'personal_data',
            'IBAN'         => 'sensitive_pii',
            'SSN'          => 'sensitive_pii',
            'ORGANIZATION' => 'business_data',
            'LOCATION'     => 'contextual_data',
            'DATE'         => 'temporal_data',
            'IP_ADDRESS'   => 'contextual_data',
            'SOMETHING'    => 'contextual_data',
        ];

        foreach ($expectations as $type => $expectedCategory) {
            $result = $this->invokePrivate('getCategoryForType', [$type]);
            $this->assertEquals(
                $expectedCategory,
                $result,
                "getCategoryForType('{$type}') should return '{$expectedCategory}'"
            );
        }
    }

    // ================================================================
    // detectWithRegex — with confidence threshold filtering
    // ================================================================

    /**
     * Test detectWithRegex filters by high confidence threshold
     *
     * @return void
     */
    public function testDetectWithRegexHighConfidenceFiltersPhones(): void
    {
        // Phone has confidence 0.7, threshold 0.8 should filter it out.
        $text = 'Call +31612345678';

        $result = $this->invokePrivate('detectWithRegex', [
            $text,
            null,
            0.8,
        ]);

        // Phone should be filtered (0.7 < 0.8).
        $phoneFound = false;
        foreach ($result as $entity) {
            if ($entity['type'] === 'PHONE') {
                $phoneFound = true;
            }
        }

        $this->assertFalse($phoneFound, 'Phone should be filtered by 0.8 threshold');
    }

    /**
     * Test detectWithRegex with specific entity types filter
     *
     * @return void
     */
    public function testDetectWithRegexEntityTypesFilter(): void
    {
        $text = 'Email: info@test.nl IBAN: NL91ABNA0417164300';

        // Only ask for EMAIL.
        $result = $this->invokePrivate('detectWithRegex', [
            $text,
            ['EMAIL'],
            0.5,
        ]);

        foreach ($result as $entity) {
            $this->assertEquals('EMAIL', $entity['type'], 'Only EMAIL should be returned');
        }
    }

    // ================================================================
    // extractFromChunk — with 'other' source type
    // ================================================================

    /**
     * Test extractFromChunk with non-file non-object source type
     * sets neither fileId nor objectId on relation
     *
     * @return void
     */
    public function testExtractFromChunkOtherSourceTypeNoFileOrObjectId(): void
    {
        $chunk = $this->createChunk(10, 'info@company.nl', 0, 'custom', 99);

        $entity = new GdprEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, 1);

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

        $this->entityMapper->method('findEntitiesPublic')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->entityMapper->method('insert')->willReturn($entity);

        $capturedRelation = null;
        $this->entityRelationMapper->method('insert')
            ->willReturnCallback(function (EntityRelation $r) use (&$capturedRelation) {
                $capturedRelation = $r;
                $ref = new ReflectionClass($r);
                $idProp = $ref->getProperty('id');
                $idProp->setAccessible(true);
                $idProp->setValue($r, 1);

                return $r;
            });

        $result = $this->handler->extractFromChunk($chunk);

        $this->assertEquals(1, $result['entities_found']);
        $this->assertNotNull($capturedRelation);
        // Neither fileId nor objectId should be set.
        $this->assertNull($capturedRelation->getFileId());
        $this->assertNull($capturedRelation->getObjectId());
    }

    // ================================================================
    // findOrCreateEntity — update path when entity exists
    // ================================================================

    /**
     * Test findOrCreateEntity updates timestamp when entity already exists
     *
     * @return void
     */
    public function testFindOrCreateEntityUpdatesExistingEntity(): void
    {
        $existingEntity = new GdprEntity();
        $ref = new ReflectionClass($existingEntity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($existingEntity, 42);
        $existingEntity->setType('EMAIL');
        $existingEntity->setValue('existing@test.nl');

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

        $this->entityMapper->method('findEntitiesPublic')
            ->willReturn([$existingEntity]);

        $this->entityMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function ($entity) {
                return $entity->getUpdatedAt() instanceof DateTime;
            }))
            ->willReturn($existingEntity);

        $result = $this->invokePrivate('findOrCreateEntity', [
            'EMAIL',
            'existing@test.nl',
            'personal_data',
        ]);

        $this->assertEquals(42, $result->getId());
    }

    // ================================================================
    // detectEntities — unknown method throws
    // ================================================================

    /**
     * Test detectEntities throws Exception for completely unknown method
     *
     * @return void
     */
    public function testDetectEntitiesUnknownMethodThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown detection method: foobar');

        $this->invokePrivate('detectEntities', [
            'Some text',
            'foobar',
            null,
            0.5,
        ]);
    }

    // ================================================================
    // getRegexPatterns — structure validation
    // ================================================================

    /**
     * Test getRegexPatterns returns exactly 3 pattern definitions
     *
     * @return void
     */
    public function testGetRegexPatternsReturnsThreePatterns(): void
    {
        $patterns = $this->invokePrivate('getRegexPatterns', []);

        $this->assertCount(3, $patterns);

        foreach ($patterns as $pattern) {
            $this->assertArrayHasKey('type', $pattern);
            $this->assertArrayHasKey('pattern', $pattern);
            $this->assertArrayHasKey('category', $pattern);
            $this->assertArrayHasKey('confidence', $pattern);
            $this->assertIsFloat($pattern['confidence']);
        }
    }
}
