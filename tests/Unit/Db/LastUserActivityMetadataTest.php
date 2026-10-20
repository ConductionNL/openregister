<?php

/**
 * `@self.lastUserActivity` reads back, filters and sorts like `@self.updated`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class LastUserActivityMetadataTest extends TestCase {

	/**
	 * WHERE and ORDER BY fragments captured from the QueryBuilder path.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	/**
	 * The search handler on a PostgreSQL connection double.
	 *
	 * @var MagicSearchHandler
	 */
	private MagicSearchHandler $search;

	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));

		$this->search = new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
		$this->captured = [];
	}//end setUp()

	/**
	 * A QueryBuilder double that records predicates and ORDER BY columns as text.
	 *
	 * @return IQueryBuilder The query-builder double.
	 */
	private function makeQueryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static fn (string $column, $value): string => "{$operator}({$column},{$value})"
			);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => (string)$value);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);
		$qb->method('addOrderBy')->willReturnCallback(
			function ($sort, $direction) use ($qb) {
				$this->captured[] = "order({$sort},{$direction})";
				return $qb;
			}
		);

		return $qb;
	}//end makeQueryBuilder()

	/**
	 * A schema without properties.
	 *
	 * @return Schema
	 */
	private function makeSchema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setProperties([]);
		return $schema;
	}//end makeSchema()

	public function testItFiltersOnTheQueryBuilderPath(): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyMetadataFilters');
		$method->invoke($this->search, $this->makeQueryBuilder(), ['lastUserActivity' => ['lt' => '2026-10-01']]);

		$this->assertSame(['lt(t._last_user_activity,2026-10-01)'], $this->captured);
	}

	public function testItFiltersOnTheUnionPath(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");

		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildMetadataFilterConditionsSql');
		$conditions = $method->invoke($this->search, ['@self' => ['lastUserActivity' => ['lt' => '2026-10-01']]], $connection, true);

		$this->assertSame(['"_last_user_activity" < \'2026-10-01\''], $conditions);
	}

	public function testItSortsByItsColumn(): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applySorting');
		$method->invoke($this->search, $this->makeQueryBuilder(), ['@self.lastUserActivity' => 'ASC'], $this->makeSchema());

		$this->assertContains('order(t._last_user_activity,ASC)', $this->captured);
	}

	public function testUpdatedStillSortsByItsOwnColumn(): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applySorting');
		$method->invoke($this->search, $this->makeQueryBuilder(), ['@self.updated' => 'DESC'], $this->makeSchema());

		$this->assertContains('order(t._updated,DESC)', $this->captured);
	}

	public function testTheSearchPathReadsItBack(): void {
		$register = new Register();
		$register->setId(1);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'convertRowToObjectEntity');
		$entity = $method->invoke(
			$this->search,
			['_uuid' => 'u-1', '_updated' => '2026-10-10 08:00:00', '_last_user_activity' => '2026-09-01 10:00:00'],
			$register,
			$this->makeSchema()
		);

		$this->assertSame('2026-09-01 10:00:00', $entity->getLastUserActivity()->format('Y-m-d H:i:s'));
		$this->assertSame('2026-10-10 08:00:00', $entity->getUpdated()->format('Y-m-d H:i:s'));
	}

	public function testTheFindPathReadsItBack(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$statistics = new MagicStatisticsHandler(
			db: $this->createMock(IDBConnection::class),
			logger: $logger,
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			schemaTypeConverter: new SchemaTypeConverter()
		);
		$register = new Register();
		$register->setId(1);

		$entity = $statistics->convertRowToObjectEntity(
			['_uuid' => 'u-1', '_id' => 1, '_last_user_activity' => '2026-09-01 10:00:00'],
			$register,
			$this->makeSchema()
		);

		$this->assertSame('2026-09-01 10:00:00', $entity->getLastUserActivity()->format('Y-m-d H:i:s'));
	}
}//end class
