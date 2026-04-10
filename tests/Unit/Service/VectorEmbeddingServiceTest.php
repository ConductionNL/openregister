<?php

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\Vectorization\Handlers\VectorSearchHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for VectorSearchHandler (cosine similarity, RRF)
 */
class VectorEmbeddingServiceTest extends TestCase
{
	/** @var VectorSearchHandler */
	private $handler;

	protected function setUp(): void
	{
		parent::setUp();

		$db = $this->createMock(\OCP\IDBConnection::class);
		$settings = $this->createMock(\OCA\OpenRegister\Service\SettingsService::class);
		$indexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);

		$this->handler = new VectorSearchHandler($db, $settings, $indexService, $logger);
	}

	/**
	 * Invoke a private/protected method on the handler.
	 */
	private function invokeMethod(string $methodName, array $args = []): mixed
	{
		$method = new ReflectionMethod($this->handler, $methodName);
		$method->setAccessible(true);
		return $method->invokeArgs($this->handler, $args);
	}

	/**
	 * Helper to build a vector result entry matching the expected format.
	 */
	private function makeVectorResult(string $id, float $similarity, string $entityType = 'object'): array
	{
		return [
			'entity_type' => $entityType,
			'entity_id' => $id,
			'chunk_index' => 0,
			'chunk_text' => 'text for ' . $id,
			'metadata' => [],
			'similarity' => $similarity,
		];
	}

	/**
	 * Helper to build a SOLR result entry matching the expected format.
	 */
	private function makeSolrResult(string $id, float $score, string $entityType = 'object'): array
	{
		return [
			'entity_type' => $entityType,
			'entity_id' => $id,
			'chunk_index' => 0,
			'chunk_text' => 'text for ' . $id,
			'metadata' => [],
			'score' => $score,
		];
	}

	public function testCosineSimilarityIdentical()
	{
		$similarity = $this->invokeMethod('cosineSimilarity', [[1.0, 0.0, 0.0], [1.0, 0.0, 0.0]]);
		$this->assertEqualsWithDelta(1.0, $similarity, 0.001);
	}

	public function testCosineSimilarityOrthogonal()
	{
		$similarity = $this->invokeMethod('cosineSimilarity', [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0]]);
		$this->assertEqualsWithDelta(0.0, $similarity, 0.001);
	}

	public function testCosineSimilarityOpposite()
	{
		$similarity = $this->invokeMethod('cosineSimilarity', [[1.0, 0.0], [-1.0, 0.0]]);
		$this->assertEqualsWithDelta(-1.0, $similarity, 0.001);
	}

	public function testCosineSimilarityPartial()
	{
		$similarity = $this->invokeMethod('cosineSimilarity', [[1.0, 1.0, 0.0], [1.0, 0.0, 0.0]]);
		// cos(45) = 0.707.
		$this->assertGreaterThan(0.7, $similarity);
		$this->assertLessThan(0.8, $similarity);
	}

	public function testCosineSimilarityHighDimensional()
	{
		$v = array_fill(0, 1536, 1.0);
		$similarity = $this->invokeMethod('cosineSimilarity', [$v, $v]);
		$this->assertEqualsWithDelta(1.0, $similarity, 0.001);
	}

	public function testCosineSimilarityNormalization()
	{
		$similarity = $this->invokeMethod('cosineSimilarity', [[2.0, 2.0], [1.0, 1.0]]);
		$this->assertEqualsWithDelta(1.0, $similarity, 0.001);
	}

	public function testReciprocalRankFusionBasic()
	{
		$vector = [
			$this->makeVectorResult('A', 0.9),
			$this->makeVectorResult('B', 0.8),
		];
		$solr = [
			$this->makeSolrResult('B', 10.0),
			$this->makeSolrResult('C', 8.0),
		];

		$merged = $this->invokeMethod('reciprocalRankFusion', [$vector, $solr]);

		$this->assertCount(3, $merged);
		// B is in both lists, should be ranked first.
		$this->assertEquals('B', $merged[0]['entity_id']);
		$this->assertArrayHasKey('combined_score', $merged[0]);
		$this->assertGreaterThan(0, $merged[0]['combined_score']);
	}

	public function testReciprocalRankFusionOnlyVector()
	{
		$vector = [
			$this->makeVectorResult('A', 0.9),
			$this->makeVectorResult('B', 0.8),
		];
		$merged = $this->invokeMethod('reciprocalRankFusion', [$vector, []]);

		$this->assertCount(2, $merged);
		$this->assertEquals('A', $merged[0]['entity_id']);
		$this->assertEquals('B', $merged[1]['entity_id']);
	}

	public function testReciprocalRankFusionOnlySolr()
	{
		$solr = [
			$this->makeSolrResult('X', 10.0),
			$this->makeSolrResult('Y', 8.0),
		];
		$merged = $this->invokeMethod('reciprocalRankFusion', [[], $solr]);

		$this->assertCount(2, $merged);
		$this->assertEquals('X', $merged[0]['entity_id']);
		$this->assertEquals('Y', $merged[1]['entity_id']);
	}

	public function testReciprocalRankFusionWeights()
	{
		// A appears at rank 0 in vector, B at rank 0 in solr.
		// With vector-heavy weights, A should rank higher; with solr-heavy, B should rank higher.
		$vector = [$this->makeVectorResult('A', 0.9)];
		$solr = [$this->makeSolrResult('B', 10.0)];

		$merged1 = $this->invokeMethod('reciprocalRankFusion', [$vector, $solr, 0.9, 0.1]);
		$merged2 = $this->invokeMethod('reciprocalRankFusion', [$vector, $solr, 0.1, 0.9]);

		// With high vector weight, A should be first.
		$this->assertEquals('A', $merged1[0]['entity_id']);
		// With high solr weight, B should be first.
		$this->assertEquals('B', $merged2[0]['entity_id']);
	}

	public function testReciprocalRankFusionPreservesMetadata()
	{
		$vector = [[
			'entity_type' => 'object',
			'entity_id' => 'A',
			'chunk_index' => 2,
			'chunk_text' => 'Some text',
			'metadata' => ['title' => 'Title A'],
			'similarity' => 0.9,
		]];
		$solr = [];

		$merged = $this->invokeMethod('reciprocalRankFusion', [$vector, $solr]);

		$this->assertEquals('A', $merged[0]['entity_id']);
		$this->assertEquals('Some text', $merged[0]['chunk_text']);
		$this->assertEquals(2, $merged[0]['chunk_index']);
		$this->assertEquals(['title' => 'Title A'], $merged[0]['metadata']);
	}

	public function testReciprocalRankFusionLargeDataset()
	{
		$vector = [];
		for ($i = 1; $i <= 100; $i++) {
			$vector[] = $this->makeVectorResult('V' . $i, 1.0 - ($i * 0.005));
		}

		$solr = [];
		for ($i = 1; $i <= 100; $i++) {
			$solr[] = $this->makeSolrResult('S' . $i, 100 - $i);
		}

		// Overlap: first 3 solr items share IDs with first 3 vector items.
		$solr[0]['entity_id'] = 'V1';
		$solr[1]['entity_id'] = 'V2';
		$solr[2]['entity_id'] = 'V3';

		$merged = $this->invokeMethod('reciprocalRankFusion', [$vector, $solr]);

		$topIds = array_slice(array_column($merged, 'entity_id'), 0, 5);
		$this->assertContains('V1', $topIds);
		$this->assertContains('V2', $topIds);
		$this->assertContains('V3', $topIds);
	}
}
