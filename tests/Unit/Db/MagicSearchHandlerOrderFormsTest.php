<?php

/**
 * A single-table search takes every form of `_order`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use LogicException;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\TableSearch;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Support\OrderParameter
 * @uses   \OCA\OpenRegister\Support\QueryLimit
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SortRequest
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\DateTimeNormalizer
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicReadTable
 * @uses \OCA\OpenRegister\Db\MagicMapper\TableSearch
 * @uses \OCA\OpenRegister\Db\RegisterSchema
 * @uses \OCA\OpenRegister\Db\Register\RegisterLanguages
 * @uses \OCA\OpenRegister\Service\Search\SearchGrammar
 */
class MagicSearchHandlerOrderFormsTest extends TestCase {
	/**
	 * The ORDER BY the search built.
	 *
	 * @var array<int, string>
	 */
	private array $orderBy = [];

	/**
	 * The forms, and the ORDER BY each gives.
	 *
	 * @return array<string, array{0: mixed, 1: array<int, string>}>
	 */
	public static function forms(): array {
		return [
			'a map' => [['title' => 'desc'], ['LOWER(CAST("t"."title" AS TEXT)) DESC', '"t"."title" DESC', 't._id ASC']],
			'a map as JSON' => ['{"title":"desc"}', ['LOWER(CAST("t"."title" AS TEXT)) DESC', '"t"."title" DESC', 't._id ASC']],
			'a list of names' => [['title'], ['LOWER(CAST("t"."title" AS TEXT)) ASC', '"t"."title" ASC', 't._id ASC']],
			'the list GraphQL sends' => ['[{"field":"title","direction":"DESC"}]', ['LOWER(CAST("t"."title" AS TEXT)) DESC', '"t"."title" DESC', 't._id ASC']],
			'one name' => ['title', ['LOWER(CAST("t"."title" AS TEXT)) ASC', '"t"."title" ASC', 't._id ASC']],
			'a list for a direction' => [['title' => ['desc']], ['LOWER(CAST("t"."title" AS TEXT)) ASC', '"t"."title" ASC', 't._id ASC']],
			'a digit-only property' => [[7 => 'desc'], ['LOWER(CAST("t"."7" AS TEXT)) DESC', '"t"."7" DESC', 't._id ASC']],
			'nothing usable keeps the stable default' => [[5], ['t._id ASC']],
		];
	}//end forms()

	/**
	 * Each form orders the search, and none ends in a TypeError.
	 *
	 * @param mixed              $order    The `_order` value.
	 * @param array<int, string> $expected The ORDER BY.
	 *
	 * @return void
	 */
	#[DataProvider('forms')]
	public function testEveryFormOrdersTheSearch(mixed $order, array $expected): void {
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string'], '7' => ['type' => 'string']]);

		try {
			$this->handler()->searchTable(query: ['_order' => $order], tableSearch: new TableSearch(table: new RegisterSchema(register: new Register(), schema: $schema), tableName: 'magic'));
			$this->fail('The builder stops the search right after the order.');
		} catch (LogicException $stopped) {
			$this->assertSame('ordered', $stopped->getMessage());
		}

		$this->assertSame($expected, $this->orderBy);
	}//end testEveryFormOrdersTheSearch()

	/**
	 * A handler whose filtered query is a builder that records the order.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(): MagicSearchHandler {
		$logger = $this->createMock(LoggerInterface::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		$handler = $this->getMockBuilder(MagicSearchHandler::class)
			->setConstructorArgs(
				[
					$db,
					$logger,
					$this->createMock(MagicRbacHandler::class),
					$this->createMock(MagicOrganizationHandler::class),
					new SchemaTypeConverter(),
					new DateTimeNormalizer($logger),
					$this->createMock(RelatedRowQueryApplier::class),
				]
			)
			->onlyMethods(['buildFilteredQuery'])
			->getMock();
		$handler->method('buildFilteredQuery')->willReturn($this->queryBuilder());

		return $handler;
	}//end handler()

	/**
	 * A builder that records ORDER BY and stops at the page limit.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getColumnName')->willReturnCallback(
			static fn (string $column, string $alias = ''): string => "\"{$alias}\".\"{$column}\""
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
				$this->orderBy[] = trim((string) $sort . ' ' . (string) $direction);
				return $qb;
			}
		);
		$qb->method('setMaxResults')->willThrowException(new LogicException('ordered'));

		return $qb;
	}//end queryBuilder()
}//end class
