<?php

/**
 * Characterisation of the FlowRunMapper reads and writes that match the active statuses or
 * the runs untouched since a moment: their exact queries, bound values and results.
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
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunSubjectScope;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Golden active-status and staleness queries of FlowRunMapper.
 *
 * @covers \OCA\OpenRegister\Db\FlowRunMapper
 */
final class FlowRunMapperActiveStatusGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var FlowRunMapper
	 */
	private FlowRunMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new FlowRunMapper($this->recordingDb());
	}//end setUp()

	/**
	 * findActive() lists active runs newest first, narrowed to an organisation and a subject.
	 *
	 * @return void
	 */
	public function testFindActive(): void {
		$this->results[] = [['id' => 9], ['id' => 4]];

		$runs = $this->mapper->findActive(new FlowRunSubjectScope('org-1', 'obj-1'), 10);

		$this->assertSame([9, 4], array_map(static fn (FlowRun $run): int => $run->getId(), $runs));
		$this->assertStringStartsWith(
			'select(*) from(openregister_flow_runs) where(in(status, :p0)) orderBy(id, DESC) setMaxResults(10) andWhere(',
			$this->sql()
		);
		$this->assertSame([FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY], $this->params[':p0']);
	}//end testFindActive()

	/**
	 * findActive() without a scope matches only the active statuses.
	 *
	 * @return void
	 */
	public function testFindActiveUnscoped(): void {
		$this->mapper->findActive(new FlowRunSubjectScope());

		$this->assertSame(
			'select(*) from(openregister_flow_runs) where(in(status, :p0)) orderBy(id, DESC) setMaxResults(25) executeQuery()',
			$this->sql()
		);
	}//end testFindActiveUnscoped()

	/**
	 * hasActiveRun() reads one id of an active run of the flow; an empty flow id is false without a query.
	 *
	 * @return void
	 */
	public function testHasActiveRun(): void {
		$this->assertFalse($this->mapper->hasActiveRun('  '));
		$this->assertSame([], $this->queries);

		$this->results[] = [['id' => 3]];
		$this->assertTrue($this->mapper->hasActiveRun('flow-1'));
		$this->assertFalse($this->mapper->hasActiveRun('flow-2'));

		$this->assertSame(
			'select(id) from(openregister_flow_runs) where(eq(flow_id, :p0)) andWhere(in(status, :p1)) setMaxResults(1) executeQuery()',
			$this->sql(0)
		);
		$this->assertSame(['flow-1', IQueryBuilder::PARAM_STR], $this->params[':p0']);
		$this->assertSame([FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY], $this->params[':p1']);
	}//end testHasActiveRun()

	/**
	 * countActive() counts the active runs, narrowed when asked.
	 *
	 * @return void
	 */
	public function testCountActive(): void {
		$this->mapper->countActive();
		$this->mapper->countActive('org-1', 'obj-1');

		$this->assertStringStartsWith('select(COUNT(*) AS `total`) from(openregister_flow_runs) where(in(status, :p0)) executeQuery()', $this->sql(0));
		$this->assertStringStartsWith('select(COUNT(*) AS `total`) from(openregister_flow_runs) where(in(status, :p1)) andWhere(', $this->sql(1));
		$this->assertSame([FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY], $this->params[':p1']);
	}//end testCountActive()

	/**
	 * pinUnversionedActive() pins only the flow's unversioned active runs.
	 *
	 * @return void
	 */
	public function testPinUnversionedActive(): void {
		$this->assertSame(1, $this->mapper->pinUnversionedActive('flow-1', 4));

		$this->assertSame(
			'update(openregister_flow_runs) set(flow_version, :p0) where(eq(flow_id, :p1)) andWhere(isNull(flow_version)) andWhere(in(status, :p2)) executeStatement()',
			$this->sql()
		);
		$this->assertSame([[4, IQueryBuilder::PARAM_INT], ['flow-1', IQueryBuilder::PARAM_STR], [FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY]], array_values($this->params));
	}//end testPinUnversionedActive()

	/**
	 * findStale() and findAbandonedSignals() read runs untouched since a moment, oldest first.
	 *
	 * @return void
	 */
	public function testUntouchedSince(): void {
		$before = new DateTime('2026-05-06T07:08:09+00:00');

		$this->mapper->findStale($before, 5);
		$this->mapper->findAbandonedSignals($before);

		$this->assertSame(
			'select(*) from(openregister_flow_runs) where(eq(status, :p0)) andWhere(lt(updated, :p1)) orderBy(updated, ASC) setMaxResults(5) executeQuery()',
			$this->sql(0)
		);
		$this->assertSame(
			'select(*) from(openregister_flow_runs) where(eq(status, :p2)) andWhere(isNull(resume_at)) andWhere(lt(updated, :p3)) orderBy(updated, ASC) setMaxResults(25) executeQuery()',
			$this->sql(1)
		);
		$this->assertSame(
			[
				[FlowRun::STATUS_RUNNING, IQueryBuilder::PARAM_STR],
				[$before, IQueryBuilder::PARAM_DATETIME_MUTABLE],
				[FlowRun::STATUS_SUSPENDED, IQueryBuilder::PARAM_STR],
				[$before, IQueryBuilder::PARAM_DATETIME_MUTABLE],
			],
			array_values($this->params)
		);
	}//end testUntouchedSince()

	/**
	 * pruneBefore() deletes only terminal runs untouched since the moment.
	 *
	 * @return void
	 */
	public function testPruneBefore(): void {
		$before = new DateTime('2026-01-02T03:04:05+00:00');

		$this->assertSame(1, $this->mapper->pruneBefore($before));
		$this->assertSame(
			'delete(openregister_flow_runs) where(in(status, :p0)) andWhere(lt(updated, :p1)) executeStatement()',
			$this->sql()
		);
		$this->assertSame(
			[[FlowRun::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY], [$before, IQueryBuilder::PARAM_DATETIME_MUTABLE]],
			array_values($this->params)
		);
	}//end testPruneBefore()
}//end class
