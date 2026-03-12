<?php

declare(strict_types=1);

/**
 * VectorSearchHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vectorization\Handlers
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Vectorization\Handlers;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorSearchHandler;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for VectorSearchHandler.
 *
 * Covers semantic search (PHP and Solr backends), hybrid search with RRF,
 * cosine similarity math, and collection resolution logic.
 */
class VectorSearchHandlerTest extends TestCase
{

    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var IndexService&MockObject */
    private IndexService $indexService;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private VectorSearchHandler $handler;

    protected function setUp(): void
    {
        $this->db              = $this->createMock(IDBConnection::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->indexService    = $this->createMock(IndexService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->handler = new VectorSearchHandler(
            $this->db,
            $this->settingsService,
            $this->indexService,
            $this->logger
        );
    }//end setUp()

    // ── Helper: build a fake DB result set ──────────────────────────────

    /**
     * Returns a mock IQueryBuilder wired to return the given rows on executeQuery/fetchAll.
     *
     * @param array $rows Rows to return from fetchAll.
     *
     * @return IQueryBuilder&MockObject
     */
    private function buildQueryBuilderMock(array $rows): IQueryBuilder&MockObject
    {
        $stmt = $this->createMock(\OCP\DB\IResult::class);
        $stmt->method('fetchAll')->willReturn($rows);

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($stmt);

        return $qb;
    }//end buildQueryBuilderMock()

    /**
     * Serialise a float array into the format stored in the DB.
     *
     * @param float[] $vec The vector to serialise.
     *
     * @return string Serialised string.
     */
    private function serialiseEmbedding(array $vec): string
    {
        return serialize($vec);
    }//end serialiseEmbedding()

    // ── semanticSearch – PHP backend ────────────────────────────────────

    public function testSemanticSearchPhpBackendReturnsEmptyWhenNoVectors(): void
    {
        $qb = $this->buildQueryBuilderMock([]);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->handler->semanticSearch([1.0, 0.0], 10, [], 'php');

        $this->assertSame([], $result);
    }//end testSemanticSearchPhpBackendReturnsEmptyWhenNoVectors()

    public function testSemanticSearchPhpBackendRanksHigherSimilarityFirst(): void
    {
        $low  = $this->serialiseEmbedding([0.0, 1.0]);  // orthogonal  → similarity 0.0
        $high = $this->serialiseEmbedding([1.0, 0.0]);  // identical   → similarity 1.0

        $rows = [
            [
                'id'                  => 1,
                'entity_type'         => 'object',
                'entity_id'           => 'uuid-1',
                'embedding'           => $low,
                'metadata'            => null,
                'chunk_index'         => 0,
                'total_chunks'        => 1,
                'chunk_text'          => 'low',
                'embedding_model'     => 'test',
                'embedding_dimensions'=> 2,
            ],
            [
                'id'                  => 2,
                'entity_type'         => 'object',
                'entity_id'           => 'uuid-2',
                'embedding'           => $high,
                'metadata'            => null,
                'chunk_index'         => 0,
                'total_chunks'        => 1,
                'chunk_text'          => 'high',
                'embedding_model'     => 'test',
                'embedding_dimensions'=> 2,
            ],
        ];

        $qb = $this->buildQueryBuilderMock($rows);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $results = $this->handler->semanticSearch([1.0, 0.0], 10, [], 'php');

        $this->assertCount(2, $results);
        $this->assertSame('uuid-2', $results[0]['entity_id']);
        $this->assertSame('uuid-1', $results[1]['entity_id']);
        $this->assertGreaterThan($results[1]['similarity'], $results[0]['similarity']);
    }//end testSemanticSearchPhpBackendRanksHigherSimilarityFirst()

    public function testSemanticSearchPhpBackendRespectsLimit(): void
    {
        $embedding = $this->serialiseEmbedding([1.0, 0.0]);

        $rows = array_map(
            fn($i) => [
                'id'                  => $i,
                'entity_type'         => 'object',
                'entity_id'           => "uuid-{$i}",
                'embedding'           => $embedding,
                'metadata'            => null,
                'chunk_index'         => 0,
                'total_chunks'        => 1,
                'chunk_text'          => "text {$i}",
                'embedding_model'     => 'test',
                'embedding_dimensions'=> 2,
            ],
            range(1, 10)
        );

        $qb = $this->buildQueryBuilderMock($rows);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $results = $this->handler->semanticSearch([1.0, 0.0], 3, [], 'php');

        $this->assertCount(3, $results);
    }//end testSemanticSearchPhpBackendRespectsLimit()

    public function testSemanticSearchPhpBackendSkipsInvalidEmbeddings(): void
    {
        $rows = [
            [
                'id'                  => 1,
                'entity_type'         => 'object',
                'entity_id'           => 'bad',
                'embedding'           => serialize('not-an-array'),
                'metadata'            => null,
                'chunk_index'         => 0,
                'total_chunks'        => 1,
                'chunk_text'          => 'bad',
                'embedding_model'     => 'test',
                'embedding_dimensions'=> 2,
            ],
            [
                'id'                  => 2,
                'entity_type'         => 'object',
                'entity_id'           => 'good',
                'embedding'           => $this->serialiseEmbedding([1.0, 0.0]),
                'metadata'            => null,
                'chunk_index'         => 0,
                'total_chunks'        => 1,
                'chunk_text'          => 'good',
                'embedding_model'     => 'test',
                'embedding_dimensions'=> 2,
            ],
        ];

        $qb = $this->buildQueryBuilderMock($rows);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $results = $this->handler->semanticSearch([1.0, 0.0], 10, [], 'php');

        $this->assertCount(1, $results);
        $this->assertSame('good', $results[0]['entity_id']);
    }//end testSemanticSearchPhpBackendSkipsInvalidEmbeddings()

    public function testSemanticSearchPhpBackendDecodesJsonMetadata(): void
    {
        $meta = ['source' => 'test', 'page' => 1];

        $rows = [
            [
                'id'                  => 1,
                'entity_type'         => 'object',
                'entity_id'           => 'uuid-1',
                'embedding'           => $this->serialiseEmbedding([1.0, 0.0]),
                'metadata'            => json_encode($meta),
                'chunk_index'         => 0,
                'total_chunks'        => 1,
                'chunk_text'          => 'text',
                'embedding_model'     => 'model-x',
                'embedding_dimensions'=> 2,
            ],
        ];

        $qb = $this->buildQueryBuilderMock($rows);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $results = $this->handler->semanticSearch([1.0, 0.0], 10, [], 'php');

        $this->assertSame($meta, $results[0]['metadata']);
        $this->assertSame('model-x', $results[0]['model']);
        $this->assertSame(2, $results[0]['dimensions']);
    }//end testSemanticSearchPhpBackendDecodesJsonMetadata()

    public function testSemanticSearchPhpBackendPropagatesDbException(): void
    {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willThrowException(new Exception('DB error'));

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Semantic search failed');

        $this->handler->semanticSearch([1.0, 0.0], 10, [], 'php');
    }//end testSemanticSearchPhpBackendPropagatesDbException()

    public function testSemanticSearchPhpBackendWithEntityTypeStringFilter(): void
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');

        $stmt = $this->createMock(\OCP\DB\IResult::class);
        $stmt->method('fetchAll')->willReturn([]);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($stmt);

        // Verify eq() is called for string entity_type filter.
        $expr->expects($this->once())->method('eq')->with('entity_type', 'object');

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->semanticSearch([1.0, 0.0], 5, ['entity_type' => 'object'], 'php');
    }//end testSemanticSearchPhpBackendWithEntityTypeStringFilter()

    public function testSemanticSearchPhpBackendWithEntityTypeArrayFilter(): void
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('in')->willReturn('1=1');

        $stmt = $this->createMock(\OCP\DB\IResult::class);
        $stmt->method('fetchAll')->willReturn([]);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($stmt);

        // Verify in() is called for array entity_type filter.
        $expr->expects($this->once())->method('in')->with('entity_type', ['object', 'file']);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->semanticSearch([1.0, 0.0], 5, ['entity_type' => ['object', 'file']], 'php');
    }//end testSemanticSearchPhpBackendWithEntityTypeArrayFilter()

