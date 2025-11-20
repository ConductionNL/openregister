<?php

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\VectorEmbeddingService;
use Test\TestCase;
use ReflectionClass;

/**
 * Unit tests for VectorEmbeddingService
 *
 * @group DB
 */
class VectorEmbeddingServiceTest extends TestCase
{
	/** @var VectorEmbeddingService */
	private $service;
	
	protected function setUp(): void
	{
		parent::setUp();
		
		$db = $this->createMock(\OCP\IDBConnection::class);
		$settings = $this->createMock(\OCA\OpenRegister\Service\SettingsService::class);
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		
		$this->service = new VectorEmbeddingService($db, $settings, $logger);
	}
	
	public function testCosineSimilarityIdentical()
	{
		$vector1 = [1.0, 0.0, 0.0];
		$vector2 = [1.0, 0.0, 0.0];
		
		$similarity = $this->invokePrivate('cosineSimilarity', [$vector1, $vector2]);
		
		$this->assertEquals(1.0, $similarity, '', 0.001);
	}
	
	public function testCosineSimilarityOrthogonal()
	{
		$vector1 = [1.0, 0.0, 0.0];
		$vector2 = [0.0, 1.0, 0.0];
		
		$similarity = $this->invokePrivate('cosineSimilarity', [$vector1, $vector2]);
		
		$this->assertEquals(0.0, $similarity, '', 0.001);
	}
	
	public function testCosineSimilarityOpposite()
	{
		$vector1 = [1.0, 0.0];
		$vector2 = [-1.0, 0.0];
		
		$similarity = $this->invokePrivate('cosineSimilarity', [$vector1, $vector2]);
		
		$this->assertEquals(-1.0, $similarity, '', 0.001);
	}
	
	public function testCosineSimilarityPartial()
	{
		$vector1 = [1.0, 1.0, 0.0];
		$vector2 = [1.0, 0.0, 0.0];
		
		$similarity = $this->invokePrivate('cosineSimilarity', [$vector1, $vector2]);
		
		// cos(45°) ≈ 0.707.
		$this->assertGreaterThan(0.7, $similarity);
		$this->assertLessThan(0.8, $similarity);
	}
	
	public function testCosineSimilarityHighDimensional()
	{
		// Test with realistic embedding dimensions (1536).
		$vector1 = array_fill(0, 1536, 1.0);
		$vector2 = array_fill(0, 1536, 1.0);
		
		$similarity = $this->invokePrivate('cosineSimilarity', [$vector1, $vector2]);
		
		$this->assertEquals(1.0, $similarity, '', 0.001);
	}
	
	public function testCosineSimilarityNormalization()
	{
		// Vectors with different magnitudes but same direction.
		$vector1 = [2.0, 2.0];
		$vector2 = [1.0, 1.0];
		
		$similarity = $this->invokePrivate('cosineSimilarity', [$vector1, $vector2]);
		
		// Should be 1.0 (same direction, different magnitude).
		$this->assertEquals(1.0, $similarity, '', 0.001);
	}
	
	public function testReciprocalRankFusionBasic()
	{
		$keywordResults = [
			['id' => 'A', 'score' => 10],
			['id' => 'B', 'score' => 8],
			['id' => 'C', 'score' => 6],
		];
		
		$semanticResults = [
			['id' => 'B', 'similarity' => 0.9],
			['id' => 'A', 'similarity' => 0.8],
			['id' => 'D', 'similarity' => 0.7],
		];
		
		$merged = $this->invokePrivate('reciprocalRankFusion', [$keywordResults, $semanticResults, 60]);
		
		$this->assertCount(4, $merged); // A, B, C, D
		
		// B should be first (rank 2 in keyword, rank 1 in semantic).
		$this->assertEquals('B', $merged[0]['id']);
		
		// Check that RRF scores are calculated.
		$this->assertArrayHasKey('rrf_score', $merged[0]);
		$this->assertGreaterThan(0, $merged[0]['rrf_score']);
	}
	
