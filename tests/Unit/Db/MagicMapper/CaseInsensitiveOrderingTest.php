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
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
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
}//end class
