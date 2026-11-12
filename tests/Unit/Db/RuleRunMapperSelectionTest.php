<?php

/**
 * RuleRunMapper's selection-based reads issue the same queries as the per-argument reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\RuleRunFilter;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunSelection;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\RuleRunMapper
 */
final class RuleRunMapperSelectionTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * Run one read on a fresh recording mapper and return its result and queries.
	 *
	 * @param callable $call The read.
	 * @param array    $rows The rows the query returns.
	 *
	 * @return array
	 */
	private function record(callable $call, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [$rows];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$result = $call(new RuleRunMapper($db));

		return [is_array($result) === true ? count($result) : $result, $this->queryLog];
	}//end record()

	/**
	 * findRuns matches findByRule for a filtered, paged read, the clamps included.
	 *
	 * @return void
	 */
	public function testFindRunsMatchesFindByRule(): void {
		$since = new DateTime('2026-03-01 00:00:00');
		$until = new DateTime('2026-03-31 23:59:59');
		$rows = [['id' => 4], ['id' => 9]];
		foreach ([[5, 2], [900, -3]] as [$limit, $offset]) {
			$old = $this->record(fn (RuleRunMapper $m) => $m->findByRule('r', 'pass', $since, $until, $limit, $offset), $rows);
			$selection = new RuleRunSelection('r', new RuleRunFilter('pass', $since, $until));
			$new = $this->record(fn (RuleRunMapper $m) => $m->findRuns($selection, new ListQuery($limit, $offset)), $rows);
			$this->assertSame($old, $new);
		}
	}//end testFindRunsMatchesFindByRule()

	/**
	 * countRuns matches countByRule, with and without a filter.
	 *
	 * @return void
	 */
	public function testCountRunsMatchesCountByRule(): void {
		$rows = [['run_count' => '7']];
		$old = $this->record(fn (RuleRunMapper $m) => $m->countByRule('r', 'fail'), $rows);
		$new = $this->record(fn (RuleRunMapper $m) => $m->countRuns(new RuleRunSelection('r', new RuleRunFilter('fail'))), $rows);
		$this->assertSame($old, $new);
		$this->assertSame(7, $new[0]);

		$bare = $this->record(fn (RuleRunMapper $m) => $m->countRuns(new RuleRunSelection('r')), $rows);
		$this->assertSame($this->record(fn (RuleRunMapper $m) => $m->countByRule('r'), $rows), $bare);
	}//end testCountRunsMatchesCountByRule()
}//end class
