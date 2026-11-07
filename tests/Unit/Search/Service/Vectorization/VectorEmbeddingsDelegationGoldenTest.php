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

use OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandlerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
	private function service(): VectorEmbeddings {
		return new VectorEmbeddings(
			$this->createMock(IDBConnection::class),
			$this->createMock(SettingsServiceInterface::class),
			new VectorEmbeddingHandlers($this->generator, $this->storage, $this->search, $this->stats),
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Every storeVector argument reaches the storage handler and its id comes back.
	 *
	 * @return void
	 */
	public function testStoreVectorPassesEveryArgumentThrough(): void {
		$this->storage->expects($this->once())
			->method('storeVector')
			->with('file', 'uuid-1', [0.1, 0.2], 'model-x', 2, 3, 7, 'text', ['k' => 'v'])
			->willReturn(42);

		$id = $this->service()->storeVector(
			entityType: 'file',
			entityId: 'uuid-1',
			embedding: [0.1, 0.2],
			model: 'model-x',
			dimensions: 2,
			chunkIndex: 3,
			totalChunks: 7,
			chunkText: 'text',
			metadata: ['k' => 'v']
		);

		$this->assertSame(42, $id);
	}//end testStoreVectorPassesEveryArgumentThrough()

	/**
	 * The defaults of storeVector reach the storage handler.
	 *
	 * @return void
	 */
	public function testStoreVectorDefaults(): void {
		$this->storage->expects($this->once())
			->method('storeVector')
			->with('object', 'uuid-2', [1.0], 'm', 1, 0, 1, null, [])
			->willReturn(5);

		$this->assertSame(
			5,
			$this->service()->storeVector(entityType: 'object', entityId: 'uuid-2', embedding: [1.0], model: 'm', dimensions: 1)
		);
	}//end testStoreVectorDefaults()

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

		$this->service()->storeVector(entityType: 'object', entityId: 'u', embedding: [], model: 'm', dimensions: 0);
		$this->addToAssertionCount(1);
	}//end testSearchHandlerUntouchedByStorage()
}//end class
