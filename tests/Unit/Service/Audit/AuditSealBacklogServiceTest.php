<?php

/**
 * Tests for the seal-backlog driver: order, window bounds, caps, resumability.
 *
 * The driver runs against an in-memory audit table that answers the cursor
 * reads and seals a window the way AuditHashService::sealRows() does: every
 * unsealed row in the window's id range, sealed rows left as they are.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Service\Audit\AuditSealBacklogCursorInterface;
use OCA\OpenRegister\Service\Audit\AuditSealBacklogService;
use OCA\OpenRegister\Service\Audit\AuditSealWindowInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Drives AuditSealBacklogService over an in-memory audit table.
 */
#[CoversClass(AuditSealBacklogService::class)]
class AuditSealBacklogServiceTest extends TestCase {

	/**
	 * Row state by id: 'unsealed', 'sealed' or 'tombstone' (sealed, purged).
	 *
	 * @var array<int, string>
	 */
	private array $rows = [];

	/**
	 * Every window handed to sealRows(), in call order.
	 *
	 * @var list<list<int>>
	 */
	private array $windows = [];

	/**
	 * sealRows() call numbers (1-based) on which the seal lock is busy.
	 *
	 * @var list<int>
	 */
	private array $busyOnCall = [];

	/**
	 * The unsealed ids after an id, ascending, as the cursor reads them.
	 *
	 * @param int $afterId Exclusive lower bound.
	 * @param int $limit   Most ids.
	 *
	 * @return list<int>
	 */
	private function unsealedAfter(int $afterId, int $limit): array {
		$ids = [];
		foreach ($this->rows as $id => $state) {
			if ($id > $afterId && $state === 'unsealed') {
				$ids[] = $id;
			}
		}

		sort($ids);

		return array_slice($ids, 0, max(0, $limit));
	}//end unsealedAfter()

