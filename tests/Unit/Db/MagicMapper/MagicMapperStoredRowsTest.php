<?php

/**
 * Locks what findStoredAcrossAllMagicTables() reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicCrossTableScan
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameClaims
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicMapperStoredRowsTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const ROW = ['_uuid' => 'u-1', '_name' => 'One', '_owner' => 'alice', '_organisation' => 'org-1', '_version' => '0.0.3'];

	/**
	 * The rows the statistics handler was asked to convert.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $converted = [];

	public function testAMatchIsConvertedWithEveryColumnOfItsRow(): void {
		$found = $this->makeMapper()->findStoredAcrossAllMagicTables(['u-1'], true);

		$this->assertCount(1, $found);
		$this->assertSame('alice', $found[0]->getOwner());
		$this->assertSame('org-1', $this->converted[0]['_organisation']);
		$this->assertSame('0.0.3', $this->converted[0]['_version']);
		$this->assertSame('9201', $this->converted[0]['_schema']);
	}//end testAMatchIsConvertedWithEveryColumnOfItsRow()

	public function testTheSlimReadStillFillsOnlyItsOwnFields(): void {
		$found = $this->makeMapper()->findMultipleAcrossAllMagicTables(['u-1']);

		$this->assertCount(1, $found);
		$this->assertNull($found[0]->getOwner());
		$this->assertSame([], $this->converted);
	}//end testTheSlimReadStillFillsOnlyItsOwnFields()

	public function testNoUuidsReadNothing(): void {
		$this->assertSame([], $this->makeMapper()->findStoredAcrossAllMagicTables([]));
	}//end testNoUuidsReadNothing()

	public function testARowThatDoesNotConvertIsLeftOut(): void {
		$this->assertSame([], $this->makeMapper(converts: false)->findStoredAcrossAllMagicTables(['u-1']));
	}//end testARowThatDoesNotConvertIsLeftOut()

	private function makeMapper(bool $converts = true): MagicMapper {
		$this->queuedRows = [[self::ROW]];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$db->method('prepare')->willReturnCallback(
			function (string $sql): IPreparedStatement {
				$rows = [['_source_table' => 'oc_openregister_table_9101_9201', 'found_uuid' => 'u-1']];
				if (str_contains($sql, 'information_schema') === true) {
					$rows = [['table_name' => 'oc_openregister_table_9101_9201']];
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows);
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturn($result);
				return $statement;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$register = new Register();
		$register->setId(9101);
		$schema = new Schema();
		$schema->setId(9201);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$statistics = $this->createMock(MagicStatisticsHandler::class);
		$statistics->method('toObjectEntity')->willReturnCallback(
			function (array $row) use ($converts): ?ObjectEntity {
				$this->converted[] = $row;
				if ($converts === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($row['_uuid']);
				$entity->setOwner($row['_owner']);
				return $entity;
			}
		);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$values = [
			'db'                => $db,
			'logger'            => $this->createMock(LoggerInterface::class),
			'config'            => $config,
			'registerMapper'    => $registers,
			'schemaMapper'      => $schemas,
			'statisticsHandler' => $statistics,
		];
		foreach ($values as $name => $value) {
			$reflection->getProperty($name)->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()
}//end class
