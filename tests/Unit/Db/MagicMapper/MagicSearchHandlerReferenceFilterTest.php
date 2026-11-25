<?php

/**
 * The list path hands `_ref` blocks to the related-row applier with the queried schema.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowFilterTarget;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicReadTable
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableFilterRun
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\DateTimeNormalizer
 * @uses   \OCA\OpenRegister\Service\Object\SchemaTypeConverter
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowFilterTarget
 */
class MagicSearchHandlerReferenceFilterTest extends TestCase {

	/**
	 * The applier double.
	 *
	 * @var RelatedRowQueryApplier&MockObject
	 */
	private RelatedRowQueryApplier&MockObject $related;

	/**
	 * Run applyRelatedRowFilters() on a schema in a register.
	 *
	 * @param array<string, mixed> $query      The request query.
	 * @param Schema               $schema     The queried schema.
	 * @param int|null             $registerId The register.
	 *
	 * @return void
	 */
	private function applyTo(array $query, Schema $schema, ?int $registerId=1): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));
		$logger = $this->createMock(LoggerInterface::class);

		$handler = new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer(logger: $logger),
			relatedRows: $this->related
		);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyRelatedRowFilters');
		$method->invoke(
			$handler,
			new TableFilterRun(qb: $this->createMock(IQueryBuilder::class), query: $query, table: new MagicReadTable(schema: $schema, registerId: $registerId))
		);
	}//end applyTo()

	protected function setUp(): void {
		$this->related = $this->createMock(RelatedRowQueryApplier::class);
	}//end setUp()

	/**
	 * A `_ref` block alone reaches the applier, with the query and the schema being queried.
	 *
	 * @return void
	 */
	public function testARefBlockReachesTheApplierWithTheQueriedSchema(): void {
		$schema = new Schema();
		$schema->setId(7);
		$query = ['_ref' => ['aanvrager' => ['woonplaats' => 'Zuiddrecht']]];

		$this->related->expects($this->once())->method('apply')->with($this->anything(), $query, $schema)->willReturn(1);

		$this->applyTo(query: $query, schema: $schema);
	}//end testARefBlockReachesTheApplierWithTheQueriedSchema()

	/**
	 * A query without related-row blocks does not reach the applier.
	 *
	 * @return void
	 */
	public function testAQueryWithoutBlocksDoesNotReachTheApplier(): void {
		$this->related->expects($this->never())->method('apply');

		$this->applyTo(query: ['titel' => 'x'], schema: new Schema());
	}//end testAQueryWithoutBlocksDoesNotReachTheApplier()

	/**
	 * Without a register a `_ref` block is refused rather than read against another register's table.
	 *
	 * @return void
	 */
	public function testARefBlockWithoutARegisterIsRefused(): void {
		$this->related->expects($this->never())->method('apply');
		$this->expectException(InvalidArgumentException::class);

		$this->applyTo(query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Z']]], schema: new Schema(), registerId: null);
	}//end testARefBlockWithoutARegisterIsRefused()

	/**
	 * The handler with a database whose query builder logs the grouping calls into $log.
	 *
	 * @param array<int, string> $log The shared call log.
	 *
	 * @return MagicSearchHandler
	 */
	private function facetHandler(array &$log): MagicSearchHandler {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnCallback(static function (string $sql) use (&$log): \OCP\DB\QueryBuilder\IQueryFunction {
			$log[] = 'createFunction ' . $sql;
			return new class($sql) implements \OCP\DB\QueryBuilder\IQueryFunction {
				/**
				 * The SQL.
				 *
				 * @param string $sql The SQL.
				 */
				public function __construct(private readonly string $sql) {
				}//end __construct()

				/**
				 * The SQL.
				 *
				 * @return string
				 */
				public function __toString(): string {
					return $this->sql;
				}//end __toString()
			};
		});
		foreach (['selectAlias', 'addSelect', 'groupBy'] as $method) {
			$qb->method($method)->willReturnCallback(static function (mixed ...$args) use (&$log, $qb, $method): IQueryBuilder {
				$log[] = $method . ' ' . implode(' ', array_map('strval', $args));
				return $qb;
			});
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));
		$logger = $this->createMock(LoggerInterface::class);

		// The filtered query is buildFilteredQuery()'s, covered by its own tests;
		// here it stands for the list's query the facet groups.
		$handler = $this->getMockBuilder(MagicSearchHandler::class)
			->setConstructorArgs(
				[
					'db' => $db,
					'logger' => $logger,
					'rbacHandler' => $this->createMock(MagicRbacHandler::class),
					'organizationHandler' => $this->createMock(MagicOrganizationHandler::class),
					'schemaTypeConverter' => new SchemaTypeConverter(),
					'dateTimeNormalizer' => new DateTimeNormalizer(logger: $logger),
					'relatedRows' => $this->related,
				]
			)
			->onlyMethods(['buildFilteredQuery'])
			->getMock();
		$handler->method('buildFilteredQuery')->willReturnCallback(static function (array $query, MagicReadTable $table) use (&$log, $qb): IQueryBuilder {
			$log[] = 'buildFilteredQuery ' . $table->tableName;
			return $qb;
		});

		return $handler;
	}//end facetHandler()

	/**
	 * A facet through a reference groups the filtered query by the joined referenced field, counting each object once.
	 *
	 * @return void
	 */
	public function testAReferenceFacetGroupsByTheJoinedField(): void {
		$schema = new Schema();
		$schema->setId(7);
		$log = [];
		$this->related->expects($this->once())->method('joinReferenceForFacet')
			->with(
				$this->callback(static fn (RelatedRowFilterTarget $target): bool => $target->register->getId() === 1),
				$schema,
				'aanvrager',
				'woonplaats'
			)
			->willReturn('rf0."woonplaats"');

		$qb = $this->facetHandler(log: $log)->referenceFacetQuery(
			query: ['titel' => 'x'],
			table: new MagicReadTable(schema: $schema, registerId: 1, tableName: 'openregister_table_1_7'),
			property: 'aanvrager',
			field: 'woonplaats'
		);

		$this->assertInstanceOf(IQueryBuilder::class, $qb);
		$grouping = array_values(array_filter($log, static fn (string $line): bool => preg_match('/^(buildFilteredQuery|selectAlias|addSelect|groupBy) /', $line) === 1));
		$this->assertSame(
			['buildFilteredQuery openregister_table_1_7', 'selectAlias rf0."woonplaats" facet_value', 'addSelect COUNT(DISTINCT t._uuid) AS doc_count', 'groupBy rf0."woonplaats"'],
			$grouping
		);
	}//end testAReferenceFacetGroupsByTheJoinedField()

	/**
	 * An omitted facet (the caller may not read the field) groups nothing.
	 *
	 * @return void
	 */
	public function testAnOmittedReferenceFacetGroupsNothing(): void {
		$log = [];
		$this->related->method('joinReferenceForFacet')->willReturn(null);

		$qb = $this->facetHandler(log: $log)->referenceFacetQuery(
			query: ['titel' => 'x'],
			table: new MagicReadTable(schema: new Schema(), registerId: 1, tableName: 'openregister_table_1_7'),
			property: 'aanvrager',
			field: 'woonplaats'
		);

		$this->assertNull($qb);
		$this->assertSame([], array_values(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'groupBy'))));
	}//end testAnOmittedReferenceFacetGroupsNothing()

	/**
	 * Without a register a facet through a reference is refused before any query is built.
	 *
	 * @return void
	 */
	public function testAReferenceFacetWithoutARegisterIsRefused(): void {
		$log = [];
		$this->related->expects($this->never())->method('joinReferenceForFacet');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A facet through a reference needs to know which register');
		$this->facetHandler(log: $log)->referenceFacetQuery(
			query: [],
			table: new MagicReadTable(schema: new Schema(), registerId: null, tableName: 'openregister_table_1_7'),
			property: 'aanvrager',
			field: 'woonplaats'
		);
	}//end testAReferenceFacetWithoutARegisterIsRefused()
}//end class
