<?php

/**
 * The state-history queries an anonymisation runs: count and drop the
 * intervals of a set of properties of one object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\StateHistoryMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two queries over one object's properties.
 */
#[CoversClass(StateHistoryMapper::class)]
final class StateHistoryMapperPropertiesTest extends TestCase {
	use RecordingQueryBuilderTrait;

	public function testTheDeleteNarrowsToTheObjectAndTheProperties(): void {
		$result = $this->recorded(fn (StateHistoryMapper $m): int => $m->deleteForObjectProperties('uuid-1', ['name' => 'name', 'status' => 'status']), []);

		$this->assertSame(1, $result['result']);
		$this->assertSame(
			[
				'#1 delete(openregister_state_history, NULL)',
				'#1 param :p1 = uuid-1, 2',
				'#1 expr.eq(object_uuid, :p1, NULL)',
				'#1 where(eq(object_uuid, :p1, NULL))',
				'#1 param :p2 = ["name","status"], 102',
				'#1 expr.in(property, :p2, NULL)',
				'#1 andWhere(in(property, :p2, NULL))',
				'#1 executeStatement()',
			],
			$result['queries'],
			'one object, the named properties, open and closed intervals alike'
		);
	}//end testTheDeleteNarrowsToTheObjectAndTheProperties()

	public function testTheCountReadsTheSameRows(): void {
		$result = $this->recorded(fn (StateHistoryMapper $m): int => $m->countForObjectProperties('uuid-1', ['name']), [[['intervals' => 3]]]);

		$this->assertSame(3, $result['result']);
		$this->assertSame(
			[
				'#1 func.count(*, intervals)',
				'#1 select(count(*, intervals))',
				'#1 from(openregister_state_history, NULL)',
				'#1 param :p1 = uuid-1, 2',
				'#1 expr.eq(object_uuid, :p1, NULL)',
				'#1 where(eq(object_uuid, :p1, NULL))',
				'#1 param :p2 = ["name"], 102',
				'#1 expr.in(property, :p2, NULL)',
				'#1 andWhere(in(property, :p2, NULL))',
				'#1 executeQuery()',
			],
			$result['queries']
		);
	}//end testTheCountReadsTheSameRows()

	public function testNoPropertiesTouchesNothing(): void {
		$none = ['result' => 0, 'queries' => []];
		$this->assertSame($none, $this->recorded(fn (StateHistoryMapper $m): int => $m->deleteForObjectProperties('uuid-1', []), []));
		$this->assertSame($none, $this->recorded(fn (StateHistoryMapper $m): int => $m->countForObjectProperties('uuid-1', []), []));
	}//end testNoPropertiesTouchesNothing()

	/**
	 * Run a mapper call against a recording builder.
	 *
	 * @param callable $call The call.
	 * @param array    $rows Rows served to executeQuery, in order.
	 *
	 * @return array{result: mixed, queries: list<string>} The result and the queries.
	 */
	private function recorded(callable $call, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		return ['result' => $call(new StateHistoryMapper($db)), 'queries' => $this->queryLog];
	}//end recorded()
}//end class
