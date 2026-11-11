<?php

/**
 * Behaviour test for the audit-trail statistics readers and their scope.
 *
 * Asserts what each statistic returns for a given set of rows, which filters
 * the scope adds (register, schema, window, exclusions, date bounds, limit),
 * and the fallback each reader returns when its query fails.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\AuditTrail
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\AuditTrail;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrail\AuditActionStatistics;
use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailStatistics;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AuditStatisticsBehaviourTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function db(array $rows): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $p): string => addcslashes($p, '%_\\'));
		return $db;
	}

	private function brokenDb(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('db down'));
		return $db;
	}

	private function actions(IDBConnection $db, ?LoggerInterface $logger = null): AuditActionStatistics {
		return new AuditActionStatistics($db, 'openregister_audit_trails', $logger ?? $this->createMock(LoggerInterface::class));
	}

	private function trails(IDBConnection $db): AuditTrailStatistics {
		return new AuditTrailStatistics($db, 'openregister_audit_trails');
	}

	private function logHas(string $needle): bool {
		foreach ($this->queryLog as $line) {
			if (str_contains($line, $needle) === true) {
				return true;
			}
		}

		return false;
	}


	public function testScopeNarrowsOnlyOnTheIdsItHas(): void {
		$this->trails($this->db([[]]))->totals(new AuditStatisticsScope(registerId: 3));
		$this->assertContains('#1 param :p1 = 3, 2', $this->queryLog);
		$this->assertContains('#1 andWhere(eq(register, :p1, NULL))', $this->queryLog);
		$this->assertFalse($this->logHas('eq(schema'));
	}

	public function testScopeNarrowsOnRegisterAndSchema(): void {
		$this->trails($this->db([[]]))->totals(new AuditStatisticsScope(registerId: 3, schemaId: 4));
		$this->assertContains('#1 andWhere(eq(register, :p1, NULL))', $this->queryLog);
		$this->assertContains('#1 andWhere(eq(schema, :p2, NULL))', $this->queryLog);
	}

	public function testScopeWindowDefaultsToTheLast24Hours(): void {
		$scope = new AuditStatisticsScope();
		$scope->hours = null;
		$before = (new DateTime())->modify('-24 hours')->modify('-1 minute');
		$after = (new DateTime())->modify('-24 hours')->modify('+1 minute');
		$this->actions($this->db([[]]))->actionDistribution($scope);

		$since = null;
		foreach ($this->queryLog as $line) {
			if (preg_match('/param :p1 = (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d), 2/', $line, $m) === 1) {
				$since = new DateTime($m[1]);
			}
		}

		$this->assertNotNull($since);
		$this->assertGreaterThan($before, $since);
		$this->assertLessThan($after, $since);
		$this->assertTrue($this->logHas('expr.gte(created, :p1'));
	}

	public function testScopeWindowHonoursAnExplicitHourCount(): void {
		$scope = new AuditStatisticsScope();
		$scope->hours = 2;
		$floor = (new DateTime())->modify('-2 hours')->modify('-1 minute');
		$this->actions($this->db([[]]))->actionDistribution($scope);

		preg_match('/= (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d), 2/', implode("\n", $this->queryLog), $m);
		$this->assertGreaterThan($floor, new DateTime($m[1]));
		$this->assertLessThan(new DateTime('-1 hour'), new DateTime($m[1]));
	}


	public function testTotalsCastsTheCountAndSize(): void {
		$result = $this->trails($this->db([[['total' => '12', 'size' => '3400']]]))->totals(new AuditStatisticsScope());
		$this->assertSame(['total' => 12, 'size' => 3400], $result);
		$this->assertContains('#1 from(openregister_audit_trails, NULL)', $this->queryLog);
	}

	public function testTotalsTreatsAMissingRowAsZero(): void {
		$this->assertSame(['total' => 0, 'size' => 0], $this->trails($this->db([[]]))->totals(new AuditStatisticsScope()));
	}

	public function testTotalsFallsBackToZeroWhenTheQueryFails(): void {
		$this->assertSame(['total' => 0, 'size' => 0], $this->trails($this->brokenDb())->totals(new AuditStatisticsScope()));
	}

	public function testTotalsExcludesEachRegisterSchemaCombination(): void {
		$scope = new AuditStatisticsScope();
		$scope->exclude = [['register' => 1, 'schema' => 2], ['register' => null, 'schema' => null]];
		$this->trails($this->db([[]]))->totals($scope);

		$this->assertContains('#1 expr.isNull(register)', $this->queryLog);
		$this->assertContains('#1 param :p1 = 1, 1', $this->queryLog);
		$this->assertContains('#1 expr.neq(schema, :p2, NULL)', $this->queryLog);
		$andWheres = array_values(array_filter($this->queryLog, static fn (string $l): bool => str_starts_with($l, '#1 andWhere(')));
		$this->assertCount(1, $andWheres, 'an empty combination adds no condition');
	}

	public function testMostActiveObjectsNamesEachObjectAndAppliesTheLimit(): void {
		$scope = new AuditStatisticsScope(schemaId: 5);
		$scope->limit = 3;
		$result = $this->trails($this->db([[['object' => '7', 'count' => '9'], ['object' => '8', 'count' => '2']]]))->mostActiveObjects($scope);

		$this->assertSame(
			['objects' => [['id' => '7', 'name' => 'Object 7', 'count' => 9], ['id' => '8', 'name' => 'Object 8', 'count' => 2]]],
			$result
		);
		$this->assertContains('#1 setMaxResults(3)', $this->queryLog);
		$this->assertContains('#1 orderBy(count, DESC)', $this->queryLog);
		$this->assertContains('#1 andWhere(eq(schema, :p2, NULL))', $this->queryLog);
	}

	public function testMostActiveObjectsWithoutALimitIsUnbounded(): void {
		$scope = new AuditStatisticsScope();
		$scope->limit = null;
		$this->assertSame(['objects' => []], $this->trails($this->db([[]]))->mostActiveObjects($scope));
		$this->assertFalse($this->logHas('setMaxResults'));
	}

	public function testMostActiveObjectsIsEmptyWhenTheQueryFails(): void {
		$this->assertSame(['objects' => []], $this->trails($this->brokenDb())->mostActiveObjects(new AuditStatisticsScope()));
	}

	public function testTotalsPerSchemaSkipsTheQueryForNoSchemas(): void {
		$this->assertSame([], $this->trails($this->db([]))->totalsPerSchema([]));
		$this->assertSame([], $this->queryLog);
	}

	public function testTotalsPerSchemaFillsSchemasWithoutTrailsWithZero(): void {
		$result = $this->trails($this->db([[['schema' => '4', 'total' => '6', 'size' => '60']]]))->totalsPerSchema([4, 9]);

		$this->assertSame([4 => ['total' => 6, 'size' => 60], 9 => ['total' => 0, 'size' => 0]], $result);
		$this->assertContains('#1 param :p1 = ["4","9"], 102', $this->queryLog);
		$this->assertContains('#1 groupBy(schema)', $this->queryLog);
	}

	public function testTotalsPerSchemaIsAllZeroWhenTheQueryFails(): void {
		$this->assertSame(
			[1 => ['total' => 0, 'size' => 0], 2 => ['total' => 0, 'size' => 0]],
			$this->trails($this->brokenDb())->totalsPerSchema([1, 2])
		);
	}


	public function testActionChartBuildsSortedDaysWithZeroGaps(): void {
		$rows = [
			['date' => '2026-01-02', 'action' => 'update', 'count' => '4'],
			['date' => '2026-01-01', 'action' => 'create', 'count' => '3'],
			['date' => '2026-01-02', 'action' => 'create', 'count' => '1'],
		];
		$chart = $this->actions($this->db([$rows]))->actionChart(new AuditStatisticsScope());

		$this->assertSame(['2026-01-01', '2026-01-02'], $chart['labels']);
		$this->assertSame(
			[
				['name' => 'Create', 'data' => [3, 1]],
				['name' => 'Update', 'data' => [0, 4]],
				['name' => 'Delete', 'data' => [0, 0]],
				['name' => 'Read', 'data' => [0, 0]],
			],
			$chart['series']
		);
		$this->assertFalse($this->logHas('expr.gte'));
		$this->assertFalse($this->logHas('expr.lte'));
	}

	public function testActionChartBoundsTheDaysWhenFromAndTillAreSet(): void {
		$scope = new AuditStatisticsScope();
		$scope->from = new DateTime('2026-01-01 15:00:00', new DateTimeZone('UTC'));
		$scope->till = new DateTime('2026-01-31 01:00:00', new DateTimeZone('UTC'));
		$this->actions($this->db([[]]))->actionChart($scope);

		$this->assertContains('#1 param :p1 = 2026-01-01, 2', $this->queryLog);
		$this->assertContains('#1 andWhere(gte(created, :p1, NULL))', $this->queryLog);
		$this->assertContains('#1 param :p2 = 2026-01-31, 2', $this->queryLog);
		$this->assertContains('#1 andWhere(lte(created, :p2, NULL))', $this->queryLog);
	}

	public function testActionChartIsEmptyWhenTheQueryFails(): void {
		$this->assertSame(['labels' => [], 'series' => []], $this->actions($this->brokenDb())->actionChart(new AuditStatisticsScope()));
	}

	public function testWindowedActionsMapsKnownActionsAndIgnoresOthers(): void {
		$rows = [['action' => 'create', 'count' => '2'], ['action' => 'read', 'count' => '5'], ['action' => 'lock', 'count' => '9']];
		$this->assertSame(
			['total' => 40, 'creates' => 2, 'updates' => 0, 'deletes' => 0, 'reads' => 5],
			$this->actions($this->db([$rows]))->windowedActions(new AuditStatisticsScope(), 40)
		);
	}

	public function testWindowedActionsZeroesEverythingIncludingTheTotalOnFailure(): void {
		$this->assertSame(
			['total' => 0, 'creates' => 0, 'updates' => 0, 'deletes' => 0, 'reads' => 0],
			$this->actions($this->brokenDb())->windowedActions(new AuditStatisticsScope(), 40)
		);
	}

	public function testLifetimeActionsTotalsEveryActionButOnlyListsChartActions(): void {
		$rows = [['action' => 'delete', 'count' => '3'], ['action' => 'lock', 'count' => '7'], ['count' => '1']];
		$this->assertSame(
			['total' => 11, 'create' => 0, 'update' => 0, 'delete' => 3, 'read' => 0],
			$this->actions($this->db([$rows]))->lifetimeActions(new AuditStatisticsScope(registerId: 2))
		);
		$this->assertContains('#1 andWhere(eq(register, :p1, NULL))', $this->queryLog);
	}

	public function testLifetimeActionsLogsAndZeroesOnFailure(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Failed to count audit trails by action: db down',
			$this->callback(static fn (array $c): bool => $c['registerId'] === 6 && $c['schemaId'] === 7)
		);

		$this->assertSame(
			['total' => 0, 'create' => 0, 'update' => 0, 'delete' => 0, 'read' => 0],
			$this->actions($this->brokenDb(), $logger)->lifetimeActions(new AuditStatisticsScope(registerId: 6, schemaId: 7))
		);
	}

	public function testCountByActionPrefixEscapesThePrefixAndKeysByAction(): void {
		$rows = [['action' => 'gdpr_erase', 'count' => '2'], ['action' => 'gdpr_export', 'count' => '1']];
		$this->assertSame(
			['gdpr_erase' => 2, 'gdpr_export' => 1],
			$this->actions($this->db([$rows]))->countByActionPrefix('gdpr_')
		);
		$this->assertContains('#1 param :p1 = gdpr\_%, 2', $this->queryLog);
		$this->assertContains('#1 expr.like(action, :p1, NULL)', $this->queryLog);
	}

	public function testActionDistributionGivesEachActionItsShare(): void {
		$rows = [['action' => 'create', 'count' => '1'], ['action' => 'update', 'count' => '2']];
		$this->assertSame(
			['actions' => [
				['name' => 'create', 'count' => 1, 'percentage' => 33.33],
				['name' => 'update', 'count' => 2, 'percentage' => 66.67],
			]],
			$this->actions($this->db([$rows]))->actionDistribution(new AuditStatisticsScope())
		);
	}

	public function testActionDistributionGivesZeroShareWhenAllCountsAreZero(): void {
		$rows = [['action' => 'create', 'count' => '0']];
		$this->assertSame(
			['actions' => [['name' => 'create', 'count' => 0, 'percentage' => 0]]],
			$this->actions($this->db([$rows]))->actionDistribution(new AuditStatisticsScope())
		);
	}

	public function testActionDistributionIsEmptyWhenTheQueryFails(): void {
		$this->assertSame(['actions' => []], $this->actions($this->brokenDb())->actionDistribution(new AuditStatisticsScope()));
	}
}
