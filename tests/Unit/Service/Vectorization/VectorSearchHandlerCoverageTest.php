<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vectorization;

use OCA\OpenRegister\Service\Vectorization\Handlers\VectorSearchHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage tests for VectorSearchHandler — targets uncovered branches in
 * cosineSimilarity, extractEntityId, getCollectionsToSearch,
 * getSolrCollectionForEntityType, semanticSearch (php backend paths),
 * hybridSearch, and reciprocalRankFusion.
 */
class VectorSearchHandlerCoverageTest extends TestCase
{
    private VectorSearchHandler $handler;
    private IDBConnection&MockObject $db;
    private SettingsService&MockObject $settingsService;
    private IndexService&MockObject $indexService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new VectorSearchHandler(
            $this->db,
            $this->settingsService,
            $this->indexService,
            $this->logger
        );
    }

    // =========================================================================
    // cosineSimilarity via semanticSearch with php backend
    // =========================================================================

    public function testSemanticSearchPhpBackendNoVectors(): void
    {
        // Mock fetchVectors returning empty array
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetchAll')->willReturn([]);

        $results = $this->handler->semanticSearch(
            queryEmbedding: [0.1, 0.2, 0.3],
            limit: 5,
            filters: [],
            backend: 'php'
        );

        $this->assertSame([], $results);
    }

    public function testSemanticSearchPhpBackendWithVectors(): void
    {
        $embedding = serialize([0.1, 0.2, 0.3]);
        $vectors = [
            [
                'id' => 1,
                'entity_type' => 'object',
                'entity_id' => '123',
                'chunk_index' => 0,
                'total_chunks' => 1,
                'chunk_text' => 'test text',
                'metadata' => json_encode(['key' => 'val']),
                'embedding_model' => 'test-model',
                'embedding_dimensions' => 3,
                'embedding' => $embedding,
            ],
        ];

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetchAll')->willReturn($vectors);

        $results = $this->handler->semanticSearch(
            queryEmbedding: [0.1, 0.2, 0.3],
            limit: 5,
            filters: [],
            backend: 'php'
        );

        $this->assertCount(1, $results);
        $this->assertSame('object', $results[0]['entity_type']);
        $this->assertSame('123', $results[0]['entity_id']);
        $this->assertGreaterThan(0, $results[0]['similarity']);
    }

    public function testSemanticSearchPhpBackendWithBadSerializedEmbedding(): void
    {
        $vectors = [
            [
                'id' => 1,
                'entity_type' => 'object',
                'entity_id' => '123',
                'chunk_index' => 0,
                'total_chunks' => 1,
                'chunk_text' => 'test text',
                'metadata' => '',
                'embedding_model' => 'test-model',
                'embedding_dimensions' => 3,
                'embedding' => serialize('not-an-array'),
            ],
        ];

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetchAll')->willReturn($vectors);

        $results = $this->handler->semanticSearch(
            queryEmbedding: [0.1, 0.2, 0.3],
            limit: 5,
            filters: [],
            backend: 'php'
        );

        // Non-array embedding is skipped
        $this->assertSame([], $results);
    }

    public function testSemanticSearchWithEntityTypeFilter(): void
    {
        $embedding = serialize([1.0, 0.0]);
        $vectors = [
            [
                'id' => 1,
                'entity_type' => 'file',
                'entity_id' => '42',
                'chunk_index' => 0,
                'total_chunks' => 1,
                'chunk_text' => 'file text',
                'metadata' => '',
                'embedding_model' => 'model',
                'embedding_dimensions' => 2,
                'embedding' => $embedding,
            ],
        ];

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetchAll')->willReturn($vectors);

        $results = $this->handler->semanticSearch(
            queryEmbedding: [1.0, 0.0],
            limit: 5,
            filters: ['entity_type' => 'file'],
            backend: 'database'
        );

        $this->assertCount(1, $results);
        $this->assertSame('file', $results[0]['entity_type']);
    }

    public function testSemanticSearchWithEntityTypeArrayFilter(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetchAll')->willReturn([]);

        $results = $this->handler->semanticSearch(
            queryEmbedding: [1.0],
            limit: 5,
            filters: [
                'entity_type' => ['file', 'object'],
                'entity_id' => ['1', '2'],
            ],
            backend: 'php'
        );

        $this->assertSame([], $results);
    }

    public function testSemanticSearchWithEntityIdStringFilter(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetchAll')->willReturn([]);

        $results = $this->handler->semanticSearch(
            queryEmbedding: [1.0],
            limit: 5,
            filters: ['entity_id' => '42'],
            backend: 'php'
        );

        $this->assertSame([], $results);
    }

    // =========================================================================
    // hybridSearch
    // =========================================================================

    public function testHybridSearchCombinesVectorAndSolrResults(): void
    {
        // Mock fetchVectors to return empty (so vector results are empty)
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetchAll')->willReturn([]);

        $solrResults = [
            [
                'entity_type' => 'object',
                'entity_id' => 'abc',
                'score' => 1.5,
                'chunk_index' => 0,
                'chunk_text' => 'solr text',
                'metadata' => [],
            ],
        ];

        $hybrid = $this->handler->hybridSearch(
            queryEmbedding: [0.1, 0.2],
            solrResults: $solrResults,
            limit: 10,
            weights: ['solr' => 0.7, 'vector' => 0.3],
            backend: 'php'
        );

        $this->assertArrayHasKey('results', $hybrid);
        $this->assertArrayHasKey('source_breakdown', $hybrid);
        $this->assertArrayHasKey('weights', $hybrid);
        $this->assertGreaterThan(0, $hybrid['total']);
        $this->assertTrue($hybrid['results'][0]['in_solr']);
    }

    public function testHybridSearchWithZeroVectorWeight(): void
    {
        $solrResults = [
            [
                'entity_type' => 'object',
                'entity_id' => 'xyz',
                'score' => 2.0,
            ],
        ];

        $hybrid = $this->handler->hybridSearch(
            queryEmbedding: [0.1],
            solrResults: $solrResults,
            limit: 5,
            weights: ['solr' => 1.0, 'vector' => 0.0],
            backend: 'php'
        );

        $this->assertSame(1, $hybrid['total']);
        $this->assertSame(1, $hybrid['source_breakdown']['solr_only']);
    }

    public function testHybridSearchWithBothVectorAndSolr(): void
    {
        // Vectors return empty (no db), but we test the RRF with overlapping results
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $dbResult = $this->createMock(\OCP\DB\IResult::class);

        $embedding = serialize([1.0, 0.0]);
        $vectors = [
            [
                'id' => 1,
                'entity_type' => 'object',
                'entity_id' => 'shared-id',
                'chunk_index' => 0,
                'total_chunks' => 1,
                'chunk_text' => 'shared text',
                'metadata' => '',
                'embedding_model' => 'model',
                'embedding_dimensions' => 2,
                'embedding' => $embedding,
            ],
        ];

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($dbResult);
        $dbResult->method('fetchAll')->willReturn($vectors);

        $solrResults = [
            [
                'entity_type' => 'object',
                'entity_id' => 'shared-id',
                'score' => 2.0,
            ],
            [
                'entity_type' => 'object',
                'entity_id' => 'solr-only',
                'score' => 1.0,
            ],
        ];

        $hybrid = $this->handler->hybridSearch(
            queryEmbedding: [1.0, 0.0],
            solrResults: $solrResults,
            limit: 10,
            weights: ['solr' => 0.5, 'vector' => 0.5],
            backend: 'php'
        );

        $this->assertGreaterThanOrEqual(2, $hybrid['total']);
        $this->assertGreaterThanOrEqual(1, $hybrid['source_breakdown']['both']);
    }
}
