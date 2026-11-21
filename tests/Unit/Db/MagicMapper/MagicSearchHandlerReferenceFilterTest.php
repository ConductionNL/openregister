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
}//end class
