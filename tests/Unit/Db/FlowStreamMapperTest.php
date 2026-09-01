<?php

/**
 * FlowStreamMapper over a fluent query-builder double: reads, the
 * conditional-UPDATE sequence allocation, and deletes.
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
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowStreamMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Streams mapper.
 *
 * @covers \OCA\OpenRegister\Db\FlowStreamMapper
 * @covers \OCA\OpenRegister\Db\FlowStream
 */
class FlowStreamMapperTest extends TestCase {

	private IDBConnection&MockObject $db;

	private IQueryBuilder&MockObject $qb;

	private FlowStreamMapper $mapper;

	/** @var array<int, string> */
	private array $calls = [];

	/** @var array<int, array<string, mixed>> rows the next query answers */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		foreach (['delete', 'update', 'set', 'select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults'] as $fluent) {
			$this->qb->method($fluent)->willReturnCallback(function () use ($fluent): IQueryBuilder {
				$this->calls[] = $fluent;
				return $this->qb;
			});
		}

		$this->qb->method('createNamedParameter')->willReturn(':p');
		$this->qb->method('createFunction')->willReturnCallback(static fn (string $f): string => $f);
		$this->qb->method('getSQL')->willReturn('SELECT uuid FROM runs');
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$this->qb->method('expr')->willReturn($expr);
		$this->qb->method('executeQuery')->willReturnCallback(function (): IResult {
			$result = $this->createMock(IResult::class);
			$queue = $this->rows;
			$result->method('fetch')->willReturnCallback(static function () use (&$queue): mixed {
				return array_shift($queue) ?? false;
			});
			return $result;
		});
		$this->db->method('getQueryBuilder')->willReturn($this->qb);

		$this->mapper = new FlowStreamMapper($this->db);
	}//end setUp()

	/**
	 * A stream row.
	 *
	 * @param int $next The row's next_sequence.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(int $next = 1): array {
		return [
			'id' => 1,
			'run_uuid' => 'run-1',
			'stream_id' => 's1',
			'ordinal_path' => '0001',
			'parent_stream_id' => null,
			'place' => 'p',
			'status' => 'running',
			'resume_at' => null,
			'next_sequence' => $next,
			'error' => null,
			'created' => '2026-09-01 08:00:00',
			'updated' => '2026-09-01 08:00:00',
		];
	}//end row()

	public function testFindByRunOrdersByOrdinalPath(): void {
		$this->rows = [$this->row()];
		$found = $this->mapper->findByRun(runUuid: 'run-1');
		$this->assertCount(1, $found);
		$this->assertSame('0001', $found[0]->getOrdinalPath());
		$this->assertSame('p', $found[0]->getPlace());
		$this->assertContains('orderBy', $this->calls);
		$this->assertContains('addOrderBy', $this->calls);
	}//end testFindByRunOrdersByOrdinalPath()

	public function testFindByRunAndStreamReturnsNullWhenAbsent(): void {
		$this->rows = [];
		$this->assertNull($this->mapper->findByRunAndStream(runUuid: 'run-1', streamId: 'nope'));
		$this->rows = [$this->row()];
		$this->assertSame('s1', $this->mapper->findByRunAndStream(runUuid: 'run-1', streamId: 's1')?->getStreamId());
	}//end testFindByRunAndStreamReturnsNullWhenAbsent()

	public function testAllocateNextSequenceReturnsZeroWhenTheStreamRowIsMissing(): void {
		$this->qb->method('executeStatement')->willReturn(0);
		$this->assertSame(0, $this->mapper->allocateNextSequence(runUuid: 'run-1', streamId: 'nope'));
	}//end testAllocateNextSequenceReturnsZeroWhenTheStreamRowIsMissing()

	public function testAllocateNextSequenceHandsOutTheValueBeforeTheIncrement(): void {
		// The UPDATE bumps next_sequence to 5; the position reserved is 4.
		$this->qb->method('executeStatement')->willReturn(1);
		$this->rows = [$this->row(next: 5)];
		$this->assertSame(4, $this->mapper->allocateNextSequence(runUuid: 'run-1', streamId: 's1'));
		$this->assertContains('update', $this->calls);
		$this->assertContains('set', $this->calls);
	}//end testAllocateNextSequenceHandsOutTheValueBeforeTheIncrement()

	public function testDeletesRunAndOrphans(): void {
		$this->qb->method('executeStatement')->willReturn(3);
		$this->assertSame(3, $this->mapper->deleteByRun(runUuid: 'run-1'));
		$this->assertSame(3, $this->mapper->deleteOrphans());
	}//end testDeletesRunAndOrphans()
}//end class
