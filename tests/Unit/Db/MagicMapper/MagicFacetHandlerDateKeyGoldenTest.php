<?php

/**
 * Golden test for MagicFacetHandler::buildDateKeyExpr() and getDateFormatForInterval().
 *
 * Records the date_histogram bucket key expression for every interval, known
 * and unknown, on PostgreSQL, MariaDB, MySQL and SQLite platforms, and the
 * PostgreSQL format of each interval. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 */
class MagicFacetHandlerDateKeyGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/facet-date-key-golden.json';

	private const INTERVALS = ['day', 'week', 'month', 'quarter', 'year', 'hour', '', 'Month', 'QUARTER'];

	public function testDateKeysMatchGolden(): void {
		$platforms = [
			'postgres' => PostgreSQLPlatform::class,
			'mariadb' => MariaDBPlatform::class,
			'mysql' => MySQLPlatform::class,
			'sqlite' => SqlitePlatform::class,
		];
		$expr = new ReflectionMethod(MagicFacetHandler::class, 'buildDateKeyExpr');
		$expr->setAccessible(true);
		$format = new ReflectionMethod(MagicFacetHandler::class, 'getDateFormatForInterval');
		$format->setAccessible(true);

		$actual = [];
		foreach ($platforms as $name => $platformClass) {
			$db = $this->createMock(IDBConnection::class);
			$db->method('getDatabasePlatform')->willReturn($this->createMock($platformClass));
			$handler = new MagicFacetHandler($db, $this->createMock(LoggerInterface::class));
			foreach (self::INTERVALS as $interval) {
				$actual[$name][$interval] = [
					'plain' => $expr->invoke($handler, 'publicatiedatum', $interval),
					'aliased' => $expr->invoke($handler, 't.publicatiedatum', $interval),
				];
			}
		}

		foreach (self::INTERVALS as $interval) {
			$actual['format'][$interval] = $format->invoke($handler, $interval);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, (string) $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testDateKeysMatchGolden()
}//end class
