<?php

/**
 * Golden test pinning which handler each VectorEmbeddings call reaches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization;

use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSubject;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorPayload;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorChunkPosition;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\StoredVector;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandlerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorQuery;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\KeywordFusion;
use OCA\OpenRegister\Search\Service\Vectorization\HybridSearchRequest;

/**
 * Pins that storage, search and statistics are passed through unchanged.
 *
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings
 */
final class VectorEmbeddingsDelegationGoldenTest extends TestCase {

	/**
	 * Storage handler mock.
	 *
	 * @var VectorStorageHandlerInterface&MockObject
	 */
	private VectorStorageHandlerInterface&MockObject $storage;

	/**
	 * Search handler mock.
	 *
	 * @var VectorSearchHandler&MockObject
	 */
	private VectorSearchHandler&MockObject $search;

	/**
	 * Stats handler mock.
	 *
	 * @var VectorStatsHandler&MockObject
	 */
	private VectorStatsHandler&MockObject $stats;

	/**
	 * Generator handler mock.
	 *
	 * @var EmbeddingGeneratorHandler&MockObject
	 */
	private EmbeddingGeneratorHandler&MockObject $generator;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->storage   = $this->createMock(VectorStorageHandlerInterface::class);
		$this->search    = $this->createMock(VectorSearchHandler::class);
		$this->stats     = $this->createMock(VectorStatsHandler::class);
		$this->generator = $this->createMock(EmbeddingGeneratorHandler::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return VectorEmbeddings
	 */
	private function service(?SettingsServiceInterface $settings = null): VectorEmbeddings {
		return new VectorEmbeddings(
			$this->createMock(IDBConnection::class),
			$settings ?? $this->createMock(SettingsServiceInterface::class),
			new VectorEmbeddingHandlers($this->generator, $this->storage, $this->search, $this->stats),
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Every field of the stored vector reaches the storage handler and its id comes back.
	 *
	 * @return void
	 */
	public function testStoreVectorPassesEveryArgumentThrough(): void {
		$stored = null;
		$this->storage->expects($this->once())
			->method('storeVector')
			->willReturnCallback(
				static function (StoredVector $vector) use (&$stored): int {
					$stored = array_values(get_object_vars($vector));
					return 42;
				}
			);

		$id = $this->service()->storeVector(
			vector: $this->vector(
				subject: new VectorSubject(entityType: 'file', entityId: 'uuid-1'),
				payload: new VectorPayload(embedding: [0.1, 0.2], model: 'model-x', dimensions: 2),
				position: new VectorChunkPosition(chunkIndex: 3, totalChunks: 7, chunkText: 'text'),
				metadata: ['k' => 'v']
			)
		);

		$this->assertSame(42, $id);
		$this->assertSame(['file', 'uuid-1', [0.1, 0.2], 'model-x', 2, 3, 7, 'text', ['k' => 'v']], $stored);
	}//end testStoreVectorPassesEveryArgumentThrough()

	/**
	 * The stored vector reaches the storage handler as the same object.
	 *
	 * @return void
	 */
	public function testStoreVectorPassesTheSameVector(): void {
		$vector = $this->vector(
			subject: new VectorSubject(entityType: 'object', entityId: 'uuid-2'),
			payload: new VectorPayload(embedding: [1.0], model: 'm', dimensions: 1),
			position: new VectorChunkPosition(chunkIndex: 0, totalChunks: 1, chunkText: null),
			metadata: []
		);
		$this->storage->expects($this->once())
			->method('storeVector')
			->with($this->identicalTo($vector))
			->willReturn(5);

		$this->assertSame(5, $this->service()->storeVector(vector: $vector));
	}//end testStoreVectorPassesTheSameVector()

	/**
	 * Statistics come from the stats handler unchanged.
	 *
	 * @return void
	 */
	public function testStatsComeFromTheStatsHandler(): void {
		$stats = ['total_vectors' => 3, 'by_type' => [], 'by_model' => [], 'object_vectors' => 2, 'file_vectors' => 1];
		$this->stats->expects($this->once())->method('getStats')->willReturn($stats);

		$this->assertSame($stats, $this->service()->getVectorStats());
	}//end testStatsComeFromTheStatsHandler()

	/**
	 * The search handler is never reached by storage or statistics.
	 *
	 * @return void
	 */
	public function testSearchHandlerUntouchedByStorage(): void {
		$this->search->expects($this->never())->method($this->anything());
		$this->storage->method('storeVector')->willReturn(1);

		$this->service()->storeVector(
			vector: $this->vector(
				subject: new VectorSubject(entityType: 'object', entityId: 'u'),
				payload: new VectorPayload(embedding: [], model: 'm', dimensions: 0),
				position: new VectorChunkPosition(chunkIndex: 0, totalChunks: 1, chunkText: null),
				metadata: []
			)
		);
		$this->addToAssertionCount(1);
	}//end testSearchHandlerUntouchedByStorage()

	/**
	 * Hybrid search embeds the query with the configured provider and hands the
	 * embedding, the keyword results, the limit and the weights to the search handler.
	 *
	 * @return void
	 */
	public function testHybridSearchEmbedsWithTheConfiguredProviderAndFuses(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getLLMSettingsOnly')->willReturn(['embeddingProvider' => 'ollama', 'ollamaConfig' => ['model' => 'm1', 'url' => 'http://o']]);
		$embedder = $this->createMock(EmbeddingGeneratorInterface::class);
		$embedder->expects($this->once())->method('embedText')->with('needle')->willReturn([0.5, 0.25]);
		$this->generator->method('getDefaultDimensions')->willReturn(2);
		$this->generator->expects($this->once())
			->method('getGenerator')
			->with(['provider' => 'ollama', 'model' => 'm1', 'dimensions' => 2, 'api_key' => null, 'base_url' => 'http://o'])
			->willReturn($embedder);
		$keyword = [['id' => 'k1']];
		$weights = ['keyword' => 0.25, 'vector' => 0.75];
		$this->search->expects($this->once())
			->method('hybridSearch')
			->with(new VectorQuery(embedding: [0.5, 0.25], limit: 7), new KeywordFusion(results: $keyword, weights: $weights))
			->willReturn(['results' => [['id' => 'k1']]]);

		$this->assertSame(
			['results' => [['id' => 'k1']]],
			$this->service($settings)->hybridSearch(request: new HybridSearchRequest(query: 'needle', keywordResults: $keyword, limit: 7, weights: $weights))
		);
	}//end testHybridSearchEmbedsWithTheConfiguredProviderAndFuses()

	/**
	 * Build a stored vector.
	 *
	 * @param VectorSubject       $subject  The entity.
	 * @param VectorPayload       $payload  The embedding.
	 * @param VectorChunkPosition $position The chunk position.
	 * @param array<mixed>        $metadata The metadata.
	 *
	 * @return StoredVector The vector.
	 */
	private function vector(VectorSubject $subject, VectorPayload $payload, VectorChunkPosition $position, array $metadata): StoredVector {
		return new StoredVector(subject: $subject, payload: $payload, position: $position, metadata: $metadata);
	}//end vector()
}//end class
