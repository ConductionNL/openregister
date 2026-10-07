<?php

/**
 * The `like` property filter on every MagicSearchHandler condition builder.
 *
 * Before this operator existed, `?title[like]=foo` reached the builders as
 * `title => ['like' => 'foo']`. `like` was not in COMPARISON_OPERATORS, so the
 * bag read as a bare IN list and the filter became `title IN ('foo')`: an exact
 * match that answered a table header filter with zero rows, silently.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks `like` on the QueryBuilder path and the raw-SQL UNION path, for object
 * fields and for `@self` metadata, on PostgreSQL and MySQL/MariaDB.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-like-filter-matches-a-substring-ignoring-case
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-like-matches-percent-underscore-and-backslash-literally
 */
class MagicSearchHandlerLikeOperatorTest extends TestCase {
	/**
	 * WHERE fragments captured from the QueryBuilder path.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	/**
	 * Values bound through createNamedParameter(), in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $bound = [];

	/**
	 * A handler on the given database platform.
	 *
	 * @param string $platformClass A Doctrine platform class.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(string $platformClass = PostgreSQL120Platform::class): MagicSearchHandler {
		$logger = $this->createMock(LoggerInterface::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock($platformClass));

		return new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
	}//end handler()

	/**
	 * A QueryBuilder double that renders predicates as text and binds `:pN`.
	 *
	 * @return IQueryBuilder The double.
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static function (string $column, $value) use ($operator): string {
					return "{$operator}({$column},{$value})";
				}
			);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value): string {
				$this->bound[] = $value;
				return ':p' . count($this->bound);
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * A connection double whose quote() wraps values in single quotes.
	 *
	 * @return object The double.
	 */
	private function connection(): object {
		$conn = $this->createMock(IDBConnection::class);
		$conn->method('quote')->willReturnCallback(static fn ($v) => "'" . str_replace("'", "''", (string)$v) . "'");
		return $conn;
	}//end connection()

	/**
	 * A schema with a string `title`, an integer `amount` and an array `tags`.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(
			[
				'title' => ['type' => 'string'],
				'amount' => ['type' => 'integer'],
				'tags' => ['type' => 'array'],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * Run applyObjectFilters().
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param string $platformClass The platform.
	 *
	 * @return string[] Captured WHERE fragments.
	 */
	private function applyObject(array $filters, string $platformClass = PostgreSQL120Platform::class): array {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters');
		$method->invoke($this->handler($platformClass), $this->queryBuilder(), $filters, $this->schema());

		return $this->captured;
	}//end applyObject()

	/**
	 * Run buildObjectFilterConditionsSql().
	 *
	 * @param array<string, mixed> $query The query.
	 * @param string $platformClass The platform.
	 *
	 * @return string[] The SQL conditions.
	 */
	private function unionObject(array $query, string $platformClass = PostgreSQL120Platform::class): array {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
		$isPostgres = ($platformClass === PostgreSQL120Platform::class);

		return $method->invoke($this->handler($platformClass), $query, $this->schema(), $this->connection(), $isPostgres);
	}//end unionObject()

	/**
	 * The bracket filter becomes a bound, case-insensitive substring match on PostgreSQL.
	 *
	 * @return void
	 */
	public function testObjectFilterIsBoundIlikeOnPostgres(): void {
		$captured = $this->applyObject(['title' => ['like' => 'Demo']]);

		$this->assertSame(['(CAST(t.title AS TEXT) ILIKE :p1)'], $captured);
		$this->assertSame(['%Demo%'], $this->bound);
	}//end testObjectFilterIsBoundIlikeOnPostgres()

	/**
	 * The old reading, an exact `IN ('Demo')`, is gone.
	 *
	 * @return void
	 */
	public function testObjectFilterIsNoLongerAnExactInList(): void {
		$captured = $this->applyObject(['title' => ['like' => 'Demo']]);

		foreach ($captured as $fragment) {
			$this->assertStringNotContainsString('in(', $fragment);
		}
	}//end testObjectFilterIsNoLongerAnExactInList()

	/**
	 * MySQL and MariaDB lower both sides.
	 *
	 * @return void
	 */
	public function testObjectFilterOnMariaDb(): void {
		$captured = $this->applyObject(['title' => ['like' => 'Demo']], MariaDBPlatform::class);

		$this->assertSame(['(LOWER(CAST(t.title AS CHAR)) LIKE LOWER(:p1))'], $captured);
	}//end testObjectFilterOnMariaDb()

