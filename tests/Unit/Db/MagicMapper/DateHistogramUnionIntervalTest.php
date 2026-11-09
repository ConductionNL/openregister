<?php

/**
 * DateHistogramUnionIntervalTest: the interval a union date histogram carries decides how dates are bucketed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\DateHistogramUnion
 * @uses   \OCA\OpenRegister\Db\MagicMapper\FacetResults
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TermsFacetUnion
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitRevealMasks
 */
class DateHistogramUnionIntervalTest extends TestCase {

	/**
	 * SQL prepared, whitespace collapsed.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	/**
	 * A union facet run for a date histogram over one table with a `datum` column.
	 *
	 * @param array<string, mixed> $facet The facet configuration.
	 *
	 * @return array<string, mixed> The facets.
	 */
	private function histogram(array $facet): array {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$db->method('prepare')->willReturnCallback(
			function (string $sql): IPreparedStatement {
				$this->statements[] = trim((string) preg_replace('/\s+/', ' ', $sql));
				$rows = [['date_key' => '2026', 'doc_count' => '3']];
				if (str_contains($sql, 'information_schema') === true) {
					$rows = [['col' => 'datum']];
				}

				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturn($this->createMock(IResult::class));
				$statement->method('fetch')->willReturnCallback(
					static function () use (&$rows) {
						return (array_shift($rows) ?? false);
					}
				);
				return $statement;
			}
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');
		$handler = new MagicFacetHandler(
			db: $db,
			logger: $this->createMock(LoggerInterface::class),
			cacheHandler: $this->createMock(CacheHandler::class),
			searchHandler: null,
			config: $config
		);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties(['datum' => ['type' => 'string', 'format' => 'date']]);
		$register = new Register();
		$register->setId(7);

		return $handler->getSimpleFacetsUnion(
			[['tableName' => 'openregister_table_7_2', 'schema' => $schema, 'register' => $register]],
			['_facets' => ['datum' => $facet]]
		);
	}//end histogram()

	/**
	 * The date-histogram statement.
	 *
	 * @return string
	 */
	private function histogramSql(): string {
		$matches = array_values(array_filter($this->statements, static fn (string $sql): bool => str_contains($sql, 'date_key')));
		$this->assertCount(1, $matches);

		return $matches[0];
	}//end histogramSql()

	public function testTheRequestedIntervalBucketsTheDates(): void {
		$facets = $this->histogram(['type' => 'date_histogram', 'interval' => 'year']);

		$this->assertStringContainsString("DATE_FORMAT(datum, '%Y') as date_key", $this->histogramSql());
		$this->assertSame('year', $facets['datum']['interval'] ?? null);
	}//end testTheRequestedIntervalBucketsTheDates()

	public function testWithoutAnIntervalTheDatesAreBucketedPerMonth(): void {
		$facets = $this->histogram(['type' => 'date_histogram']);

		$this->assertStringContainsString("DATE_FORMAT(datum, '%Y-%m') as date_key", $this->histogramSql());
		$this->assertSame('month', $facets['datum']['interval'] ?? null);
	}//end testWithoutAnIntervalTheDatesAreBucketedPerMonth()
}//end class
