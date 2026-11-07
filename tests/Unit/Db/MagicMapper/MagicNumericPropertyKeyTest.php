<?php

/**
 * A property named with digits only reaches the code as an int key: PHP turns "7" into 7.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun;
use OCA\OpenRegister\Db\MagicMapper\SearchLeafScope;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameClaims
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchLeafScope
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchLeaf
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 * @uses   \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 */
class MagicNumericPropertyKeyTest extends TestCase {
	private const PROPERTIES = ['7' => ['type' => 'string', 'facetable' => true], 'title' => ['type' => 'string']];

	private function schema(): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);
		$schema->method('getLinkedTypes')->willReturn([]);

		return $schema;
	}//end schema()

	public function testTheColumnPlanHoldsTheProperty(): void {
		$logger = $this->createMock(LoggerInterface::class);

		$plan = (new MagicSchemaColumns(logger: $logger))->forSchema(schema: $this->schema());

		$this->assertSame('7', $plan[7]['name']);
		$this->assertSame('title', $plan['title']['name']);
	}//end testTheColumnPlanHoldsTheProperty()

	public function testTheFacetIndexIsCreated(): void {
		$statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);

		(new MagicTableIndexer($db, $logger, new MagicPgTrgmProbe($db, $logger)))->create(new MagicTableIndexRun('t', 'oc_t'), $this->schema());

		$this->assertStringContainsString('t_7_facet_idx', implode("\n", $statements));
	}//end testTheFacetIndexIsCreated()

	public function testADigitColumnOfARowIsNoMetadata(): void {
		$this->assertNull((new MagicMetadataColumn())->field(column: 7, columnToPropertyMap: []));
		$this->assertSame('uuid', (new MagicMetadataColumn())->field(column: '_uuid', columnToPropertyMap: []));
	}//end testADigitColumnOfARowIsNoMetadata()

	public function testARowReadsBackThePropertyAndItsValue(): void {
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('find')->willReturn($this->schema());
		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('schemaMapper')->setValue($mapper, $schemaMapper);
		$reflection->getProperty('logger')->setValue($mapper, $this->createMock(LoggerInterface::class));

		$row = ['_uuid' => 'u-1', '_schema' => 3, '7' => 'seven', 'title' => 'T'];
		$entity = (new ReflectionMethod(MagicMapper::class, 'rowToObjectEntity'))->invoke($mapper, $row);

		$this->assertSame(['id' => 'u-1', 7 => 'seven', 'title' => 'T'], $entity->getObject());
	}//end testARowReadsBackThePropertyAndItsValue()

	public function testTheFreeTextSearchMatchesTheColumn(): void {
		$handler = (new ReflectionClass(MagicSearchHandler::class))->newInstanceWithoutConstructor();
		$scope = new SearchLeafScope(schema: $this->schema(), isPostgres: true);
		$scope->connection = new class {
			public function quote(string $value): string {
				return "'" . $value . "'";
			}//end quote()
		};

		$conditions = (new ReflectionMethod(MagicSearchHandler::class, 'propertyLeafConditions'))->invoke($handler, $scope->term(pattern: '%x%', literal: 'x'));

		$this->assertStringContainsString('"7"', implode(' ', $conditions));
	}//end testTheFreeTextSearchMatchesTheColumn()
}//end class