	/**
	 * `%`, `_` and `\` are escaped in the bound value.
	 *
	 * @return void
	 */
	public function testObjectFilterEscapesMetacharacters(): void {
		$this->applyObject(['title' => ['like' => '100%_a\\b']]);

		$this->assertSame(['%100\\%\\_a\\\\b%'], $this->bound);
	}//end testObjectFilterEscapesMetacharacters()

	/**
	 * `like` sits beside the other operators in one bag.
	 *
	 * @return void
	 */
	public function testLikeCombinesWithOtherOperators(): void {
		$captured = $this->applyObject(['title' => ['like' => 'demo', 'ne' => 'Demo B.V.']]);

		$this->assertSame(['(CAST(t.title AS TEXT) ILIKE :p1)', 'neq(t.title,:p2)'], $captured);
	}//end testLikeCombinesWithOtherOperators()

	/**
	 * Numeric and JSON array columns are matched as text, not refused or misread.
	 *
	 * @return void
	 */
	public function testNonStringColumnsAreMatchedAsText(): void {
		$captured = $this->applyObject(['amount' => ['like' => '25'], 'tags' => ['like' => 'urgent']]);

		$this->assertSame(['(CAST(t.amount AS TEXT) ILIKE :p1)', '(CAST(t.tags AS TEXT) ILIKE :p2)'], $captured);
	}//end testNonStringColumnsAreMatchedAsText()

	/**
	 * A cleared filter (`title[like]=`) adds no condition.
	 *
	 * @return void
	 */
	public function testEmptyTermAddsNoCondition(): void {
		$this->assertSame([], $this->applyObject(['title' => ['like' => '']]));
	}//end testEmptyTermAddsNoCondition()

	/**
	 * Several terms match any of them.
	 *
	 * @return void
	 */
	public function testListOfTermsMatchesAny(): void {
		$captured = $this->applyObject(['title' => ['like' => ['demo', 'test']]]);

		$this->assertSame(['(CAST(t.title AS TEXT) ILIKE :p1 OR CAST(t.title AS TEXT) ILIKE :p2)'], $captured);
	}//end testListOfTermsMatchesAny()

	/**
	 * The raw UNION path emits the same predicate with a quoted, escaped literal.
	 *
	 * @return void
	 */
	public function testRawSqlObjectPathAgrees(): void {
		$conditions = $this->unionObject(['title' => ['like' => "it's 100%"]]);

		$this->assertSame(["(CAST(\"title\" AS TEXT) ILIKE '%it''s 100\\%%')"], $conditions);
	}//end testRawSqlObjectPathAgrees()

	/**
	 * The raw UNION path on MariaDB.
	 *
	 * @return void
	 */
	public function testRawSqlObjectPathOnMariaDb(): void {
		$conditions = $this->unionObject(['title' => ['like' => 'a_b']], MariaDBPlatform::class);

		$this->assertSame(["(LOWER(CAST(`title` AS CHAR)) LIKE LOWER('%a\\_b%'))"], $conditions);
	}//end testRawSqlObjectPathOnMariaDb()

	/**
	 * `@self` metadata filters take `like` on the QueryBuilder path.
	 *
	 * @return void
	 */
	public function testMetadataFilterOnQueryBuilderPath(): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyMetadataFilters');
		$method->invoke($this->handler(), $this->queryBuilder(), ['name' => ['like' => 'demo']]);

		$this->assertCount(1, $this->captured);
		$this->assertStringContainsString('ILIKE :p1', $this->captured[0]);
		$this->assertSame(['%demo%'], $this->bound);
	}//end testMetadataFilterOnQueryBuilderPath()

	/**
	 * `@self` metadata filters take `like` on the raw UNION path.
	 *
	 * @return void
	 */
	public function testMetadataFilterOnRawSqlPath(): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildMetadataFilterConditionsSql');
		$conditions = $method->invoke($this->handler(), ['@self' => ['name' => ['like' => 'demo']]], $this->connection(), true);

		$this->assertCount(1, $conditions);
		$this->assertStringContainsString("ILIKE '%demo%'", $conditions[0]);
	}//end testMetadataFilterOnRawSqlPath()
}//end class
