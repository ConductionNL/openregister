<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileSearchController;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FileSearchController.
 *
 * Covers the hybrid-document-search contract (or#277 fixes): file-scoped
 * semantic search via the `entity_type` filter key, the real keyword arm
 * feeding hybridSearch's RRF fusion, and the flat `{results, total, ...}`
 * hybrid response shape with a correct total.
 *
 * @spec openspec/changes/hybrid-document-search/tasks.md#7.3
 */
class FileSearchControllerTest extends TestCase {
	private FileSearchController $controller;
	private IRequest&MockObject $request;
	private VectorizationService&MockObject $vectorService;
	private ChunkMapper&MockObject $chunkMapper;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->vectorService = $this->createMock(VectorizationService::class);
		$this->chunkMapper = $this->createMock(ChunkMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new FileSearchController(
			'openregister',
			$this->request,
			$this->vectorService,
			$this->chunkMapper,
			$this->logger
		);
	}

	/**
	 * Build a hybrid service response with N fused results.
	 */
	private function makeHybridServiceResponse(int $count): array {
		$results = [];
		for ($i = 0; $i < $count; $i++) {
			$results[] = [
				'entity_type' => 'file',
				'entity_id' => (string)($i + 1),
				'combined_score' => (1 / ($i + 1)),
				'in_vector' => true,
				'in_keyword' => ($i % 2 === 0),
			];
		}

		return [
			'results' => $results,
			'total' => $count,
			'search_time_ms' => 12.5,
			'source_breakdown' => [
				'vector_only' => (int)floor($count / 2),
				'keyword_only' => 0,
				'both' => (int)ceil($count / 2),
			],
			'weights' => ['keyword' => 0.5, 'vector' => 0.5],
		];
	}

	public function testSemanticSearchEmptyQuery(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', ''],
				['limit', 10, 10],
			]);

		$result = $this->controller->semanticSearch();

		$this->assertEquals(400, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Query parameter is required', $data['message']);
	}

	public function testSemanticSearchSuccess(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test query'],
				['limit', 10, 10],
			]);

		$this->vectorService->method('semanticSearch')->willReturn([
			['id' => 1, 'score' => 0.9],
		]);

		$result = $this->controller->semanticSearch();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('semantic', $data['search_type']);
		$this->assertEquals(1, $data['total']);
	}

	/**
	 * Regression test for the or#277 entityType → entity_type filter-key bug:
	 * the controller MUST pass the snake_case `entity_type` key that
	 * VectorSearchHandler::fetchVectors() actually reads, so file-scoped
	 * search really scopes to files.
	 */
	public function testSemanticSearchPassesEntityTypeFilterKey(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'scoped query'],
				['limit', 10, 10],
			]);

		$this->vectorService->expects($this->once())
			->method('semanticSearch')
			->with(
				'scoped query',
				10,
				$this->callback(
					function (array $filters): bool {
						return ($filters['entity_type'] ?? null) === 'file'
							&& array_key_exists('entityType', $filters) === false;
					}
				)
			)
			->willReturn([]);

		$result = $this->controller->semanticSearch();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testSemanticSearchException(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['limit', 10, 10],
			]);

		$this->vectorService->method('semanticSearch')
			->willThrowException(new \Exception('Vector error'));

		$result = $this->controller->semanticSearch();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testHybridSearchEmptyQuery(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', ''],
				['limit', 10, 10],
				['keyword_weight', 0.5, 0.5],
				['semantic_weight', 0.5, 0.5],
			]);

		$result = $this->controller->hybridSearch();

		$this->assertEquals(400, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Query parameter is required', $data['message']);
	}

	/**
	 * Regression test for the or#277 total/shape bug: `total` MUST equal the
	 * fused-result count (not the count of the service response's outer keys)
	 * and `results` MUST be the flat fused list (not the nested service
	 * response object).
	 */
	public function testHybridSearchTotalMatchesFusedResultCountAndResultsAreFlat(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'seven results'],
				['limit', 10, 10],
				['keyword_weight', 0.5, 0.5],
				['semantic_weight', 0.5, 0.5],
			]);

		$this->chunkMapper->method('searchByKeyword')->willReturn([]);
		$this->vectorService->method('hybridSearch')
			->willReturn($this->makeHybridServiceResponse(7));

		$result = $this->controller->hybridSearch();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();

		$this->assertEquals(7, $data['total']);
		$this->assertCount(7, $data['results']);
		// Flat list of result entries — not a nested service response.
		$this->assertArrayNotHasKey('results', $data['results']);
		$this->assertArrayNotHasKey('total', $data['results']);
		$this->assertArrayHasKey('entity_id', $data['results'][0]);
		// Flat top-level shape for a search-page UI.
		$this->assertArrayHasKey('search_time_ms', $data);
		$this->assertArrayHasKey('source_breakdown', $data);
		$this->assertArrayHasKey('weights', $data);
		$this->assertEquals('hybrid', $data['search_type']);
	}

	/**
	 * The keyword arm MUST be populated from ChunkMapper::searchByKeyword()
	 * (scoped to file chunks) and passed into the service's RRF fusion —
	 * not the former hard-coded empty array (or#277).
	 */
	public function testHybridSearchPassesRealKeywordResultsIntoFusion(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'quarterly report'],
				['limit', 10, 10],
				['keyword_weight', 0.5, 0.5],
				['semantic_weight', 0.5, 0.5],
			]);

		$keywordResults = [
			[
				'entity_type' => 'file',
				'entity_id' => '42',
				'score' => 0.8,
				'chunk_text' => 'the quarterly report shows',
				'chunk_index' => 0,
				'metadata' => [],
			],
		];

		$this->chunkMapper->expects($this->once())
			->method('searchByKeyword')
			->with(
				'quarterly report',
				20,
				$this->callback(
					fn (array $filters): bool => ($filters['source_type'] ?? null) === 'file'
				)
			)
			->willReturn($keywordResults);

		$this->vectorService->expects($this->once())
			->method('hybridSearch')
			->with(
				'quarterly report',
				$keywordResults,
				10,
				['keyword' => 0.5, 'vector' => 0.5]
			)
			->willReturn($this->makeHybridServiceResponse(1));

		$result = $this->controller->hybridSearch();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testHybridSearchException(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['limit', 10, 10],
				['keyword_weight', 0.5, 0.5],
				['semantic_weight', 0.5, 0.5],
			]);

		$this->chunkMapper->method('searchByKeyword')->willReturn([]);
		$this->vectorService->method('hybridSearch')
			->willThrowException(new \Exception('Hybrid error'));

		$result = $this->controller->hybridSearch();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testSemanticSearchReturnsEmptyResults(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'no match'],
				['limit', 10, 5],
			]);

		$this->vectorService->method('semanticSearch')->willReturn([]);

		$result = $this->controller->semanticSearch();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(0, $data['total']);
		$this->assertEquals([], $data['results']);
	}

	public function testHybridSearchReturnsNormalisedWeightsInResponse(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'hybrid test'],
				['limit', 10, 10],
				['keyword_weight', 0.5, 0.7],
				['semantic_weight', 0.5, 0.3],
			]);

		$serviceResponse = $this->makeHybridServiceResponse(1);
		$serviceResponse['weights'] = ['keyword' => 0.7, 'vector' => 0.3];

		$this->chunkMapper->method('searchByKeyword')->willReturn([]);
		$this->vectorService->method('hybridSearch')->willReturn($serviceResponse);

		$result = $this->controller->hybridSearch();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('weights', $data);
		$this->assertEquals(0.7, $data['weights']['keyword']);
		$this->assertEquals(0.3, $data['weights']['vector']);
		$this->assertEquals(1, $data['total']);
	}
}
