<?php

/**
 * Ordering on a string property is total and case-insensitive: LOWER(col), then raw col, then _id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-insensitive-ordering/specs/zoeken-filteren/spec.md#requirement-ordering-on-a-string-property-ignores-letter-case-on-every-supported-database-req-cio-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\SortRequest;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionPage
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Support\OrderParameter
 * @uses   \OCA\OpenRegister\Support\QueryLimit
 */
class CaseInsensitiveOrderingTest extends TestCase {

	/**
	 * Every addOrderBy() call as [expression, direction].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $orderBy = [];

	/**
	 * The ORDER BY terms applySorting() builds for an order on the given platform.
	 *
	 * @param array  $order    The requested order.
	 * @param object $platform The database platform.
	 * @param array  $properties The schema's properties.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function orderTerms(array $order, object $platform, array $properties = ['title' => ['type' => 'string']]): array {
		$this->orderBy = [];

		$logger = $this->createMock(LoggerInterface::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$handler = new MagicSearchHandler(
			$db,
			$logger,
			$this->createMock(MagicRbacHandler::class),
			$this->createMock(MagicOrganizationHandler::class),
			new SchemaTypeConverter(),
			new DateTimeNormalizer($logger),
			$this->createMock(RelatedRowQueryApplier::class),
		);

		$schema = new Schema();
		$schema->setProperties($properties);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applySorting');
		$method->invoke($handler, $this->queryBuilder(), new SortRequest(order: $order, schema: $schema));

		return $this->orderBy;
	}//end orderTerms()

	/**
	 * A query-builder double that quotes identifiers and records every addOrderBy().
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getColumnName')->willReturnCallback(
			static fn (string $column, string $alias = ''): string => ($alias === '' ? '' : "\"{$alias}\".") . "\"{$column}\""
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $call): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($call);
				return $function;
			}
		);
		$qb->method('addOrderBy')->willReturnCallback(
			function ($sort, $direction = null) use ($qb) {
				$this->orderBy[] = [(string) $sort, (string) $direction];
				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	public function testTheSqlIsLowerThenRawThenIdOnPostgres(): void {
		$this->assertSame(
			[
				['LOWER(CAST("t"."title" AS TEXT))', 'ASC'],
				['"t"."title"', 'ASC'],
				['t._id', 'ASC'],
			],
			$this->orderTerms(['title' => 'asc'], new PostgreSQLPlatform())
		);
	}//end testTheSqlIsLowerThenRawThenIdOnPostgres()

	public function testTheSqlIsLowerThenRawThenIdOnMySql(): void {
		$this->assertSame(
			[
				['LOWER("t"."title")', 'DESC'],
				['"t"."title"', 'DESC'],
				['t._id', 'ASC'],
			],
			$this->orderTerms(['title' => 'desc'], new MariaDBPlatform())
		);
	}//end testTheSqlIsLowerThenRawThenIdOnMySql()

	public function testANonStringPropertyKeepsNativeOrdering(): void {
		$terms = $this->orderTerms(
			['amount' => 'asc', 'when' => 'desc'],
			new PostgreSQLPlatform(),
			['amount' => ['type' => 'number'], 'when' => ['type' => 'string', 'format' => 'date']]
		);

		// No LOWER() is applied to a number or a date; only the _id tie-break is added.
		$this->assertSame(
			[['t.amount', 'ASC'], ['t.when', 'DESC'], ['t._id', 'ASC']],
			$terms
		);
	}//end testANonStringPropertyKeepsNativeOrdering()

	public function testTiesAreBrokenByRawValueThenId(): void {
		$terms = $this->orderTerms(['title' => 'asc'], new MariaDBPlatform());

		// "besluit" and "Besluit" lower-case alike, so the raw value decides
		// between them, and _id closes the order so paging never repeats a row.
		$this->assertSame('LOWER("t"."title")', $terms[0][0]);
		$this->assertSame(['"t"."title"', 'ASC'], $terms[1]);
		$this->assertSame(['t._id', 'ASC'], $terms[2]);
		$this->assertCount(3, $terms);
	}//end testTiesAreBrokenByRawValueThenId()

	public function testTheNameMetadataFieldOrdersCaseInsensitivelyAndTotally(): void {
		$this->assertSame(
			[
				['LOWER(CAST("t"."_name" AS TEXT))', 'ASC'],
				['"t"."_name"', 'ASC'],
				['t._id', 'ASC'],
			],
			$this->orderTerms(['@self.name' => 'asc'], new PostgreSQLPlatform())
		);
	}//end testTheNameMetadataFieldOrdersCaseInsensitivelyAndTotally()
	/**
	 * Call a private MagicMapper method on a mapper over the given platform.
	 *
	 * @param string $method   The method.
	 * @param array  $args     Its arguments.
	 * @param object $platform The database platform.
	 *
	 * @return mixed
	 */
	private function mapperCall(string $method, array $args, object $platform): mixed {
		$mapper = (new ReflectionClass(MagicMapper::class))->newInstanceWithoutConstructor();
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		(new ReflectionProperty(MagicMapper::class, 'db'))->setValue($mapper, $db);

		return (new ReflectionMethod(MagicMapper::class, $method))->invokeArgs($mapper, $args);
	}//end mapperCall()