	public function testReciprocalRankFusionOnlyKeyword()
	{
		$keywordResults = [
			['id' => 'A', 'score' => 10],
			['id' => 'B', 'score' => 8],
		];
		
		$semanticResults = [];
		
		$merged = $this->invokePrivate('reciprocalRankFusion', [$keywordResults, $semanticResults, 60]);
		
		$this->assertCount(2, $merged);
		$this->assertEquals('A', $merged[0]['id']);
		$this->assertEquals('B', $merged[1]['id']);
	}
	
	public function testReciprocalRankFusionOnlySemantic()
	{
		$keywordResults = [];
		
		$semanticResults = [
			['id' => 'X', 'similarity' => 0.9],
			['id' => 'Y', 'similarity' => 0.8],
		];
		
		$merged = $this->invokePrivate('reciprocalRankFusion', [$keywordResults, $semanticResults, 60]);
		
		$this->assertCount(2, $merged);
		$this->assertEquals('X', $merged[0]['id']);
		$this->assertEquals('Y', $merged[1]['id']);
	}
	
	public function testReciprocalRankFusionKParameter()
	{
		$keywordResults = [['id' => 'A', 'score' => 10]];
		$semanticResults = [['id' => 'A', 'similarity' => 0.9]];
		
		// Test with different k values.
		$merged1 = $this->invokePrivate('reciprocalRankFusion', [$keywordResults, $semanticResults, 10]);
		$merged2 = $this->invokePrivate('reciprocalRankFusion', [$keywordResults, $semanticResults, 100]);
		
		// Different k values should produce different scores.
		$this->assertNotEquals($merged1[0]['rrf_score'], $merged2[0]['rrf_score']);
	}
	
	public function testReciprocalRankFusionPreservesMetadata()
	{
		$keywordResults = [
			['id' => 'A', 'score' => 10, 'title' => 'Title A', 'custom' => 'data']
		];
		
		$semanticResults = [
			['id' => 'A', 'similarity' => 0.9, 'text' => 'Some text']
		];
		
		$merged = $this->invokePrivate('reciprocalRankFusion', [$keywordResults, $semanticResults, 60]);
		
		// Should preserve metadata from keyword results.
		$this->assertArrayHasKey('title', $merged[0]);
		$this->assertEquals('Title A', $merged[0]['title']);
		$this->assertArrayHasKey('custom', $merged[0]);
		$this->assertEquals('data', $merged[0]['custom']);
		
		// Should also have semantic data.
		$this->assertArrayHasKey('text', $merged[0]);
		$this->assertEquals('Some text', $merged[0]['text']);
	}
	
	public function testReciprocalRankFusionLargeDataset()
	{
		// Generate 100 keyword results.
		$keywordResults = [];
		for ($i = 1; $i <= 100; $i++) {
			$keywordResults[] = ['id' => 'K' . $i, 'score' => 100 - $i];
		}
		
		// Generate 100 semantic results (with some overlap).
		$semanticResults = [];
		for ($i = 1; $i <= 100; $i++) {
			$semanticResults[] = ['id' => 'S' . $i, 'similarity' => 1.0 - ($i * 0.01)];
		}
		
		// Add some overlapping IDs.
		$semanticResults[0]['id'] = 'K1';
		$semanticResults[1]['id'] = 'K2';
		$semanticResults[2]['id'] = 'K3';
		
		$merged = $this->invokePrivate('reciprocalRankFusion', [$keywordResults, $semanticResults, 60]);
		
		// K1, K2, K3 should rank highly due to appearing in both.
		$topIds = array_slice(array_column($merged, 'id'), 0, 5);
		$this->assertContains('K1', $topIds);
		$this->assertContains('K2', $topIds);
		$this->assertContains('K3', $topIds);
	}
	
	/**
	 * Helper method to invoke private methods
	 *
	 * @param string $methodName
	 * @param array $parameters
	 * @return mixed
	 */
	private function invokePrivate(string $methodName, array $parameters = [])
	{
		$reflection = new ReflectionClass($this->service);
		$method = $reflection->getMethod($methodName);
		$method->setAccessible(true);
		return $method->invokeArgs($this->service, $parameters);
	}
}

