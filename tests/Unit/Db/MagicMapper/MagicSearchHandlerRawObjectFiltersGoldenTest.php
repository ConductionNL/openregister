<?php

/**
 * Golden for the object field filters on the raw-SQL (UNION) search path and
 * for nested-path filters on both paths.
 *
 * Records every condition buildObjectFilterConditionsSql() returns for scalar,
 * null, like, list, comparison-operator, array, object and nested-path
 * filters, and every predicate applyObjectFilters() adds for nested-path
 * filters, on PostgreSQL and MySQL. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use OCA\OpenRegister\Db\MagicMapper\ConditionSqlRun;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;

/**
 * Raw-SQL object field filters and nested-path filters, recorded per filter and platform.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\LikeOperator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\ObjectFieldFilter
 * @uses   \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Support\FilterParams
 * @uses   \OCA\OpenRegister\Support\NullFilter
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermParser
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermSqlCompiler
 */
class MagicSearchHandlerRawObjectFiltersGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/raw-object-filters-golden.json';

	private const PROPERTIES = [
		'title' => ['type' => 'string'],
		'firstName' => ['type' => 'string'],
		'count' => ['type' => 'integer'],
		'amount' => ['type' => 'number'],
		'flag' => ['type' => 'boolean'],
		'tags' => ['type' => 'array'],
		'owner' => ['type' => 'object'],
		'secret' => ['type' => 'object'],
		'sealed' => ['type' => 'object', 'x-openregister-encrypted' => true],
	];

	/**
	 * Every builder call, rendered.
	 *
	 * @var array<int, string>
	 */
	private array $captured = [];

	/**
	 * Every filter adds exactly the predicates recorded, on both platforms.
	 *
	 * @return void
	 */
	public function testObjectFiltersMatchGolden(): void {
		$filters = [
			'scalar' => ['title' => 'Hallo'],
			'camel' => ['firstName' => 'Jan'],
			'is null' => ['title' => 'IS NULL'],
			'is not null' => ['count' => 'IS NOT NULL'],
			'number' => ['count' => 3],
			'numeric string' => ['count' => '3'],
			'uuid on number' => ['count' => '0a1b2c3d-1111-2222-3333-444455556666'],
			'boolean true' => ['flag' => true],
			'boolean false' => ['flag' => false],
			'list' => ['title' => ['a', 'b']],
			'list on number' => ['count' => ['1', 'x']],
			'range' => ['amount' => ['gte' => 1, 'lte' => 9]],
			'gt lt' => ['count' => ['gt' => '1', 'lt' => 'z']],
			'in scalar' => ['title' => ['in' => 'a']],
			'in list' => ['count' => ['in' => [1, 'u']]],
			'notIn list' => ['title' => ['notIn' => ['a', 'b']]],
			'notIn empty' => ['title' => ['notIn' => []]],
			'notIn scalar' => ['count' => ['notIn' => 'x']],
			'ne' => ['count' => ['ne' => 'x']],
			'isnull true' => ['title' => ['isnull' => true]],
			'isnull false' => ['title' => ['isnull' => 'false']],
			'everything' => ['amount' => ['gte' => 1, 'lt' => 5, 'in' => [2], 'notIn' => [3], 'ne' => 4, 'isnull' => 'false']],
			'like' => ['title' => ['like' => 'foo']],
			'like and gte' => ['count' => ['like' => '1', 'gte' => 2]],
			'like empty' => ['title' => ['like' => '']],
			'or null' => ['title' => ['eq' => 'a', 'orNull' => true]],
			'array' => ['tags' => 'x'],
			'array list' => ['tags' => ['x', 'y']],
			'object' => ['owner' => 'abc'],
			'object list' => ['owner' => ['abc', 'def']],
			'missing' => ['nope' => 1, 'title' => 'x'],
			'several' => ['title' => 'a', 'count' => ['gte' => 2], 'flag' => true],
			'reserved and meta' => ['_limit' => 5, '@self' => ['owner' => 'x'], 'title' => 'y'],
			'nested' => ['owner.name' => 'Jan'],
			'nested deep' => ['owner.address.city' => ['in' => ['a', 'b']]],
			'nested or null' => ['owner.name' => ['eq' => 'Jan', 'orNull' => true]],
			'nested unreadable' => ['secret.name' => 'x'],
			'nested encrypted' => ['sealed.name' => 'x'],
		];

		$actual = [];
		foreach (['pg' => true, 'mysql' => false] as $platform => $isPostgres) {
			foreach ($filters as $name => $filter) {
				$actual["{$platform} raw {$name}"] = $this->apply(filters: $filter, isPostgres: $isPostgres, raw: true);
				if (str_starts_with($name, 'nested') === true) {
					$actual["{$platform} qb {$name}"] = $this->apply(filters: $filter, isPostgres: $isPostgres, raw: false);
				}
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
	}//end testObjectFiltersMatchGolden()

	/**
	 * Run buildObjectFilterConditionsSql() or applyObjectFilters() on one filter set.
	 *
	 * @param array $filters    The object field filters.
	 * @param bool  $isPostgres Whether the platform is PostgreSQL.
	 * @param bool  $raw        Whether to run the raw-SQL path.
	 *
	 * @return array<int, string> The conditions or captured predicates, or the exception.
	 */
	private function apply(array $filters, bool $isPostgres, bool $raw): array {
		$this->captured = [];
		$db = $this->createMock(IDBConnection::class);
		$platform = new MySQLPlatform();
		if ($isPostgres === true) {
			$platform = new PostgreSQLPlatform();
		}

		$db->method('getDatabasePlatform')->willReturn($platform);
		$handler = new MagicSearchHandler(
			$db,
			$this->createMock(LoggerInterface::class),
			$this->createMock(MagicRbacHandler::class),
			$this->createMock(MagicOrganizationHandler::class),
			new SchemaTypeConverter(),
			$this->createMock(DateTimeNormalizer::class),
			relatedRows: $this->relatedRows()
		);
		$schema = new Schema();
		$schema->setProperties(self::PROPERTIES);

		try {
			if ($raw === true) {
				$connection = $this->createMock(IDBConnection::class);
				$connection->method('quote')->willReturnCallback(static fn ($value): string => "'" . (string) $value . "'");
				$this->captured = (new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql'))
					->invoke($handler, new ConditionSqlRun(query: $filters, table: new MagicReadTable(schema: $schema), connection: $connection, isPostgres: $isPostgres));
				return $this->captured;
			}

			(new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters'))->invoke($handler, new TableFilterRun(qb: $this->queryBuilder(), query: [], table: new MagicReadTable(schema: $schema)), $filters);
		} catch (\Throwable $e) {
			$this->captured[] = 'threw ' . get_class($e) . ': ' . $e->getMessage();
		}

		return $this->captured;
	}//end apply()

	/**
	 * A related-row applier that refuses reading the `secret` property only.
	 *
	 * @return RelatedRowQueryApplier The applier.
	 */
	private function relatedRows(): RelatedRowQueryApplier {
		$relatedRows = $this->createMock(RelatedRowQueryApplier::class);
		$relatedRows->method('mayReadProperty')->willReturnCallback(
			static fn (Schema $schema, string $property): bool => $property !== 'secret'
		);

		return $relatedRows;
	}//end relatedRows()

	/**
	 * A builder that renders every predicate it is given.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$params = 0;
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn', 'like', 'iLike'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static fn ($column, $value): string => "{$operator}({$column},{$value})"
			);
		}

		$expr->method('isNull')->willReturnCallback(static fn ($c): string => "isNull({$c})");
		$expr->method('isNotNull')->willReturnCallback(static fn ($c): string => "isNotNull({$c})");
		$expr->method('orX')->willReturnCallback(static fn (...$parts): ICompositeExpression => self::composite(parts: $parts));
		$expr->method('andX')->willReturnCallback(static fn (...$parts): ICompositeExpression => self::composite(parts: $parts));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql): string => $sql);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type = null) use (&$params): string {
				$params++;
				return ":p{$params}" . json_encode([$value, $type]);
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string) $predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * A composite expression that renders its parts.
	 *
	 * @param array $parts The initial parts.
	 *
	 * @return ICompositeExpression The composite.
	 */
	private static function composite(array $parts): ICompositeExpression {
		return new class($parts) implements ICompositeExpression, \Stringable {
			/**
			 * Constructor.
			 *
			 * @param array $parts The parts.
			 */
			public function __construct(private array $parts) {
			}

			public function addMultiple(array $parts = []): ICompositeExpression {
				foreach ($parts as $part) {
					$this->add($part);
				}

				return $this;
			}

			public function add($part): ICompositeExpression {
				$this->parts[] = $part;
				return $this;
			}

			public function count(): int {
				return count($this->parts);
			}

			public function getType(): string {
				return 'composite';
			}

			public function __toString(): string {
				return 'composite(' . implode(' | ', array_map(static fn ($p): string => (string) $p, $this->parts)) . ')';
			}
		};
	}//end composite()
}//end class