	/**
	 * A schema with the given properties.
	 *
	 * @param array $properties The properties.
	 *
	 * @return Schema
	 */
	private static function schemaWith(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}//end schemaWith()

	/**
	 * REQ-CIO-001 on the UNION path: a text column orders lower-cased, then raw,
	 * then by uuid, in the SQL and in the merge of several batches alike.
	 *
	 * @return void
	 */
	public function testTheUnionPathOrdersTheSameWay(): void {
		$postgres = $this->mapperCall('unionOrderByClause', ['query' => ['_order' => ['title' => 'asc']], 'textColumns' => ['title']], new PostgreSQLPlatform());
		$this->assertSame(' ORDER BY LOWER(CAST("title" AS TEXT)) ASC, "title" ASC, _uuid ASC', $postgres);

		$mysql = $this->mapperCall('unionOrderByClause', ['query' => ['_order' => ['title' => 'desc']], 'textColumns' => ['title']], new MariaDBPlatform());
		$this->assertSame(' ORDER BY LOWER(`title`) DESC, `title` DESC, _uuid ASC', $mysql);

		$rows = [
			['_uuid' => 'u1', 'title' => 'Zienswijze'],
			['_uuid' => 'u2', 'title' => 'besluit'],
			['_uuid' => 'u3', 'title' => 'aanvraag'],
			['_uuid' => 'u4', 'title' => 'Besluit'],
			['_uuid' => 'u0', 'title' => 'besluit'],
		];
		$merged = $this->mapperCall('mergeUnionBatchRows', ['rows' => $rows, 'query' => ['_order' => ['title' => 'asc']], 'textColumns' => ['title']], new MariaDBPlatform());

		// aanvraag before Besluit before Zienswijze; the raw value then the uuid break the ties.
		$this->assertSame(['u3', 'u4', 'u0', 'u2', 'u1'], array_column($merged, '_uuid'));
	}//end testTheUnionPathOrdersTheSameWay()

	/**
	 * A text metadata column folds case on the UNION path without being declared; a non-text column keeps its native order.
	 *
	 * @return void
	 */
	public function testTheUnionPathFoldsTheNameAndLeavesNumbersAlone(): void {
		$this->assertSame(
			' ORDER BY LOWER(CAST("_name" AS TEXT)) ASC, "_name" ASC, _uuid ASC',
			$this->mapperCall('unionOrderByClause', ['query' => ['_order' => ['@self.name' => 'asc']], 'textColumns' => []], new PostgreSQLPlatform())
		);
		$this->assertSame(
			' ORDER BY "amount" ASC, _uuid ASC',
			$this->mapperCall('unionOrderByClause', ['query' => ['_order' => ['amount' => 'asc']], 'textColumns' => ['title']], new PostgreSQLPlatform())
		);
	}//end testTheUnionPathFoldsTheNameAndLeavesNumbersAlone()

	/**
	 * A column is text on the UNION path only when every schema declaring it holds text there.
	 *
	 * @return void
	 */
	public function testAUnionColumnIsTextOnlyWhenEverySchemaHoldsText(): void {
		$pairs = [
			['schema' => self::schemaWith(['title' => ['type' => 'string'], 'code' => ['type' => 'string'], 'when' => ['type' => 'string', 'format' => 'date'], 'label' => ['type' => 'string', 'translatable' => true]])],
			['schema' => self::schemaWith(['title' => ['type' => 'string'], 'code' => ['type' => 'integer'], 'extra' => ['type' => 'string']])],
			['schema' => null],
		];

		$this->assertSame(['title', 'extra'], $this->mapperCall('unionTextColumns', ['registerSchemaPairs' => $pairs], new PostgreSQLPlatform()));
	}//end testAUnionColumnIsTextOnlyWhenEverySchemaHoldsText()
}//end class
