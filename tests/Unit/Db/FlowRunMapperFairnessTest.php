<?php

/**
 * The queue is shared between waiting flows, not handed out by arrival order.
 *
 * A single global FIFO makes queue position a function of nothing but arrival
 * order, so one flow that queues in bulk owns the queue until it drains.
 * Measured on the dev instance 2026-08-02: one flow held 9,644 queued runs and
 * anything queued behind them waited about thirty-two hours to start.
 *
 * These tests exercise the SHARING RULE, not the SQL: the two query seams are
 * stubbed so what is asserted is the decision `findQueued()` makes about who
 * gets the batch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlowRunMapperFairnessTest extends TestCase {

	/**
	 * A mapper whose two query seams answer from an in-memory queue.
	 *
	 * @param array<string, int> $depths Queued runs per flow, in the order the
	 *                                   flows have been waiting (longest first).
	 *
	 * @return FlowRunMapper&MockObject The mapper.
	 */
	private function mapperWithQueue(array $depths): FlowRunMapper&MockObject {
		$mapper = $this->getMockBuilder(FlowRunMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['flowsWithQueuedRuns', 'queuedForFlow'])
			->getMock();

		$flowIds = array_keys($depths);

		$mapper->method('flowsWithQueuedRuns')->willReturnCallback(
			static fn (int $limit): array => array_slice($flowIds, 0, $limit)
		);

		$mapper->method('queuedForFlow')->willReturnCallback(
			static function (string $flowId, int $limit) use ($depths): array {
				$runs = [];
				$take = min($limit, ($depths[$flowId] ?? 0));
				for ($i = 0; $i < $take; $i++) {
					$run = new FlowRun();
					$run->setUuid($flowId . '-run-' . $i);
					$run->setFlowId($flowId);
					$run->setStatus(FlowRun::STATUS_QUEUED);
					$runs[] = $run;
				}

				return $runs;
			}
		);

		return $mapper;
	}

	/**
	 * How many of the claimed runs belong to each flow.
	 *
	 * @param array<int, FlowRun> $runs The claimed runs.
	 *
	 * @return array<string, int> Counts keyed by flow id.
	 */
	private function countByFlow(array $runs): array {
		$counts = [];
		foreach ($runs as $run) {
			$flowId = (string)$run->getFlowId();
			$counts[$flowId] = (($counts[$flowId] ?? 0) + 1);
		}

		return $counts;
	}

	/**
	 * The whole point: one queued run is not buried under someone else's burst.
	 *
	 * This is the dev instance's shape — 9,644 runs of one flow, and a fresh
	 * scheduled tick behind them.
	 */
	public function testASingleQueuedRunIsNotStarvedByAHugeBacklog(): void {
		$mapper = $this->mapperWithQueue(['burst' => 9000, 'scheduled' => 1]);

		$counts = $this->countByFlow($mapper->findQueued(limit: 25));

		$this->assertSame(1, ($counts['scheduled'] ?? 0), 'the lone scheduled run must be claimed this pass');
		$this->assertLessThanOrEqual(13, ($counts['burst'] ?? 0), 'the burst must not take the whole batch');
	}

	/**
	 * Two flows that both have more work than the batch split it.
	 */
	public function testTheBatchIsSharedBetweenFlowsThatBothHaveDeepBacklogs(): void {
		$mapper = $this->mapperWithQueue(['a' => 500, 'b' => 500]);

		$counts = $this->countByFlow($mapper->findQueued(limit: 25));

		$this->assertArrayHasKey('a', $counts);
		$this->assertArrayHasKey('b', $counts);
		$this->assertLessThanOrEqual(13, $counts['a']);
		$this->assertLessThanOrEqual(13, $counts['b']);
	}

	/**
	 * The common case must not regress: one waiting flow still gets the batch.
	 */
	public function testOneWaitingFlowStillTakesTheWholeBatch(): void {
		$mapper = $this->mapperWithQueue(['only' => 500]);

		$runs = $mapper->findQueued(limit: 25);

		$this->assertCount(25, $runs);
		$this->assertSame(['only' => 25], $this->countByFlow($runs));
	}

	/**
	 * More flows than slots must still claim work — a floored share would be
	 * zero and the pass would do nothing at all.
	 */
	public function testMoreWaitingFlowsThanSlotsStillClaimsRuns(): void {
		$depths = [];
		for ($i = 0; $i < 40; $i++) {
			$depths['flow-' . $i] = 5;
		}

		$runs = $this->mapperWithQueue($depths)->findQueued(limit: 25);

		$this->assertNotEmpty($runs, 'a pass must never claim nothing while runs are waiting');
		$this->assertCount(25, $runs);
		foreach ($this->countByFlow($runs) as $flowId => $count) {
			$this->assertSame(1, $count, $flowId . ' must not exceed its one-run share');
		}
	}

	/**
	 * The batch ceiling is absolute — sharing must not overrun it.
	 */
	public function testTheBatchLimitIsNeverExceeded(): void {
		$mapper = $this->mapperWithQueue(['a' => 100, 'b' => 100, 'c' => 100]);

		$this->assertLessThanOrEqual(25, count($mapper->findQueued(limit: 25)));
	}

	/**
	 * Flows are offered their share longest-waiting first; that ordering is
	 * what makes the rotation self-maintaining.
	 */
	public function testTheLongestWaitingFlowIsServedFirst(): void {
		$mapper = $this->mapperWithQueue(['older' => 50, 'newer' => 50]);

		$runs = $mapper->findQueued(limit: 25);

		$this->assertSame('older', $runs[0]->getFlowId());
	}

	/**
	 * An empty queue claims nothing and asks for nothing per flow.
	 */
	public function testAnEmptyQueueClaimsNothing(): void {
		$this->assertSame([], $this->mapperWithQueue([])->findQueued(limit: 25));
	}

	/**
	 * A non-positive batch is a no-op rather than an unbounded query.
	 */
	public function testANonPositiveLimitClaimsNothing(): void {
		$this->assertSame([], $this->mapperWithQueue(['a' => 10])->findQueued(limit: 0));
	}

	/**
	 * Expiry marks the run failed with the caller's reason, and does not touch
	 * a row that stopped being queued between the read and the write.
	 */
	public function testExpiryFailsStaleRunsAndSkipsOnesThatMovedOn(): void {
		$stale = new FlowRun();
		$stale->setUuid('stale-1');
		$stale->setFlowId('f1');
		$stale->setStatus(FlowRun::STATUS_QUEUED);

		$raced = new FlowRun();
		$raced->setUuid('raced-1');
		$raced->setFlowId('f1');
		// A worker pass claimed it after the expiry query read it.
		$raced->setStatus(FlowRun::STATUS_RUNNING);

		$mapper = $this->getMockBuilder(FlowRunMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['queuedBefore', 'update'])
			->getMock();

		$mapper->method('queuedBefore')->willReturn([$stale, $raced]);
		$mapper->method('update')->willReturnArgument(0);

		$expired = $mapper->expireQueuedBefore(
			before: new DateTime('-1 day'),
			reason: 'Expired: waited too long.',
			limit: 500
		);

		$this->assertCount(1, $expired, 'only the still-queued run may be expired');
		$this->assertSame('stale-1', $expired[0]->getUuid());
		$this->assertSame(FlowRun::STATUS_FAILED, $stale->getStatus());
		$this->assertSame('Expired: waited too long.', $stale->getError());
		$this->assertSame(FlowRun::STATUS_RUNNING, $raced->getStatus(), 'the raced run must be left alone');
	}
}
