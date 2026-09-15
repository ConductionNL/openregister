<?php

/**
 * Tests for the missing-value bucket on a terms facet.
 *
 * "Twelve cases have no result type" is a data-quality report nobody should
 * have to write, and the facet already knows the answer: it counted the ones
 * that do. Before this change the terms facet filtered the NULL group out with
 * an explicit IS NOT NULL, so the complement was unreachable.
 *
 * Three properties are pinned here, and each of them is a way this could go
 * silently wrong rather than loudly wrong.
 *
 * The NULL group is GROUPED OVER, not filtered out and not counted again
 * afterwards, so the missing bucket is produced by the same query, inside the
 * same access scope, as every value bucket (ADR-009).
 *
 * The NULL group is pinned FIRST in the ordering. Buckets are capped, so a
 * count-ordered facet with many values could drop a small NULL group off the
 * end and report a missing bucket of zero — a wrong number that looks exactly
 * like a right one.
 *
 * The NULL row never reaches the value buckets, where it would render as an
 * unlabelled chip.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks the missing-value bucket of a terms facet.
 */
class MagicFacetHandlerMissingBucketTest extends TestCase {

	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	/**
	 * Every expression the query builder was asked to build.
	 *
	 * @var string[]
	 */
	private array $predicates = [];

	/**
	 * Every ORDER BY expression, in order.
	 *
	 * @var string[]
	 */
	private array $ordering = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
		$this->predicates = [];
		$this->ordering = [];
	}//end setUp()

	/**
	 * A result double serving the given rows and then false.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows to hand back.
	 *
	 * @return IResult The result double.
	 */
	private function makeResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$rows[] = false;
		$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);

		return $result;
	}//end makeResult()

	/**
	 * A prepared-statement double answering the information_schema column probe.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows to hand back.
	 *
	 * @return IPreparedStatement The statement double.
	 */
	private function makeStatement(array $rows): IPreparedStatement {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturn($this->createMock(IResult::class));
		$rows[] = false;
		$statement->method('fetch')->willReturnOnConsecutiveCalls(...$rows);

		return $statement;
	}//end makeStatement()

	/**
	 * A query-builder double that records predicates and ordering and serves
	 * the given facet rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The facet rows to serve.
	 *
	 * @return IQueryBuilder The query-builder double.
	 */
	private function makeQueryBuilder(array $rows): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('isNull')->willReturnCallback(static fn (string $c): string => "isNull({$c})");
		$expr->method('isNotNull')->willReturnCallback(static fn (string $c): string => "isNotNull({$c})");

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('expr')->willReturn($expr);
		$queryBuilder->method('createFunction')->willReturnCallback(static fn (string $sql): string => $sql);
		$queryBuilder->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);

		foreach (['selectAlias', 'addSelect', 'from', 'groupBy', 'setMaxResults'] as $fluent) {
			$queryBuilder->method($fluent)->willReturn($queryBuilder);
		}

		$queryBuilder->method('where')->willReturnCallback(
			function (...$predicates) use ($queryBuilder): IQueryBuilder {
				foreach ($predicates as $predicate) {
					$this->predicates[] = (string)$predicate;
				}

				return $queryBuilder;
			}
		);
		$queryBuilder->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($queryBuilder): IQueryBuilder {
				foreach ($predicates as $predicate) {
					$this->predicates[] = (string)$predicate;
				}

				return $queryBuilder;
			}
		);
		$queryBuilder->method('orderBy')->willReturnCallback(
			function ($sort, $order = null) use ($queryBuilder): IQueryBuilder {
				$this->ordering[] = (string)$sort . ' ' . (string)$order;
				return $queryBuilder;
			}
		);
		$queryBuilder->method('addOrderBy')->willReturnCallback(
			function ($sort, $order = null) use ($queryBuilder): IQueryBuilder {
				$this->ordering[] = (string)$sort . ' ' . (string)$order;
				return $queryBuilder;
			}
		);

		$queryBuilder->method('executeQuery')->willReturn($this->makeResult($rows));

		return $queryBuilder;
	}//end makeQueryBuilder()

	/**
	 * Run getTermsFacet() over the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The facet rows the database returns.
	 *
	 * @return array<string, mixed> The facet result.
	 */
	private function facetOver(array $rows): array {
		// The information_schema probe runs first; it must find the column.
		$this->db->method('prepare')->willReturn(
			$this->makeStatement([['col' => 'result_type']])
		);
		$this->db->method('getQueryBuilder')->willReturn($this->makeQueryBuilder($rows));

		$handler = new MagicFacetHandler(db: $this->db, logger: $this->logger);

		$method = new ReflectionMethod(MagicFacetHandler::class, 'getTermsFacet');
		$method->setAccessible(true);

		return $method->invoke(
			$handler,
			'openregister_table_1_2',
			'result_type',
			[],
			false,
			$this->createMock(Register::class),
			$this->createMock(Schema::class)
		);
	}//end facetOver()

	/**
	 * The spec scenario: forty objects, twelve of which hold no resultType.
	 *
	 * @return void
	 */
	public function testTheNullGroupBecomesTheMissingBucket(): void {
		$facet = $this->facetOver(
			[
				['facet_value' => null, 'doc_count' => 12],
				['facet_value' => 'toegekend', 'doc_count' => 20],
				['facet_value' => 'afgewezen', 'doc_count' => 8],
			]
		);

		$this->assertSame(12, $facet['missing']['results']);
	}//end testTheNullGroupBecomesTheMissingBucket()

	/**
	 * The NULL row is not also a value bucket: it would render as a chip with
	 * no label and be counted twice on screen.
	 *
	 * @return void
	 */
	public function testTheNullRowIsNotAlsoAValueBucket(): void {
		$facet = $this->facetOver(
			[
				['facet_value' => null, 'doc_count' => 12],
				['facet_value' => 'toegekend', 'doc_count' => 20],
				['facet_value' => 'afgewezen', 'doc_count' => 8],
			]
		);

		$this->assertCount(2, $facet['buckets']);
		$this->assertSame(['toegekend', 'afgewezen'], array_column($facet['buckets'], 'key'));
		$this->assertSame([20, 8], array_column($facet['buckets'], 'results'));
	}//end testTheNullRowIsNotAlsoAValueBucket()

	/**
	 * A facet over a property nothing leaves empty still carries the key, with
	 * a zero. A consumer that has to branch on the key's absence will forget.
	 *
	 * @return void
	 */
	public function testAFacetWithNoNullGroupStillCarriesTheKey(): void {
		$facet = $this->facetOver([['facet_value' => 'toegekend', 'doc_count' => 20]]);

		$this->assertArrayHasKey('missing', $facet);
		$this->assertSame(0, $facet['missing']['results']);
	}//end testAFacetWithNoNullGroupStillCarriesTheKey()

	/**
	 * The count comes from the same query as the value buckets, so the query
	 * must NOT exclude the NULL group. An IS NOT NULL here would mean the
	 * missing bucket had to be counted by a second pass.
	 *
	 * @return void
	 */
	public function testTheQueryDoesNotExcludeTheNullGroup(): void {
		$this->facetOver([['facet_value' => 'toegekend', 'doc_count' => 20]]);

		foreach ($this->predicates as $predicate) {
			$this->assertStringNotContainsString(
				'isNotNull(result_type)',
				$predicate,
				'The faceted column must not be filtered to non-null; the NULL group IS the missing bucket.'
			);
		}
	}//end testTheQueryDoesNotExcludeTheNullGroup()

	/**
	 * The NULL group is pinned first, so the bucket cap cannot truncate it into
	 * a silent zero on a facet with many values.
	 *
	 * @return void
	 */
	public function testTheNullGroupIsOrderedFirst(): void {
		$this->facetOver([['facet_value' => 'toegekend', 'doc_count' => 20]]);

		$this->assertNotEmpty($this->ordering);
		$this->assertStringContainsString(
			'CASE WHEN result_type IS NULL THEN 0 ELSE 1 END',
			$this->ordering[0],
			'The NULL group must sort first, ahead of the count ordering.'
		);
		$this->assertStringContainsString('doc_count DESC', implode(' | ', $this->ordering));
	}//end testTheNullGroupIsOrderedFirst()
}//end class
