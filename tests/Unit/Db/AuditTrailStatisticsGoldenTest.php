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
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
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
			$calls["getStatistics $tag"] = fn (AuditTrailMapper $m) => $m->getStatistics($reg, $sch);
			$calls["getStatistics exclude $tag"] = fn (AuditTrailMapper $m) => $m->getStatistics(
				$reg,
				$sch,
				[['register' => 1, 'schema' => 2], ['register' => 3], ['schema' => 4]]
			);
			$calls["getActionChartData $tag"] = fn (AuditTrailMapper $m) => $m->getActionChartData(null, null, $reg, $sch);
			$calls["getActionChartData range $tag"] = fn (AuditTrailMapper $m) => $m->getActionChartData($from, $till, $reg, $sch);
			$calls["getDetailedStatistics $tag"] = fn (AuditTrailMapper $m) => $m->getDetailedStatistics($reg, $sch, 12);
			$calls["getActionCounts $tag"] = fn (AuditTrailMapper $m) => $m->getActionCounts($reg, $sch);
			$calls["getActionDistribution $tag"] = fn (AuditTrailMapper $m) => $m->getActionDistribution($reg, $sch, 48);
			$calls["getMostActiveObjects $tag"] = fn (AuditTrailMapper $m) => $m->getMostActiveObjects($reg, $sch, 3, 6);
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

	private function mapper(): AuditTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new AuditTrailMapper(
			$db,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IRequest::class),
			new NullLogger()
		);
	}//end mapper()
}//end class