    // ── hybridSearch / Reciprocal Rank Fusion ───────────────────────────

    public function testHybridSearchCombinesBothSources(): void
    {
        // No vectors in DB so semanticSearch returns [].
        $qb = $this->buildQueryBuilderMock([]);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $solrResults = [
            [
                'entity_type' => 'object',
                'entity_id'   => 'solr-only',
                'score'       => 0.9,
                'chunk_index' => 0,
                'chunk_text'  => null,
                'metadata'    => [],
            ],
        ];

        $result = $this->handler->hybridSearch([1.0, 0.0], $solrResults, 10, ['solr' => 0.5, 'vector' => 0.5], 'php');

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('search_time_ms', $result);
        $this->assertArrayHasKey('source_breakdown', $result);
        $this->assertArrayHasKey('weights', $result);
        $this->assertSame(1, $result['total']);
        $this->assertTrue($result['results'][0]['in_solr']);
        $this->assertFalse($result['results'][0]['in_vector']);
    }//end testHybridSearchCombinesBothSources()

    public function testHybridSearchNormalisesWeights(): void
    {
        $qb = $this->buildQueryBuilderMock([]);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        // Weights sum to 2 → normalised to 0.5 each.
        $result = $this->handler->hybridSearch([1.0, 0.0], [], 10, ['solr' => 1.0, 'vector' => 1.0], 'php');

        $this->assertEqualsWithDelta(0.5, $result['weights']['solr'], 0.001);
        $this->assertEqualsWithDelta(0.5, $result['weights']['vector'], 0.001);
    }//end testHybridSearchNormalisesWeights()

