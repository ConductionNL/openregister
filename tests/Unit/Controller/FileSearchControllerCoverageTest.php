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
 * Coverage tests for FileSearchController — targets remaining uncovered branches.
 */
class FileSearchControllerCoverageTest extends TestCase {
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

	// =========================================================================
	// semanticSearch — with custom limit
	// =========================================================================

	public function testSemanticSearchWithCustomLimit(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'financial report'],
				['limit', 10, 25],
			]);

		$this->vectorService->method('semanticSearch')
			->with('financial report', 25, ['entity_type' => 'file'])
			->willReturn([['id' => 1], ['id' => 2]]);

		$result = $this->controller->semanticSearch();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(2, $data['total']);
		$this->assertEquals('financial report', $data['query']);
	}

	// =========================================================================
	// hybridSearch — with custom weights
	// =========================================================================

	public function testHybridSearchCustomWeights(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'annual report'],
				['limit', 10, 20],
				['keyword_weight', 0.5, 0.8],
				['semantic_weight', 0.5, 0.2],
			]);

		$this->chunkMapper->method('searchByKeyword')->willReturn([]);

		$this->vectorService->method('hybridSearch')
			->willReturn(
				[
					'results' => [['entity_id' => '1', 'combined_score' => 0.95]],
					'total' => 1,
					'search_time_ms' => 3.2,
					'source_breakdown' => ['vector_only' => 1, 'keyword_only' => 0, 'both' => 0],
					'weights' => ['keyword' => 0.8, 'vector' => 0.2],
				]
			);

		$result = $this->controller->hybridSearch();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals('hybrid', $data['search_type']);
		$this->assertEquals(0.8, $data['weights']['keyword']);
		$this->assertEquals(0.2, $data['weights']['vector']);
	}

	// =========================================================================
	// semanticSearch — exception path
	// =========================================================================

	public function testSemanticSearchException(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['limit', 10, 10],
			]);

		$this->vectorService->method('semanticSearch')
			->willThrowException(new \Exception('Vector service error'));

		$result = $this->controller->semanticSearch();

		$this->assertEquals(500, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Vector service error', $data['message']);
	}

	// =========================================================================
	// hybridSearch — exception path
	// =========================================================================

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
			->willThrowException(new \Exception('No endpoint'));

		$result = $this->controller->hybridSearch();

		$this->assertEquals(500, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('No endpoint', $data['message']);
	}
}
