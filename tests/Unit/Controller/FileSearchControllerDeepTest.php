<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FileSearchController;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileSearchControllerDeepTest extends TestCase {
	private FileSearchController $controller;
	private IRequest|MockObject $request;
	private VectorizationService|MockObject $vectorService;
	private ChunkMapper|MockObject $chunkMapper;
	private LoggerInterface|MockObject $logger;

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

	public function testSemanticSearchEmptyQuery(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', ''],
				['limit', 10, 10],
			]);

		$response = $this->controller->semanticSearch();

		$this->assertEquals(400, $response->getStatus());
	}

	public function testSemanticSearchException(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['limit', 10, 10],
			]);
		$this->vectorService->method('semanticSearch')
			->willThrowException(new \Exception('semantic fail'));

		$response = $this->controller->semanticSearch();

		$this->assertEquals(500, $response->getStatus());
	}

	public function testHybridSearchEmptyQuery(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', ''],
				['limit', 10, 10],
				['keyword_weight', 0.5, 0.5],
				['semantic_weight', 0.5, 0.5],
			]);

		$response = $this->controller->hybridSearch();

		$this->assertEquals(400, $response->getStatus());
	}

	public function testHybridSearchException(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['limit', 10, 10],
				['keyword_weight', 0.5, 0.5],
				['semantic_weight', 0.5, 0.5],
			]);
		$this->vectorService->method('hybridSearch')
			->willThrowException(new \Exception('hybrid fail'));

		$response = $this->controller->hybridSearch();

		$this->assertEquals(500, $response->getStatus());
	}
}
