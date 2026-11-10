<?php

/**
 * Golden test for the statistics queries of AuditTrailMapper.
 *
 * Records the query and the result of getStatistics(), getActionChartData(),
 * getDetailedStatistics(), getActionCounts(), getActionDistribution() and
 * getMostActiveObjects() with and without a register and schema filter.
 * Timestamps relative to now are recorded as an offset in hours. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Tests\Support\AuditStatisticsCalls;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditTrailStatistics
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditActionStatistics
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope
 */
class AuditTrailStatisticsGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/audit-trail-statistics-golden.json';

	private const ROWS = [
		['action' => 'create', 'count' => '3', 'total' => '7', 'size' => '120', 'date' => '2026-01-02', 'object' => 'obj-1'],
		['action' => 'read', 'count' => '2', 'total' => '7', 'size' => '120', 'date' => '2026-01-02', 'object' => 'obj-2'],
		['action' => 'update', 'count' => '1', 'total' => '7', 'size' => '120', 'date' => '2026-01-03', 'object' => 'obj-3'],
		['action' => 'other', 'count' => '1', 'total' => '7', 'size' => '120', 'date' => '2026-01-03', 'object' => 'obj-4'],
	];

	private static function calls(): array {
		$from = new DateTime('2026-01-01 10:00:00');
		$till = new DateTime('2026-01-05 10:00:00');
		$calls = [];
		foreach ([[null, null], [5, null], [null, 9], [5, 9]] as [$reg, $sch]) {
			$tag = 'reg ' . var_export($reg, true) . ' sch ' . var_export($sch, true);
			$calls["getStatistics $tag"] = fn (AuditTrailMapper $m) => $m->statisticsTotals(AuditStatisticsCalls::scope($reg, $sch));
			$calls["getStatistics exclude $tag"] = fn (AuditTrailMapper $m) => $m->statisticsTotals(
				AuditStatisticsCalls::scope($reg, $sch, [['register' => 1, 'schema' => 2], ['register' => 3], ['schema' => 4]])
			);
			$calls["getActionChartData $tag"] = fn (AuditTrailMapper $m) => $m->getActionChartData(null, null, $reg, $sch);
			$calls["getActionChartData range $tag"] = fn (AuditTrailMapper $m) => $m->getActionChartData($from, $till, $reg, $sch);
			$calls["getDetailedStatistics $tag"] = static function (AuditTrailMapper $m) use ($reg, $sch): array {
				$s = new AuditStatisticsScope($reg, $sch);
				$s->hours = 12;
				return $m->detailedStatistics($s);
			};
			$calls["getActionCounts $tag"] = fn (AuditTrailMapper $m) => $m->getActionCounts($reg, $sch);
			$calls["getActionDistribution $tag"] = static function (AuditTrailMapper $m) use ($reg, $sch): array {
				$s = new AuditStatisticsScope($reg, $sch);
				$s->hours = 48;
				return $m->actionDistribution($s);
			};
			$calls["getMostActiveObjects $tag"] = static function (AuditTrailMapper $m) use ($reg, $sch): array {
				$s = new AuditStatisticsScope($reg, $sch);
				$s->limit = 3;
				$s->hours = 6;
				return $m->mostActiveObjects($s);
			};
		}

		return $calls;
	}//end calls()

	public function testStatisticsMatchGolden(): void {
		$actual = [];
		foreach (self::calls() as $name => $call) {
			foreach (['rows' => self::ROWS, 'empty' => []] as $rowName => $rows) {
				$this->queryLog = [];
				$this->builderCount = 0;
				$this->parameterCount = 0;
				$this->queuedRows = [$rows, $rows, $rows];
				$result = $call($this->mapper());
				$queries = preg_replace_callback(
					'/\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/',
					static fn (array $m): string => '<now -' . round((time() - strtotime($m[0])) / 3600) . 'h>',
					$this->queryLog
				);
				$actual["$name $rowName"] = ['result' => $result, 'queries' => $queries];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testStatisticsMatchGolden()

	/**
	 * The grouped read, a read without a limit, and every read on a failing database.
	 */
	public function testGroupedAndFailingReadsMatchGolden(): void {
		$rows = [['schema' => '5', 'total' => '2', 'size' => '7'], ['schema' => '6', 'total' => null, 'size' => null]];
		$actual = [];
		foreach (['rows' => $rows, 'empty' => []] as $rowName => $served) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = [$served];
			$result = $this->mapper()->getStatisticsGroupedBySchema([5, 9]);
			$actual["grouped $rowName"] = ['result' => $result, 'queries' => $this->queryLog];
		}

		$actual['grouped no ids'] = $this->mapper()->getStatisticsGroupedBySchema([]);

		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [[]];
		$unlimited = new AuditStatisticsScope();
		$unlimited->limit = null;
		$unlimited->hours = 6;
		$this->mapper()->mostActiveObjects($unlimited);
		$actual['most active without limit'] = preg_grep('/setMaxResults/', $this->queryLog);

		$failing = [
			'getStatistics' => fn (AuditTrailMapper $m) => $m->statisticsTotals(AuditStatisticsCalls::scope()),
			'getActionChartData' => fn (AuditTrailMapper $m) => $m->getActionChartData(),
			'getDetailedStatistics' => fn (AuditTrailMapper $m) => $m->detailedStatistics(new AuditStatisticsScope()),
			'getActionCounts' => fn (AuditTrailMapper $m) => $m->getActionCounts(5, 9),
			'getActionDistribution' => fn (AuditTrailMapper $m) => $m->actionDistribution(new AuditStatisticsScope()),
			'getMostActiveObjects' => fn (AuditTrailMapper $m) => $m->mostActiveObjects(new AuditStatisticsScope()),
			'getStatisticsGroupedBySchema' => fn (AuditTrailMapper $m) => $m->getStatisticsGroupedBySchema([5, 9]),
		];
		foreach ($failing as $name => $call) {
			$logged = [];
			$actual["failing $name"] = ['result' => $call($this->failingMapper($logged)), 'logged' => $logged];
		}

		$fixture = __DIR__ . '/fixtures/audit-trail-statistics-failures-golden.json';
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string) file_get_contents($fixture), true), json_decode((string) json_encode($actual), true));
	}//end testGroupedAndFailingReadsMatchGolden()

	/**
	 * Dates arrive in any order, a list of no ids asks nothing, an empty
	 * exclusion adds no condition, and a failing second read loses the total too.
	 */
	public function testEdgesOfTheReads(): void {
		$this->queuedRows = [[
			['date' => '2026-01-03', 'action' => 'read', 'count' => '1'],
			['date' => '2026-01-01', 'action' => 'create', 'count' => '2'],
		]];
		$this->assertSame(['2026-01-01', '2026-01-03'], $this->mapper()->getActionChartData()['labels']);

		$this->queryLog = [];
		$this->mapper()->getStatisticsGroupedBySchema([]);
		$this->assertSame([], $this->queryLog);

		$this->queuedRows = [[]];
		$this->mapper()->statisticsTotals(AuditStatisticsCalls::scope(null, null, [[]]));
		$this->assertSame([], preg_grep('/andWhere/', $this->queryLog));

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$calls) {
				$calls++;
				if ($calls > 1) {
					throw new \Exception('database down');
				}

				return $this->recordingBuilder();
			}
		);
		$this->queuedRows = [[['total' => '7', 'size' => '1']]];
		$mapper = new AuditTrailMapper(
			$db,
			new AuditTrailMapperServices($this->createMock(ContainerInterface::class), new NullLogger(), $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);
		$this->assertSame(
			['total' => 0, 'creates' => 0, 'updates' => 0, 'deletes' => 0, 'reads' => 0],
			$mapper->detailedStatistics(new AuditStatisticsScope())
		);
	}//end testEdgesOfTheReads()

	/**
	 * A mapper whose database refuses every query, logging into $logged.
	 *
	 * @param array<int, array<string, mixed>> $logged The log lines.
	 */
	private function failingMapper(array &$logged): AuditTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \Exception('database down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				unset($context['exception']);
				$logged[] = ['message' => $message, 'context' => $context];
			}
		);

		return new AuditTrailMapper(
			$db,
			new AuditTrailMapperServices($this->createMock(ContainerInterface::class), $logger, $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);
	}//end failingMapper()

	private function mapper(): AuditTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new AuditTrailMapper(
			$db,
			new AuditTrailMapperServices($this->createMock(ContainerInterface::class), new NullLogger(), $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);
	}//end mapper()
}//end class