    public function testHybridSearchSourceBreakdownCategorisesResults(): void
    {
        // One vector result, one solr-only result — same entity for "both".
        $embedding = $this->serialiseEmbedding([1.0, 0.0]);

        $rows = [
            [
                'id'                  => 1,
                'entity_type'         => 'object',
                'entity_id'           => 'shared-uuid',
                'embedding'           => $embedding,
                'metadata'            => null,
                'chunk_index'         => 0,
                'total_chunks'        => 1,
                'chunk_text'          => 'shared',
                'embedding_model'     => 'test',
                'embedding_dimensions'=> 2,
            ],
        ];

        $qb = $this->buildQueryBuilderMock($rows);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $solrResults = [
            [
                'entity_type' => 'object',
                'entity_id'   => 'shared-uuid',
                'score'       => 0.8,
                'chunk_index' => 0,
                'chunk_text'  => null,
                'metadata'    => [],
            ],
            [
                'entity_type' => 'object',
                'entity_id'   => 'solr-only-uuid',
                'score'       => 0.5,
                'chunk_index' => 0,
                'chunk_text'  => null,
                'metadata'    => [],
            ],
        ];

        $result = $this->handler->hybridSearch([1.0, 0.0], $solrResults, 20, ['solr' => 0.5, 'vector' => 0.5], 'php');

        $breakdown = $result['source_breakdown'];
        $this->assertSame(1, $breakdown['both']);
        $this->assertSame(1, $breakdown['solr_only']);
        $this->assertSame(0, $breakdown['vector_only']);
    }//end testHybridSearchSourceBreakdownCategorisesResults()

    public function testHybridSearchWithZeroVectorWeightSkipsSemanticSearch(): void
    {
        // DB should NOT be queried because vector weight is 0.
        $this->db->expects($this->never())->method('getQueryBuilder');

        $solrResults = [
            [
                'entity_type' => 'object',
                'entity_id'   => 'solr-id',
                'score'       => 0.9,
                'chunk_index' => 0,
                'chunk_text'  => null,
                'metadata'    => [],
            ],
        ];

        $result = $this->handler->hybridSearch([1.0, 0.0], $solrResults, 5, ['solr' => 1.0, 'vector' => 0.0], 'php');

        $this->assertSame(1, $result['total']);
    }//end testHybridSearchWithZeroVectorWeightSkipsSemanticSearch()

