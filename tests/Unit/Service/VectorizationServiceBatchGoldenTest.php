<?php

declare(strict_types=1);

/**
 * VectorizationService batch result characterisation tests.
 *
 * Pins the whole vectorizeBatch() result (counts, message and every listed
 * error) for the serial path, the batched path, a batch of one, and an
 * entity that fails outright.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Service\Vectorization\Strategies\VectorizationStrategyInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Search\Service\VectorizationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VectorizationServiceBatchGoldenTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $stored = [];

	/**
	 * A service with one strategy over two entities: `a` with three items, `b` failing.
	 *
	 * @param VectorEmbeddings $vectors The embedding backend.
	 *
	 * @return VectorizationService
	 */
	private function service(VectorEmbeddings $vectors): VectorizationService {
		$strategy = $this->createMock(VectorizationStrategyInterface::class);
		$strategy->method('fetchEntities')->willReturn(['a', 'b']);
		$strategy->method('getEntityIdentifier')->willReturnCallback(static fn ($entity) => 'id-' . $entity);
		$strategy->method('extractVectorizationItems')->willReturnCallback(
			static function ($entity) {
				if ($entity === 'b') {
					throw new Exception('no items for b');
				}

				return [['text' => 'one'], ['text' => 'two'], ['text' => 'three']];
			}
		);
		$strategy->method('prepareVectorMetadata')->willReturnCallback(
			static fn ($entity, $item) => ['entity_type' => 'object', 'entity_id' => 'id-' . $entity, 'chunk_text' => $item['text']]
		);

		$vectors->method('storeVector')->willReturnCallback(
			function (...$args) {
				$this->stored[] = $args;
				return 1;
			}
		);

		$service = new VectorizationService($vectors, $this->createMock(LoggerInterface::class));
		$service->registerStrategy('object', $strategy);

		return $service;
	}//end service()

	/**
	 * The serial path embeds each item and lists the failing one with its index.
	 *
	 * @return void
	 */
	public function testSerialBatchResult(): void {
		$vectors = $this->createMock(VectorEmbeddings::class);
		$vectors->method('generateEmbedding')->willReturnCallback(
			static function (string $text) {
				if ($text === 'two') {
					throw new Exception('embed failed');
				}

				return ['embedding' => [0.5], 'model' => 'm', 'dimensions' => 1];
			}
		);

		$result = $this->service($vectors)->vectorizeBatch('object');

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Batch vectorization completed: 2 vectorized, 1 failed',
				'entity_type' => 'object',
				'total_entities' => 2,
				'total_items' => 3,
				'vectorized' => 2,
				'failed' => 1,
				'errors' => [
					['entity_id' => 'id-a', 'item_index' => 1, 'error' => 'embed failed'],
					['entity_id' => 'id-b', 'error' => 'no items for b'],
				],
			],
			$result
		);
		$this->assertCount(2, $this->stored);
	}//end testSerialBatchResult()

	/**
	 * The batched path lists an item without an embedding, with the backend's error or the generic one.
	 *
	 * @return void
	 */
	public function testParallelBatchResult(): void {
		$vectors = $this->createMock(VectorEmbeddings::class);
		$vectors->method('generateBatchEmbeddings')->willReturnCallback(
			static function (array $texts) {
				if ($texts === ['one', 'two']) {
					return [['embedding' => [0.1], 'model' => 'm', 'dimensions' => 1], ['embedding' => null, 'error' => 'rate limited']];
				}

				return [];
			}
		);

		$result = $this->service($vectors)->vectorizeBatch('object', ['mode' => 'parallel', 'batch_size' => 2]);

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Batch vectorization completed: 1 vectorized, 2 failed',
				'entity_type' => 'object',
				'total_entities' => 2,
				'total_items' => 3,
				'vectorized' => 1,
				'failed' => 2,
				'errors' => [
					['entity_id' => 'id-a', 'item_index' => 1, 'error' => 'rate limited'],
					['entity_id' => 'id-a', 'item_index' => 0, 'error' => 'Embedding generation failed'],
					['entity_id' => 'id-b', 'error' => 'no items for b'],
				],
			],
			$result
		);
		$this->assertCount(1, $this->stored);
	}//end testParallelBatchResult()

	/**
	 * A failing batch request counts the whole batch as failed without listing it.
	 *
	 * @return void
	 */
	public function testFailingBatchRequestCountsTheBatch(): void {
		$vectors = $this->createMock(VectorEmbeddings::class);
		$vectors->method('generateBatchEmbeddings')->willThrowException(new Exception('backend down'));

		$result = $this->service($vectors)->vectorizeBatch('object', ['mode' => 'parallel', 'batch_size' => 50]);

		$this->assertSame(3, $result['failed']);
		$this->assertSame(0, $result['vectorized']);
		$this->assertSame([['entity_id' => 'id-b', 'error' => 'no items for b']], $result['errors']);
	}//end testFailingBatchRequestCountsTheBatch()

	/**
	 * Parallel mode with a batch size of one runs serially.
	 *
	 * @return void
	 */
	public function testBatchSizeOneRunsSerially(): void {
		$vectors = $this->createMock(VectorEmbeddings::class);
		$vectors->expects($this->never())->method('generateBatchEmbeddings');
		$vectors->expects($this->exactly(3))->method('generateEmbedding')
			->willReturn(['embedding' => [0.5], 'model' => 'm', 'dimensions' => 1]);

		$result = $this->service($vectors)->vectorizeBatch('object', ['mode' => 'parallel', 'batch_size' => 1]);

		$this->assertSame(3, $result['vectorized']);
		// An entity that fails outright is listed but not counted as a failed item.
		$this->assertSame(0, $result['failed']);
		$this->assertSame([['entity_id' => 'id-b', 'error' => 'no items for b']], $result['errors']);
	}//end testBatchSizeOneRunsSerially()
}//end class
