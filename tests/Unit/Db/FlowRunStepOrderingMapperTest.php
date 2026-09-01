<?php

/**
 * The run-log reads: canonical order by branch, wall-clock order on request,
 * and the run row's locked read for the commit path.
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

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Ordering and locking reads.
 *
 * @covers \OCA\OpenRegister\Db\FlowRunStepMapper
 * @covers \OCA\OpenRegister\Db\FlowRunMapper
 * @covers \OCA\OpenRegister\Db\FlowRunStep
 * @covers \OCA\OpenRegister\Db\FlowRun
 */
class FlowRunStepOrderingMapperTest extends TestCase {

	private IDBConnection&MockObject $db;

	private IQueryBuilder&MockObject $qb;

	/** @var array<int, array{0: string, 1: array}> fluent calls with their arguments */
	private array $calls = [];

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'forUpdate', 'setMaxResults'] as $fluent) {
			$this->qb->method($fluent)->willReturnCallback(function (...$args) use ($fluent): IQueryBuilder {
				$this->calls[] = [$fluent, $args];
				return $this->qb;
			});
		}

		$this->qb->method('createNamedParameter')->willReturn(':p');
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
	}//end setUp()

	/**
	 * The ordering clauses issued, as `method:column`.
	 *
	 * @return array<int, string> The clauses.
	 */
	private function ordering(): array {
		$out = [];
		foreach ($this->calls as [$method, $args]) {
			if (in_array($method, ['orderBy', 'addOrderBy'], true) === true) {
				$out[] = $method . ':' . (string)($args[0] ?? '');
			}
		}

		return $out;
	}//end ordering()

	public function testFindByRunIsCanonicalByOrdinalPathThenSequence(): void {
		$this->rows = [['id' => 1, 'run_uuid' => 'r', 'flow_id' => 'f', 'node_id' => 'n', 'sequence' => 1, 'status' => 'completed', 'stream_id' => 's', 'ordinal_path' => '0001', 'created' => '2026-09-01 08:00:00']];
		$steps = (new FlowRunStepMapper($this->db))->findByRun(runUuid: 'r');

		$this->assertSame(['orderBy:ordinal_path', 'addOrderBy:sequence', 'addOrderBy:id'], $this->ordering());
		$this->assertSame('0001', $steps[0]->getOrdinalPath());
		$this->assertSame('s', $steps[0]->jsonSerialize()['streamId']);
	}//end testFindByRunIsCanonicalByOrdinalPathThenSequence()

	public function testTheTimestampReadIsExplicitAndSeparate(): void {
		(new FlowRunStepMapper($this->db))->findByRunByTimestamp(runUuid: 'r');
		$this->assertSame(['orderBy:created', 'addOrderBy:id'], $this->ordering());
	}//end testTheTimestampReadIsExplicitAndSeparate()

	public function testLockByUuidReadsForUpdate(): void {
		$this->rows = [['id' => 9, 'uuid' => 'r', 'flow_id' => 'f', 'status' => 'running', 'marking' => json_encode(['a' => 1]), 'firings' => 2, 'place_items' => json_encode(['a' => []])]];
		$run = (new FlowRunMapper($this->db))->lockByUuid(uuid: 'r');

		$this->assertContains('forUpdate', array_column($this->calls, 0));
		$this->assertSame(2, (int)$run->getFirings());
		$this->assertSame(['a' => []], $run->getPlaceItems());
		$this->assertSame(2, $run->jsonSerialize()['firings']);
	}//end testLockByUuidReadsForUpdate()
}//end class