    public function testHybridSearchReturnsTopNResults(): void
    {
        $qb = $this->buildQueryBuilderMock([]);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $solrResults = array_map(
            fn($i) => [
                'entity_type' => 'object',
                'entity_id'   => "solr-{$i}",
                'score'       => 1.0 / ($i + 1),
                'chunk_index' => 0,
                'chunk_text'  => null,
                'metadata'    => [],
            ],
            range(0, 9)
        );

        $result = $this->handler->hybridSearch([1.0, 0.0], $solrResults, 3, ['solr' => 0.5, 'vector' => 0.5], 'php');

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['results']);
    }//end testHybridSearchReturnsTopNResults()

    public function testHybridSearchVectorSearchFailsGracefully(): void
    {
        // Make DB throw so the inner semanticSearch fails.
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willThrowException(new Exception('DB error'));
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->logger->expects($this->atLeastOnce())->method('warning');

        // Hybrid search should still return a result (solr only) despite vector failure.
        $solrResults = [
            [
                'entity_type' => 'object',
                'entity_id'   => 'solr-id',
                'score'       => 0.9,
                'chunk_index' => 0,
                'chunk_text'  => null,
                'metadata'    => [],
            ],
        ];

        $result = $this->handler->hybridSearch([1.0, 0.0], $solrResults, 5, ['solr' => 0.5, 'vector' => 0.5], 'php');

        $this->assertSame(1, $result['total']);
        $this->assertTrue($result['results'][0]['in_solr']);
    }//end testHybridSearchVectorSearchFailsGracefully()

    // ── RRF score ordering ───────────────────────────────────────────────

    public function testHybridSearchRrfOrdersByDescendingCombinedScore(): void
    {
        $qb = $this->buildQueryBuilderMock([]);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        // First-ranked SOLR item should receive higher RRF than second-ranked.
        $solrResults = [
            ['entity_type' => 'object', 'entity_id' => 'first',  'score' => 1.0, 'chunk_index' => 0, 'chunk_text' => null, 'metadata' => []],
            ['entity_type' => 'object', 'entity_id' => 'second', 'score' => 0.5, 'chunk_index' => 0, 'chunk_text' => null, 'metadata' => []],
        ];

        $result = $this->handler->hybridSearch([1.0, 0.0], $solrResults, 10, ['solr' => 1.0, 'vector' => 0.0], 'php');

        $this->assertSame('first', $result['results'][0]['entity_id']);
        $this->assertSame('second', $result['results'][1]['entity_id']);
        $this->assertGreaterThan(
            $result['results'][1]['combined_score'],
            $result['results'][0]['combined_score']
        );
    }//end testHybridSearchRrfOrdersByDescendingCombinedScore()

    // ── cosine similarity (via semanticSearch output) ────────────────────

    public function testCosineSimilarityIdenticalVectorsReturnsOne(): void
    {
        $vec = $this->serialiseEmbedding([0.6, 0.8]);

        $rows = [
            [
                'id'                  => 1,
                'entity_type'         => 'object',
                'entity_id'           => 'uuid-1',
                'embedding'           => $vec,
                'metadata'            => null,
                'chunk_index'         => 0,
                'total_chunks'        => 1,
                'chunk_text'          => 'text',
                'embedding_model'     => 'test',
                'embedding_dimensions'=> 2,
            ],
        ];

        $qb = $this->buildQueryBuilderMock($rows);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $results = $this->handler->semanticSearch([0.6, 0.8], 10, [], 'php');

        $this->assertEqualsWithDelta(1.0, $results[0]['similarity'], 0.0001);
    }//end testCosineSimilarityIdenticalVectorsReturnsOne()

    public function testCosineSimilarityOrthogonalVectorsReturnsZero(): void
    {
        $vec = $this->serialiseEmbedding([0.0, 1.0]);

        $rows = [
            [
                'id'                  => 1,
                'entity_type'         => 'object',
                'entity_id'           => 'uuid-1',
                'embedding'           => $vec,
                'metadata'            => null,
                'chunk_index'         => 0,
                'total_chunks'        => 1,
                'chunk_text'          => 'text',
                'embedding_model'     => 'test',
                'embedding_dimensions'=> 2,
            ],
        ];

        $qb = $this->buildQueryBuilderMock($rows);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $results = $this->handler->semanticSearch([1.0, 0.0], 10, [], 'php');

        $this->assertEqualsWithDelta(0.0, $results[0]['similarity'], 0.0001);
    }//end testCosineSimilarityOrthogonalVectorsReturnsZero()

    // ── Solr backend (searchVectorsInSolr is private — tested via public semanticSearch) ──

    public function testSemanticSearchSolrBackendThrowsWhenSolrUnavailable(): void
    {
        $backend = $this->getMockBuilder(\OCA\OpenRegister\Service\Index\Backends\SolrBackend::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isAvailable'])
            ->getMock();
        $backend->method('isAvailable')->willReturn(false);

        $this->indexService->method('getBackend')->willReturn($backend);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Semantic search failed');

        $this->handler->semanticSearch([1.0, 0.0], 10, [], 'solr');
    }//end testSemanticSearchSolrBackendThrowsWhenSolrUnavailable()

    // ── getCollectionsToSearch (private — covered via solr flow) ─────────

    public function testSemanticSearchSolrBackendThrowsWhenNoCollections(): void
    {
        $backend = $this->getMockBuilder(\OCA\OpenRegister\Service\Index\Backends\SolrBackend::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isAvailable'])
            ->getMock();
        $backend->method('isAvailable')->willReturn(true);

        $this->indexService->method('getBackend')->willReturn($backend);
        // Return settings with no solr.objectCollection or fileCollection.
        $this->settingsService->method('getSettings')->willReturn(['solr' => []]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Semantic search failed');

        $this->handler->semanticSearch([1.0, 0.0], 10, [], 'solr');
    }//end testSemanticSearchSolrBackendThrowsWhenNoCollections()

    // ── max_vectors filter ───────────────────────────────────────────────

    public function testSemanticSearchPhpBackendUsesDefaultMaxVectors(): void
    {
        $stmt = $this->createMock(\OCP\DB\IResult::class);
        $stmt->method('fetchAll')->willReturn([]);

        $expr = $this->createMock(IExpressionBuilder::class);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($stmt);

        // Default max_vectors should be 500.
        $qb->expects($this->once())->method('setMaxResults')->with(500)->willReturnSelf();

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->semanticSearch([1.0, 0.0], 10, [], 'php');
    }//end testSemanticSearchPhpBackendUsesDefaultMaxVectors()

    public function testSemanticSearchPhpBackendUsesCustomMaxVectors(): void
    {
        $stmt = $this->createMock(\OCP\DB\IResult::class);
        $stmt->method('fetchAll')->willReturn([]);

        $expr = $this->createMock(IExpressionBuilder::class);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($stmt);

        $qb->expects($this->once())->method('setMaxResults')->with(100)->willReturnSelf();

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->handler->semanticSearch([1.0, 0.0], 10, ['max_vectors' => 100], 'php');
    }//end testSemanticSearchPhpBackendUsesCustomMaxVectors()

    // ── result structure ─────────────────────────────────────────────────

    public function testSemanticSearchPhpBackendResultHasExpectedKeys(): void
    {
        $rows = [
            [
                'id'                  => 42,
                'entity_type'         => 'object',
                'entity_id'           => 'uuid-42',
                'embedding'           => $this->serialiseEmbedding([1.0, 0.0]),
                'metadata'            => json_encode(['key' => 'val']),
                'chunk_index'         => 2,
                'total_chunks'        => 5,
                'chunk_text'          => 'some text',
                'embedding_model'     => 'ada-002',
                'embedding_dimensions'=> 2,
            ],
        ];

        $qb = $this->buildQueryBuilderMock($rows);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $results = $this->handler->semanticSearch([1.0, 0.0], 10, [], 'php');

        $expected = ['vector_id', 'entity_type', 'entity_id', 'similarity', 'chunk_index', 'total_chunks', 'chunk_text', 'metadata', 'model', 'dimensions'];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $results[0], "Missing key: {$key}");
        }

        $this->assertSame(42, $results[0]['vector_id']);
        $this->assertSame(2, $results[0]['chunk_index']);
        $this->assertSame(5, $results[0]['total_chunks']);
        $this->assertSame('some text', $results[0]['chunk_text']);
        $this->assertSame('ada-002', $results[0]['model']);
        $this->assertSame(2, $results[0]['dimensions']);
    }//end testSemanticSearchPhpBackendResultHasExpectedKeys()

    public function testHybridSearchResultHasAllExpectedTopLevelKeys(): void
    {
        $qb = $this->buildQueryBuilderMock([]);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->handler->hybridSearch([], [], 5, ['solr' => 0.5, 'vector' => 0.5], 'php');

        foreach (['results', 'total', 'search_time_ms', 'source_breakdown', 'weights'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }

        foreach (['vector_only', 'solr_only', 'both'] as $key) {
            $this->assertArrayHasKey($key, $result['source_breakdown'], "Missing breakdown key: {$key}");
        }
    }//end testHybridSearchResultHasAllExpectedTopLevelKeys()

}//end class
