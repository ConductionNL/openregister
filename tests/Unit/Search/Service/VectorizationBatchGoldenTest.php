<?php

/**
 * Golden test pinning the vectorizeBatch result over mixed outcomes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service;

use OCA\OpenRegister\Search\Service\Vectorization\Handlers\StoredVector;
use Exception;
use OCA\OpenRegister\Service\Vectorization\Strategies\VectorizationStrategyInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddingsInterface;
use OCA\OpenRegister\Search\Service\VectorizationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins totals, error entries and what gets stored, in serial and parallel mode.
 *
 * @covers \OCA\OpenRegister\Search\Service\VectorizationService
 */
final class VectorizationBatchGoldenTest extends TestCase {

	/**
	 * Every stored vector, as [entityId, chunkText, model].
	 *
	 * @var array<int, array{string, ?string, string}>
	 */
	private array $stored = [];

	/**
	 * Every batch request, as the texts.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $batches = [];

	/**
	 * The service with one strategy over three entities.
	 *
	 * Entity "e1" has items a, b, c, d; "e2" has none; "e3" throws on extraction;
	 * "e4" has items x and y.
	 *
	 * @return VectorizationService
	 */
	private function service(): VectorizationService {
		$items = [
			'e1' => [['text' => 'a'], ['text' => 'b'], ['text' => 'c'], ['text' => 'd']],
			'e2' => [],
			'e4' => [['text' => 'x'], ['text' => 'y']],
		];
		$strategy = $this->createMock(VectorizationStrategyInterface::class);
		$strategy->method('fetchEntities')->willReturn(['e1', 'e2', 'e3', 'e4']);
		$strategy->method('getEntityIdentifier')->willReturnCallback(static fn (string $e): string => 'id-' . $e);
		$strategy->method('extractVectorizationItems')->willReturnCallback(
			static function (string $e) use ($items): array {
				if ($e === 'e3') {
					throw new Exception('cannot extract');
				}

				return $items[$e];
			}
		);
		$strategy->method('prepareVectorMetadata')->willReturnCallback(
			static fn (string $e, array $item): array => ['entity_type' => 'object', 'entity_id' => 'id-' . $e, 'chunk_text' => $item['text']]
		);

		$vectors = $this->createMock(VectorEmbeddingsInterface::class);
		$vectors->method('generateEmbedding')->willReturnCallback(
			static function (string $text): array {
				if ($text === 'b' || $text === 'y') {
					throw new Exception('no embedding for ' . $text);
				}

				return ['embedding' => [1.0], 'model' => 'serial', 'dimensions' => 1];
			}
		);
		$vectors->method('generateBatchEmbeddings')->willReturnCallback(
			function (array $texts): array {
				$this->batches[] = $texts;
				if ($texts === ['x', 'y']) {
					throw new Exception('batch down');
				}

				return [
					['embedding' => [1.0], 'model' => 'batch', 'dimensions' => 1],
					['embedding' => null, 'error' => 'rate limited'],
					['error' => null],
				];
			}
		);
		$vectors->method('storeVector')->willReturnCallback(
			function (StoredVector $vector): int {
				if ($vector->chunkText === 'd') {
					throw new Exception('disk full');
				}

				$this->stored[] = [$vector->entityId, $vector->chunkText, $vector->model];
				return 1;
			}
		);

		$service = new VectorizationService($vectors, $this->createMock(LoggerInterface::class));
		$service->registerStrategy('object', $strategy);
		return $service;
	}//end service()

	/**
	 * Serial mode: one embedding per item, failures counted and listed.
	 *
	 * @return void
	 */
	public function testSerial(): void {
		$this->assertSame(
			[
				'success' => true,
				'message' => 'Batch vectorization completed: 3 vectorized, 3 failed',
				'entity_type' => 'object',
				'total_entities' => 4,
				'total_items' => 6,
				'vectorized' => 3,
				'failed' => 3,
				'errors' => [
					['entity_id' => 'id-e1', 'item_index' => 1, 'error' => 'no embedding for b'],
					['entity_id' => 'id-e1', 'item_index' => 3, 'error' => 'disk full'],
					['entity_id' => 'id-e3', 'error' => 'cannot extract'],
					['entity_id' => 'id-e4', 'item_index' => 1, 'error' => 'no embedding for y'],
				],
			],
			$this->service()->vectorizeBatch('object', ['mode' => 'serial'])
		);
		$this->assertSame([['id-e1', 'a', 'serial'], ['id-e1', 'c', 'serial'], ['id-e4', 'x', 'serial']], $this->stored);
		$this->assertSame([], $this->batches);
	}//end testSerial()

	/**
	 * Parallel mode: batches of three; a missing embedding is a listed
	 * failure, a failed batch counts every item and lists none.
	 *
	 * @return void
	 */
	public function testParallel(): void {
		$this->assertSame(
			[
				'success' => true,
				'message' => 'Batch vectorization completed: 1 vectorized, 5 failed',
				'entity_type' => 'object',
				'total_entities' => 4,
				'total_items' => 6,
				'vectorized' => 1,
				'failed' => 5,
				'errors' => [
					['entity_id' => 'id-e1', 'item_index' => 1, 'error' => 'rate limited'],
					['entity_id' => 'id-e1', 'item_index' => 2, 'error' => null],
					['entity_id' => 'id-e3', 'error' => 'cannot extract'],
				],
			],
			$this->service()->vectorizeBatch('object', ['mode' => 'parallel', 'batch_size' => 3])
		);
		$this->assertSame([['id-e1', 'a', 'batch']], $this->stored);
		$this->assertSame([['a', 'b', 'c'], ['d'], ['x', 'y']], $this->batches);
	}//end testParallel()

	/**
	 * Parallel with batch size one, or one item, falls back to serial.
	 *
	 * @return void
	 */
	public function testParallelWithBatchSizeOneIsSerial(): void {
		$result = $this->service()->vectorizeBatch('object', ['mode' => 'parallel', 'batch_size' => 1]);
		$this->assertSame(3, $result['vectorized']);
		$this->assertSame([], $this->batches);
	}//end testParallelWithBatchSizeOneIsSerial()
}//end class
