<?php

/**
 * Golden test pinning the per-row and failure outcomes of
 * VectorStorageHandler::backfillEmbeddingVectors.
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

use Exception;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\PgVectorPlatform;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandler;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the branches the existing tests leave open: wrong-dimension and
 * non-string BLOBs, a failing sidecar write, a failing count query and a
 * failing select, plus the batch limit and the log lines.
 *
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandler
 */
final class VectorStorageHandlerBackfillGoldenTest extends TestCase {

	/**
	 * Logged lines as [level, message, vector_id|error].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logs = [];

	/**
	 * Build a handler over the given rows and failure switches.
	 *
	 * @param array<int, array<string, mixed>> $rows       Rows the select returns
	 * @param bool                             $failSelect Throw from the select
	 * @param bool                             $failCount  Throw from the count
	 * @param array<int, int>                  $failWrite  Vector ids whose sidecar write throws
	 * @param array<int, string>               $sql        Captured SQL (by reference)
	 *
	 * @return VectorStorageHandler
	 */
	private function handler(array $rows, bool $failSelect, bool $failCount, array $failWrite, array &$sql): VectorStorageHandler {
		$pgVector = $this->createMock(PgVectorPlatform::class);
		$pgVector->method('getVectorColumnDimension')->willReturn(2);
		$pgVector->method('formatVector')->willReturn('[1,2]');

		$select = $this->createMock(IResult::class);
		$select->method('fetchAll')->willReturn($rows);
		$count = $this->createMock(IResult::class);
		$count->method('fetchOne')->willReturn('7');

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $query) use (&$sql, $select, $count, $failSelect, $failCount) {
				$sql[] = $query;
				if (str_contains($query, 'COUNT(*)') === true) {
					if ($failCount === true) {
						throw new Exception('count down');
					}

					return $count;
				}

				if ($failSelect === true) {
					throw new Exception('select down');
				}

				return $select;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $query, array $params) use ($failWrite): int {
				if (in_array((int)$params[0], $failWrite, true) === true) {
					throw new Exception('write down');
				}

				return 1;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context['vector_id'] ?? $context['error'] ?? null];
				}
			);
		}

		return new VectorStorageHandler($db, $pgVector, $logger);
	}//end handler()

	/**
	 * Wrong-dimension, non-string and write-failing rows all count as failed.
	 *
	 * @return void
	 */
	public function testRowOutcomesAndLogs(): void {
		$sql = [];
		$rows = [
			['id' => 3, 'embedding' => serialize([0.1, 0.2, 0.3])],
			['id' => 4, 'embedding' => 42],
			['id' => 5, 'embedding' => serialize([0.1, 0.2])],
			['id' => 6, 'embedding' => serialize([0.3, 0.4])],
			['id' => 8, 'embedding' => serialize('scalar')],
		];
		$result = $this->handler($rows, false, false, [5], $sql)->backfillEmbeddingVectors(25, 1);

		$this->assertSame(['converted' => 1, 'failed' => 4, 'last_id' => 8, 'remaining' => 7], $result);
		$this->assertCount(2, $sql);
		$this->assertStringEndsWith('ORDER BY v.id ASC LIMIT 25', $sql[0]);
		$skip = '[VectorStorageHandler] Skipping backfill for unparseable embedding BLOB';
		$this->assertSame(
			[
				['warning', $skip, 3],
				['warning', $skip, 4],
				['warning', '[VectorStorageHandler] Failed to write pgvector ANN sidecar row', 5],
				['warning', $skip, 8],
			],
			$this->logs
		);
	}//end testRowOutcomesAndLogs()

	/**
	 * A failing count query keeps the batch tallies and reports nothing remaining.
	 *
	 * @return void
	 */
	public function testCountFailureKeepsTallies(): void {
		$sql = [];
		$rows = [
			['id' => 9, 'embedding' => serialize([0.1, 0.2])],
			['id' => 10, 'embedding' => 'garbage'],
		];
		$result = $this->handler($rows, false, true, [], $sql)->backfillEmbeddingVectors(5, 0);

		$this->assertSame(['converted' => 1, 'failed' => 1, 'last_id' => 10, 'remaining' => 0], $result);
		$this->assertSame(['error', '[VectorStorageHandler] pgvector warm-up backfill batch failed', 'count down'], end($this->logs));
	}//end testCountFailureKeepsTallies()

	/**
	 * A failing select returns the cursor unchanged and zero counts.
	 *
	 * @return void
	 */
	public function testSelectFailureReturnsCursor(): void {
		$sql = [];
		$result = $this->handler([], true, false, [], $sql)->backfillEmbeddingVectors(5, 33);

		$this->assertSame(['converted' => 0, 'failed' => 0, 'last_id' => 33, 'remaining' => 0], $result);
		$this->assertSame([['error', '[VectorStorageHandler] pgvector warm-up backfill batch failed', 'select down']], $this->logs);
	}//end testSelectFailureReturnsCursor()
}//end class
