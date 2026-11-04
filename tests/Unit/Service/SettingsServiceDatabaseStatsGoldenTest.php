<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Records the queries, log lines and result of the private getDatabaseStats(), per scenario,
 * against fixtures/settings-database-stats-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 * SQL is compared with whitespace collapsed and comments removed.
 *
 * @covers \OCA\OpenRegister\Service\SettingsService
 */
class SettingsServiceDatabaseStatsGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Builds the service over a scripted database for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return SettingsService
	 */
	private function service(array $s): SettingsService {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getTableName')->willReturnCallback(fn (string $t) => 'oc_' . $t);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('getDatabasePlatform')->willReturnCallback(function () use ($s) {
			if ($s['platform'] === 'broken') {
				throw new Exception('no platform');
			}
			return $s['platform'] === 'pgsql' ? new PostgreSQLPlatform() : new MySQLPlatform();
		});
		$db->method('executeQuery')->willReturnCallback(function (string $sql) use ($s) {
			$sql = trim(preg_replace('/\s+/', ' ', preg_replace('/--[^\n]*/', '', $sql)));
			$this->calls[] = ['sql', $sql];
			$result = $this->createMock(IResult::class);
			if (str_contains($sql, 'information_schema') === true || str_contains($sql, 'pg_tables') === true) {
				$result->method('fetchAll')->willReturn($s['tables']);
				return $result;
			}
			foreach ($s['tableRows'] as $table => $row) {
				if (str_contains($sql, 'FROM ' . $table . ' ') === true) {
					if ($row === null) {
						throw new Exception('table broken');
					}
					$result->method('fetch')->willReturn($row);
					return $result;
				}
			}
			if (str_starts_with($sql, 'SELECT 1 FROM oc_openconnector_sources') === true) {
				if ($s['sources'] === false) {
					throw new Exception('no such table');
				}
				return $result;
			}
			$result->method('fetch')->willReturn($s['row']);
			return $result;
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				unset($context['file'], $context['line']);
				$this->calls[] = ['log.' . $level, $message, $context];
			});
		}

		$args = [];
		$given = ['db' => $db, 'logger' => $logger];
		foreach ((new ReflectionMethod(SettingsService::class, '__construct'))->getParameters() as $param) {
			$name = $param->getName();
			if (isset($given[$name]) === true) {
				$args[$name] = $given[$name];
			} elseif ($param->getType() !== null && $param->getType()->isBuiltin() === false) {
				$args[$name] = $this->createMock($param->getType()->getName());
			}
		}
		return new SettingsService(...$args);
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$row = [
			'total_audit_trails' => '11', 'total_search_trails' => '12', 'total_configurations' => '3',
			'total_organisations' => '4', 'total_registers' => '5', 'total_schemas' => '6', 'total_sources' => '7',
			'total_webhook_logs' => '8', 'audit_trails_without_expiry' => '1', 'search_trails_without_expiry' => '2',
			'expired_audit_trails' => '9', 'expired_search_trails' => '10',
		];
		$base = [
			'platform' => 'mysql',
			'tables' => ['oc_openregister_table_a', 'oc_openregister_table_b', 'oc_openregister_table_c'],
			'tableRows' => [
				'oc_openregister_table_a' => ['cnt' => '3', 'total_size' => '300'],
				'oc_openregister_table_b' => null,
				'oc_openregister_table_c' => ['cnt' => 2, 'total_size' => '20'],
			],
			'sources' => true,
			'row' => $row,
		];
		return [
			'mysql with tables' => [$base],
			'postgres no tables' => [['platform' => 'pgsql', 'tables' => []] + $base],
			'platform broken' => [['platform' => 'broken'] + $base],
			'no sources table' => [['sources' => false] + $base],
			'partial row' => [['row' => ['total_schemas' => '2']] + $base],
			'no row' => [['row' => false] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testGetDatabaseStatsGolden(array $s): void {
		$service = $this->service($s);
		$method = new ReflectionMethod(SettingsService::class, 'getDatabaseStats');
		try {
			$this->calls[] = ['result', $method->invoke($service)];
		} catch (Exception $e) {
			$this->calls[] = ['thrown', get_class($e), $e->getMessage()];
		}

		$file = __DIR__ . '/fixtures/settings-database-stats-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
