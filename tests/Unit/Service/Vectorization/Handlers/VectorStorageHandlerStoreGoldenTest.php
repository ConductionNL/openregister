<?php

/**
 * Golden test pinning what VectorStorageHandler::storeVector writes and logs.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vectorization\Handlers
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vectorization\Handlers;

use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSubject;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorPayload;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorChunkPosition;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\StoredVector;
use Exception;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\PgVectorPlatform;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the inserted row, its parameter types, the sidecar write and the log lines.
 *
 * The two timestamps are pinned by shape only, so the test does not depend on
 * today's date; the logged file and line are left out, being where the code is.
 *
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandler
 */
final class VectorStorageHandlerStoreGoldenTest extends TestCase {

	/**
	 * Every recorded call, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * A chunk with text and metadata is written whole, then mirrored to the sidecar.
	 *
	 * @return void
	 */
	public function testAChunkIsWrittenAndMirrored(): void {
		$handler = $this->handler(dimension: 3, failInsert: false);

		$id = $handler->storeVector(
			vector: new StoredVector(
				subject: new VectorSubject(entityType: 'file', entityId: 'f-1'),
				payload: new VectorPayload(embedding: [0.5, -1.0, 2.25], model: 'nomic', dimensions: 3),
				position: new VectorChunkPosition(chunkIndex: 4, totalChunks: 9, chunkText: "  héllo\x00\x07  \n wörld  "),
				metadata: ['page' => 2, 'title' => 'A']
			)
		);

		$this->assertSame(41, $id);
		$this->assertSame(
			[
				['debug', '[VectorStorageHandler] Storing vector in database', ['entity_type' => 'file', 'entity_id' => 'f-1', 'chunk_index' => 4, 'dimensions' => 3]],
				['insert', 'openregister_vectors'],
				['param', 'file', IQueryBuilder::PARAM_STR],
				['param', 'f-1', IQueryBuilder::PARAM_STR],
				['param', 4, IQueryBuilder::PARAM_INT],
				['param', 9, IQueryBuilder::PARAM_INT],
				['param', 'héllo wörld', IQueryBuilder::PARAM_STR],
				['param', serialize([0.5, -1.0, 2.25]), IQueryBuilder::PARAM_LOB],
				['param', 'nomic', IQueryBuilder::PARAM_STR],
				['param', 3, IQueryBuilder::PARAM_INT],
				['param', '{"page":2,"title":"A"}', IQueryBuilder::PARAM_STR],
				['param', 'TIMESTAMP', IQueryBuilder::PARAM_STR],
				['param', 'TIMESTAMP', IQueryBuilder::PARAM_STR],
				['values', ['entity_type', 'entity_id', 'chunk_index', 'total_chunks', 'chunk_text', 'embedding', 'embedding_model', 'embedding_dimensions', 'metadata', 'created_at', 'updated_at']],
				['executeStatement'],
				['getLastInsertId'],
				['sidecar', ['41', '[0.5,-1,2.25]']],
				['info', '[VectorStorageHandler] Vector stored successfully in database', ['vector_id' => 41, 'entity_type' => 'file', 'entity_id' => 'f-1']],
			],
			$this->calls
		);
	}//end testAChunkIsWrittenAndMirrored()

	/**
	 * Without text or metadata both are null; a dimension mismatch skips the sidecar.
	 *
	 * @return void
	 */
	public function testAnObjectWithDefaultsSkipsTheSidecar(): void {
		$handler = $this->handler(dimension: 768, failInsert: false);

		$id = $handler->storeVector(
			vector: new StoredVector(
				subject: new VectorSubject(entityType: 'object', entityId: 'o-1'),
				payload: new VectorPayload(embedding: [1.0], model: 'm', dimensions: 1),
				position: new VectorChunkPosition(chunkIndex: 0, totalChunks: 1, chunkText: null),
				metadata: []
			)
		);

		$this->assertSame(41, $id);
		$this->assertSame(
			[
				['param', 0, IQueryBuilder::PARAM_INT],
				['param', 1, IQueryBuilder::PARAM_INT],
				['param', null, IQueryBuilder::PARAM_STR],
			],
			[$this->calls[4], $this->calls[5], $this->calls[6]]
		);
		$this->assertSame(['param', null, IQueryBuilder::PARAM_STR], $this->calls[10]);
		$this->assertSame(
			['info', '[VectorStorageHandler] Vector stored successfully in database', ['vector_id' => 41, 'entity_type' => 'object', 'entity_id' => 'o-1']],
			$this->calls[16]
		);
		$this->assertCount(17, $this->calls);
	}//end testAnObjectWithDefaultsSkipsTheSidecar()

	/**
	 * A failed insert is logged and rethrown with the storage prefix.
	 *
	 * @return void
	 */
	public function testAFailedInsertIsLoggedAndRethrown(): void {
		$handler = $this->handler(dimension: null, failInsert: true);

		try {
			$handler->storeVector(
				vector: new StoredVector(
					subject: new VectorSubject(entityType: 'object', entityId: 'o-2'),
					payload: new VectorPayload(embedding: [], model: 'm', dimensions: 0),
					position: new VectorChunkPosition(chunkIndex: 0, totalChunks: 1, chunkText: null),
					metadata: []
				)
			);
			$this->fail('The failure should be rethrown.');
		} catch (Exception $exception) {
			$this->assertSame(Exception::class, get_class($exception));
			$this->assertSame('Vector storage failed: disk full', $exception->getMessage());
		}

		$this->assertSame(['executeStatement'], $this->calls[14]);
		$this->assertSame(
			['error', '[VectorStorageHandler] Failed to store vector in database', ['error' => 'disk full', 'entity_type' => 'object', 'entity_id' => 'o-2']],
			$this->calls[15]
		);
		$this->assertCount(16, $this->calls);
	}//end testAFailedInsertIsLoggedAndRethrown()

	/**
	 * The handler over recording doubles.
	 *
	 * @param int|null $dimension  The sidecar's declared dimension, or null without pgvector.
	 * @param bool     $failInsert Whether the insert throws.
	 *
	 * @return VectorStorageHandler The handler.
	 */
	private function handler(?int $dimension, bool $failInsert): VectorStorageHandler {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->builder(failInsert: $failInsert));
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->calls[] = ['sidecar', $params];
				return 1;
			}
		);

		$pgVector = $this->createMock(PgVectorPlatform::class);
		$pgVector->method('getVectorColumnDimension')->willReturn($dimension);
		$pgVector->method('formatVector')->willReturnCallback(
			static fn (array $embedding): string => (string)json_encode($embedding)
		);

		return new VectorStorageHandler($db, $pgVector, $this->logger());
	}//end handler()

	/**
	 * A logger that records level, message and context without file and line.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->calls[] = [$level, (string)$message, $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * A query builder that records the insert.
	 *
	 * @param bool $failInsert Whether executeStatement throws.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function builder(bool $failInsert): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->calls[] = ['insert', $table];
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): string {
				if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
					$value = 'TIMESTAMP';
				}

				$this->calls[] = ['param', $value, $type];
				return ':p'.count($this->calls);
			}
		);
		$qb->method('values')->willReturnCallback(
			function (array $values) use ($qb): IQueryBuilder {
				$this->calls[] = ['values', array_keys($values)];
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($failInsert): int {
				$this->calls[] = ['executeStatement'];
				if ($failInsert === true) {
					throw new RuntimeException('disk full');
				}

				return 1;
			}
		);
		$qb->method('getLastInsertId')->willReturnCallback(
			function (): int {
				$this->calls[] = ['getLastInsertId'];
				return 41;
			}
		);

		return $qb;
	}//end builder()
}//end class
