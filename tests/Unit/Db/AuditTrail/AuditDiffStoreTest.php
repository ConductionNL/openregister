<?php

/**
 * The audit diff store reads one record's diffs and rewrites one row's.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\AuditTrail
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\AuditTrail;

use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;

use OCA\OpenRegister\Db\AuditTrail\AuditDiffStore;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the read and the rewrite.
 */
#[CoversClass(AuditDiffStore::class)]
final class AuditDiffStoreTest extends TestCase {
	use RecordingQueryBuilderTrait;

	public function testTheReadNarrowsToTheRecordAndSkipsTombstones(): void {
		$result = $this->recorded(
			fn (AuditDiffStore $s): array => $s->diffsOfObject('uuid-1'),
			[[
				['id' => '3', 'changed' => '{"name":{"old":null,"new":"Jan"}}'],
				['id' => '9', 'changed' => ['status' => ['old' => 'a', 'new' => 'b']]],
				['id' => '11', 'changed' => 'not json'],
				['id' => '12', 'changed' => null],
			]]
		);

		$this->assertSame(
			[
				3 => ['name' => ['old' => null, 'new' => 'Jan']],
				9 => ['status' => ['old' => 'a', 'new' => 'b']],
				11 => [],
				12 => [],
			],
			$result['result'],
			'decoded by row id; an unreadable or empty diff reads as empty'
		);
		$this->assertSame(
			[
				'#1 select(id, changed)',
				'#1 from(openregister_audit_trails, NULL)',
				'#1 param :p1 = uuid-1, 2',
				'#1 expr.eq(object_uuid, :p1, NULL)',
				'#1 where(eq(object_uuid, :p1, NULL))',
				'#1 expr.isNull(purged_at)',
				'#1 andWhere(isNull(purged_at))',
				'#1 orderBy(id, ASC)',
				'#1 executeQuery()',
			],
			$result['queries']
		);
	}//end testTheReadNarrowsToTheRecordAndSkipsTombstones()

	public function testTheRewriteStoresJsonOnOneRow(): void {
		$result = $this->recorded(fn (AuditDiffStore $s) => $s->rewriteDiff(7, ['name' => ['old' => '[anonymised]', 'new' => 'é/x']]), []);

		$this->assertSame(
			[
				'#1 update(openregister_audit_trails, NULL)',
				'#1 param :p1 = {"name":{"old":"[anonymised]","new":"é/x"}}, 2',
				'#1 set(changed, :p1)',
				'#1 param :p2 = 7, 1',
				'#1 expr.eq(id, :p2, NULL)',
				'#1 where(eq(id, :p2, NULL))',
				'#1 executeStatement()',
			],
			$result['queries']
		);
	}//end testTheRewriteStoresJsonOnOneRow()

	/**
	 * Run a store call against a recording builder.
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

		return ['result' => $call(new AuditDiffStore($db)), 'queries' => $this->queryLog];
	}//end recorded()
}//end class