	/**
	 * A driver over $this->rows, with the given clock readings.
	 *
	 * @param list<int> $times Successive getTime() readings; the last repeats.
	 *
	 * @return AuditSealBacklogService
	 */
	private function driver(array $times = [1000]): AuditSealBacklogService {
		$cursor = $this->createMock(AuditSealBacklogCursorInterface::class);
		$cursor->method('lastLinkId')->willReturnCallback(
			function (): int {
				$last = 0;
				foreach ($this->rows as $id => $state) {
					if ($state === 'sealed' && $id > $last) {
						$last = $id;
					}
				}

				return $last;
			}
		);
		$cursor->method('unsealedIdsAfter')->willReturnCallback(
			fn (int $afterId, int $limit): array => $this->unsealedAfter($afterId, $limit)
		);
		$cursor->method('countUnsealedBefore')->willReturnCallback(
			function (int $beforeId): int {
				$count = 0;
				foreach ($this->rows as $id => $state) {
					if ($id < $beforeId && $state === 'unsealed') {
						$count++;
					}
				}

				return $count;
			}
		);

		$sealer = $this->createMock(AuditSealWindowInterface::class);
		$sealer->method('sealRows')->willReturnCallback(
			function (array $ids): int {
				$this->windows[] = $ids;
				if (in_array(count($this->windows), $this->busyOnCall, true) === true) {
					return 0;
				}

				$sealed = 0;
				foreach ($this->rows as $id => $state) {
					if ($id >= min($ids) && $id <= max($ids) && $state === 'unsealed') {
						$this->rows[$id] = 'sealed';
						$sealed++;
					}
				}

				return $sealed;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(
			function () use (&$times): int {
				if (count($times) > 1) {
					return array_shift($times);
				}

				return $times[0];
			}
		);

		return new AuditSealBacklogService($cursor, $sealer, $time);
	}//end driver()

	/**
	 * Fill ids $from..$to with one state.
	 *
	 * @param int    $from  First id.
	 * @param int    $to    Last id.
	 * @param string $state The state.
	 *
	 * @return void
	 */
	private function fill(int $from, int $to, string $state): void {
		for ($id = $from; $id <= $to; $id++) {
			$this->rows[$id] = $state;
		}
	}//end fill()

	/**
	 * A contiguous backlog is cut at MAX_WINDOW_ROWS, ascending, each window after the last.
	 *
	 * @return void
	 */
	public function testABacklogIsDrainedInAscendingWindowsOfAtMostTheRowCap(): void {
		$this->fill(1, 10, 'sealed');
		$this->fill(11, 4510, 'unsealed');

		$result = $this->driver()->drain(PHP_INT_MAX);

		$this->assertSame(4500, $result['rowsSealed']);
		$this->assertSame(3, $result['windowsProcessed']);
		$this->assertSame('drained', $result['stoppedBy']);
		$this->assertSame([2000, 2000, 500], array_map('count', $this->windows));
		$this->assertSame([11, 2011, 4011], array_map('min', $this->windows));
		foreach ($this->windows as $index => $window) {
			$sorted = $window;
			sort($sorted);
			$this->assertSame($sorted, $window, 'a window is ascending');
			if ($index > 0) {
				$this->assertGreaterThan(max($this->windows[$index - 1]), min($window), 'windows never overlap or go back');
			}
		}

		$this->assertNotContains('unsealed', $this->rows);
	}//end testABacklogIsDrainedInAscendingWindowsOfAtMostTheRowCap()

	/**
	 * Sparse unsealed rows are cut by id span, so a window never covers more than MAX_WINDOW_SPAN ids.
	 *
	 * @return void
	 */
	public function testASparseWindowIsBoundedBySpanNotOnlyByRowCount(): void {
		$this->rows[1] = 'sealed';
		foreach ([10, 15000, 30000, 45000, 60000] as $id) {
			$this->rows[$id] = 'unsealed';
		}

		$result = $this->driver()->drain(PHP_INT_MAX);

		$this->assertSame(5, $result['rowsSealed']);
		$this->assertSame([[10, 15000], [30000, 45000], [60000]], $this->windows);
		foreach ($this->windows as $window) {
			$this->assertLessThanOrEqual(AuditSealBacklogService::MAX_WINDOW_SPAN, (max($window) - min($window) + 1));
		}
	}//end testASparseWindowIsBoundedBySpanNotOnlyByRowCount()

	/**
	 * A drained backlog re-runs as a no-op: no window is sealed at all.
	 *
	 * @return void
	 */
	public function testADrainedBacklogRerunsAsANoOp(): void {
		$this->fill(1, 50, 'sealed');

		$result = $this->driver()->drain(PHP_INT_MAX);

		$this->assertSame(
			['rowsSealed' => 0, 'windowsProcessed' => 0, 'windowsAbandoned' => 0, 'stoppedBy' => 'drained', 'unsealedBehindChain' => 0],
			$result
		);
		$this->assertSame([], $this->windows);
	}//end testADrainedBacklogRerunsAsANoOp()

	/**
	 * A busy seal lock abandons that window and ends the pass; the next pass resumes without rework.
	 *
	 * @return void
	 */
	public function testABusyLockAbandonsTheWindowAndTheNextPassResumes(): void {
		$this->rows[1] = 'sealed';
		$this->fill(2, 4001, 'unsealed');
		$this->busyOnCall = [2];

		$first = $this->driver()->drain(PHP_INT_MAX);

		$this->assertSame('lock', $first['stoppedBy']);
		$this->assertSame(1, $first['windowsAbandoned']);
		$this->assertSame(2000, $first['rowsSealed']);
		$this->assertSame('unsealed', $this->rows[2002], 'the abandoned window is left as it was');

		$firstRun = $this->windows;
		$this->windows = [];
		$second = $this->driver()->drain(PHP_INT_MAX);

		$this->assertSame('drained', $second['stoppedBy']);
		$this->assertSame(2000, $second['rowsSealed']);
		$this->assertSame(2002, min($this->windows[0]), 'it resumes at the oldest row still unsealed');
		$this->assertSame([], array_intersect(array_merge(...$this->windows), $firstRun[0]), 'rows sealed earlier are not handed in again');
		$this->assertNotContains('unsealed', $this->rows);
	}//end testABusyLockAbandonsTheWindowAndTheNextPassResumes()

	/**
	 * The row cap narrows the last window and stops the pass.
	 *
	 * @return void
	 */
	public function testTheRowCapStopsThePass(): void {
		$this->rows[1] = 'sealed';
		$this->fill(2, 6001, 'unsealed');

		$result = $this->driver()->drain(2500);

		$this->assertSame(2500, $result['rowsSealed']);
		$this->assertSame('row-cap', $result['stoppedBy']);
		$this->assertSame([2000, 500], array_map('count', $this->windows));
		$this->assertSame('unsealed', $this->rows[2502]);
	}//end testTheRowCapStopsThePass()

	/**
	 * The time cap stops the pass once the deadline has passed.
	 *
	 * @return void
	 */
	public function testTheTimeCapStopsThePass(): void {
		$this->rows[1] = 'sealed';
		$this->fill(2, 6001, 'unsealed');

		// Deadline 1000 + 60; the check before window 1 reads 1000, before window 2 reads 1061.
		$result = $this->driver([1000, 1000, 1061])->drain(PHP_INT_MAX, 60);

		$this->assertSame('time-cap', $result['stoppedBy']);
		$this->assertSame(1, $result['windowsProcessed']);
		$this->assertSame(2000, $result['rowsSealed']);
	}//end testTheTimeCapStopsThePass()

	/**
	 * A cap below one row seals nothing and reads nothing to seal.
	 *
	 * @return void
	 */
	public function testAZeroRowCapSealsNothing(): void {
		$this->fill(1, 3, 'unsealed');

		$result = $this->driver()->drain(0);

		$this->assertSame('row-cap', $result['stoppedBy']);
		$this->assertSame([], $this->windows);
	}//end testAZeroRowCapSealsNothing()

	/**
	 * A gap behind the newest sealed row is never sealed in place, and is counted.
	 *
	 * Sealing row 2 would make row 3's stored hash disagree with its new
	 * predecessor: the chain would fork exactly where it was whole.
	 *
	 * @return void
	 */
	public function testAGapBehindTheNewestLinkIsCountedNotSealed(): void {
		$this->rows = [1 => 'sealed', 2 => 'unsealed', 3 => 'sealed', 4 => 'unsealed', 5 => 'unsealed'];

		$result = $this->driver()->drain(PHP_INT_MAX);

		$this->assertSame([[4, 5]], $this->windows);
		$this->assertSame('unsealed', $this->rows[2]);
		$this->assertSame(1, $result['unsealedBehindChain']);
	}//end testAGapBehindTheNewestLinkIsCountedNotSealed()

	/**
	 * A retention tombstone is no link, so a gap before it is sealed, and the tombstone is left alone.
	 *
	 * @return void
	 */
	public function testAGapBeforeATombstoneIsSealedAndTheTombstoneLeftAlone(): void {
		$this->rows = [1 => 'sealed', 2 => 'unsealed', 3 => 'tombstone', 4 => 'unsealed'];

		$result = $this->driver()->drain(PHP_INT_MAX);

		$this->assertSame([[2, 4]], $this->windows);
		$this->assertSame(2, $result['rowsSealed']);
		$this->assertSame('tombstone', $this->rows[3]);
		$this->assertSame(0, $result['unsealedBehindChain']);
	}//end testAGapBeforeATombstoneIsSealedAndTheTombstoneLeftAlone()

	/**
	 * The progress callback hears every sealed window with the running totals.
	 *
	 * @return void
	 */
	public function testEveryWindowIsReported(): void {
		$this->rows[1] = 'sealed';
		$this->fill(2, 2501, 'unsealed');
		$heard = [];

		$this->driver()->drain(
			PHP_INT_MAX,
			0,
			static function (array $counters) use (&$heard): void {
				$heard[] = [$counters['windowsProcessed'], $counters['rowsSealed']];
			}
		);

		$this->assertSame([[1, 2000], [2, 2500]], $heard);
	}//end testEveryWindowIsReported()

	/**
	 * cutWindow keeps the leading ids within the span and nothing after the first that falls out.
	 *
	 * @return void
	 */
	public function testCutWindowKeepsTheLeadingIdsWithinTheSpan(): void {
		$this->assertSame([], AuditSealBacklogService::cutWindow([]));
		$this->assertSame([5, 20004], AuditSealBacklogService::cutWindow([5, 20004, 20005, 20006]));
	}//end testCutWindowKeepsTheLeadingIdsWithinTheSpan()
}//end class
