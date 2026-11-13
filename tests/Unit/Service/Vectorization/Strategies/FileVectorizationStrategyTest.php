<?php

/**
 * FileVectorizationStrategy: which file chunks are fetched and what is stored per chunk.
 *
 * Covers the max_files cap (query limit and the per-source cut-off while
 * grouping), the item shape handed to the embedder, and the vector metadata
 * (total chunk count of the source, 500-character preview).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vectorization\Strategies
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vectorization\Strategies;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategy;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategy
 * @uses \OCA\OpenRegister\Files\Db\Chunk
 */
class FileVectorizationStrategyTest extends TestCase {

	private ChunkMapperInterface&MockObject $chunkMapper;
	private IDBConnection&MockObject $db;
	private IQueryBuilder&MockObject $qb;
	private FileVectorizationStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();
		$this->chunkMapper = $this->createMock(ChunkMapperInterface::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('source_type = :file');
		$this->qb->method('expr')->willReturn($expr);
		$this->qb->method('createNamedParameter')->willReturn(':file');
		foreach (['select', 'from', 'where', 'orderBy', 'addOrderBy', 'setMaxResults'] as $fluent) {
			$this->qb->method($fluent)->willReturnSelf();
		}

		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->strategy = new FileVectorizationStrategy($this->chunkMapper, $this->db, $this->createMock(LoggerInterface::class));
	}

	private function chunk(int $sourceId, int $index, string $text = 'text'): Chunk {
		$chunk = new Chunk();
		$chunk->setSourceId($sourceId);
		$chunk->setChunkIndex($index);
		$chunk->setTextContent($text);
		$chunk->setStartOffset($index * 10);
		$chunk->setEndOffset(($index * 10) + 9);
		return $chunk;
	}

	public function testWithoutMaxFilesReturnsEveryChunkAndSetsNoLimit(): void {
		$this->qb->expects($this->never())->method('setMaxResults');
		$chunks = [$this->chunk(1, 0), $this->chunk(1, 1), $this->chunk(2, 0), $this->chunk(3, 0)];
		$this->chunkMapper->expects($this->once())->method('findEntitiesPublic')->with($this->qb)->willReturn($chunks);

		$this->assertSame($chunks, $this->strategy->fetchEntities([]));
	}

	public function testMaxFilesLimitsTheQueryAndStopsAtTheFirstChunkOfAnExtraFile(): void {
		$this->qb->expects($this->once())->method('setMaxResults')->with(200);
		$chunks = [$this->chunk(1, 0), $this->chunk(1, 1), $this->chunk(2, 0), $this->chunk(2, 1), $this->chunk(3, 0), $this->chunk(3, 1)];
		$this->chunkMapper->method('findEntitiesPublic')->willReturn($chunks);

		$result = $this->strategy->fetchEntities(['max_files' => '2']);

		$this->assertSame(array_slice($chunks, 0, 4), $result);
	}

	public function testMaxFilesNotReachedKeepsAllChunks(): void {
		$this->qb->expects($this->once())->method('setMaxResults')->with(500);
		$chunks = [$this->chunk(7, 0), $this->chunk(8, 0)];
		$this->chunkMapper->method('findEntitiesPublic')->willReturn($chunks);

		$this->assertSame($chunks, $this->strategy->fetchEntities(['max_files' => 5]));
	}

	public function testNoChunksYieldsAnEmptyList(): void {
		$this->chunkMapper->method('findEntitiesPublic')->willReturn([]);

		$this->assertSame([], $this->strategy->fetchEntities(['max_files' => 1]));
	}

	public function testExtractVectorizationItemsReturnsTheChunkAsOneItem(): void {
		$items = $this->strategy->extractVectorizationItems($this->chunk(4, 2, 'hello'));

		$this->assertSame([['text' => 'hello', 'index' => 2, 'start_offset' => 20, 'end_offset' => 29]], $items);
	}

	public function testPrepareVectorMetadataCountsSourceChunksAndTruncatesThePreview(): void {
		$entity = $this->chunk(42, 1);
		$this->chunkMapper->expects($this->once())->method('findBySource')->with('file', 42)
			->willReturn([$this->chunk(42, 0), $entity, $this->chunk(42, 2)]);
		$item = ['text' => str_repeat('a', 600), 'index' => 1, 'start_offset' => 10, 'end_offset' => 19];

		$meta = $this->strategy->prepareVectorMetadata($entity, $item);

		$this->assertSame('file', $meta['entity_type']);
		$this->assertSame('42', $meta['entity_id']);
		$this->assertSame(1, $meta['chunk_index']);
		$this->assertSame(3, $meta['total_chunks']);
		$this->assertSame(str_repeat('a', 500), $meta['chunk_text']);
		$this->assertSame(['source_id' => 42, 'start_offset' => 10, 'end_offset' => 19], $meta['additional_metadata']);
	}

	public function testEntityIdentifierIsTheSourceFileId(): void {
		$this->assertSame(99, $this->strategy->getEntityIdentifier($this->chunk(99, 0)));
	}
}
