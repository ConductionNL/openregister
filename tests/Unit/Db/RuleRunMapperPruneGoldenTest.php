<?php

/**
 * Characterisation of RuleRunMapper::pruneBefore(): the batched id reads and deletes of the
 * runs created before a moment, when it stops, and the count it answers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Golden prune of rule runs.
 *
 * @covers \OCA\OpenRegister\Db\RuleRunMapper
 */
final class RuleRunMapperPruneGoldenTest extends TestCase {
	use RecordsQueries;

	private const SELECT = 'select(id) from(openregister_rule_runs) where(lt(created, :p%d)) orderBy(created, ASC) setMaxResults(2000) executeQuery()';

	private const DELETE = 'delete(openregister_rule_runs) where(in(id, :p%d)) executeStatement()';

	/**
	 * Rows of ids.
	 *
	 * @param int $from  The first id.
	 * @param int $count How many.
	 *
	 * @return list<array{id: string}>
	 */
	private static function rows(int $from, int $count): array {
		return array_map(static fn (int $id): array => ['id' => (string) $id], range($from, $from + $count - 1));
	}//end rows()

	/**
	 * Nothing to prune is one read and no delete.
	 *
	 * @return void
	 */
	public function testNothingToPrune(): void {
		$before = new DateTime('2026-01-01T00:00:00+00:00');

		$this->assertSame(0, (new RuleRunMapper($this->recordingDb()))->pruneBefore($before));
		$this->assertSame([sprintf(self::SELECT, 0)], [$this->sql(0)]);
		$this->assertCount(1, $this->queries);
		$this->assertSame([$before, IQueryBuilder::PARAM_DATETIME_MUTABLE], $this->params[':p0']);
	}//end testNothingToPrune()

	/**
	 * A short batch is deleted and ends the prune.
	 *
	 * @return void
	 */
	public function testShortBatchEndsThePrune(): void {
		$this->results[] = self::rows(from: 5, count: 3);
		$this->outcomes = [3];

		$this->assertSame(3, (new RuleRunMapper($this->recordingDb()))->pruneBefore(new DateTime('2026-01-01')));
		$this->assertSame([sprintf(self::SELECT, 0), sprintf(self::DELETE, 1)], [$this->sql(0), $this->sql(1)]);
		$this->assertCount(2, $this->queries);
		$this->assertSame([[5, 6, 7], IQueryBuilder::PARAM_INT_ARRAY], $this->params[':p1']);
	}//end testShortBatchEndsThePrune()

	/**
	 * Full batches go on until a short or empty one, and the counts add up.
	 *
	 * @return void
	 */
	public function testFullBatchesGoOn(): void {
		$this->results[] = self::rows(from: 1, count: 2000);
		$this->results[] = self::rows(from: 2001, count: 2000);
		$this->results[] = [];
		$this->outcomes = [2000, 1999];

		$this->assertSame(3999, (new RuleRunMapper($this->recordingDb()))->pruneBefore(new DateTime('2026-01-01')));
		$this->assertSame(
			[sprintf(self::SELECT, 0), sprintf(self::DELETE, 1), sprintf(self::SELECT, 2), sprintf(self::DELETE, 3), sprintf(self::SELECT, 4)],
			array_map(fn (int $index): string => $this->sql($index), range(0, 4))
		);
		$this->assertCount(5, $this->queries);
	}//end testFullBatchesGoOn()

	/**
	 * The pass limit stops a prune of full batches; zero or fewer still runs one pass.
	 *
	 * @return void
	 */
	public function testPassLimit(): void {
		$this->results[] = self::rows(from: 1, count: 2000);
		$this->outcomes = [2000];
		$this->assertSame(2000, (new RuleRunMapper($this->recordingDb()))->pruneBefore(new DateTime('2026-01-01'), 1));
		$this->assertCount(2, $this->queries);

		$this->queries = [];
		$this->results[] = self::rows(from: 1, count: 2000);
		$this->outcomes = [7];
		$this->assertSame(7, (new RuleRunMapper($this->recordingDb()))->pruneBefore(new DateTime('2026-01-01'), 0));
		$this->assertCount(2, $this->queries);
	}//end testPassLimit()
}//end class
