<?php

/**
 * Raw SQL over magic tables follows the configured table prefix.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\BackgroundJob\BlobMigrationJob;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\IntegrityCascadeControls;
use OCA\OpenRegister\Service\Object\IntegrityRelationIndex;
use OCA\OpenRegister\Service\Object\IntegrityStorage;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The connection double replaces `*PREFIX*` with `nc_`, as Nextcloud's does.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\BlobMigrationJob
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @covers \OCA\OpenRegister\Service\Object\CacheHandler
 * @covers \OCA\OpenRegister\Service\Object\ReferentialIntegrityService
 * @covers \OCA\OpenRegister\Service\SettingsService
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\DateTimeNormalizer
 */
class ConfiguredTablePrefixTest extends TestCase {
	private const TABLE = 'nc_openregister_table_1_7';

	/**
	 * A connection on prefix `nc_` that answers each statement through a callback.
	 *
	 * @param callable(string):array $rows The rows for a finished statement.
	 *
	 * @return IDBConnection
	 */
	private function connection(callable $rows): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(MySQLPlatform::class));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($rows): IPreparedStatement {
				$queue = $rows($this->finished(sql: $sql));
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('fetch')->willReturnCallback(
					static function () use (&$queue): mixed {
						return (array_shift($queue) ?? false);
					}
				);

				return $statement;
			}
		);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql) use ($rows): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows($this->finished(sql: $sql)));

				return $result;
			}
		);

		return $db;
	}

	/**
	 * What the connection makes of a statement.
	 *
	 * @param string $sql The statement.
	 *
	 * @return string The statement with the prefix in place.
	 */
	private function finished(string $sql): string {
		return str_replace('*PREFIX*', 'nc_', $sql);
	}

	/**
	 * The catalogue of a database whose tables carry the prefix `nc_`.
	 *
	 * @return callable(string):array
	 */
	private static function catalogue(): callable {
		return static fn (string $sql): array => str_contains($sql, "LIKE 'nc_openregister_table_%'") === true
			? [['table_name' => self::TABLE]]
			: [];
	}

	public function testTheIntegrityMapFindsTablesUnderAnotherPrefix(): void {
		$first = new Register();
		$first->setId(1);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$first]);

		$service = new ReferentialIntegrityService(
			index: new IntegrityRelationIndex(schemaMapper: $this->createMock(SchemaMapper::class), registerMapper: $registers, cacheFactory: $this->createMock(ICacheFactory::class)),
			storage: new IntegrityStorage(objectEntityMapper: $this->createMock(MagicMapper::class), db: $this->connection(rows: self::catalogue())),
			controls: new IntegrityCascadeControls(auditTrailMapper: $this->createMock(AuditTrailMapper::class), archivalGuard: (new ReflectionClass(ArchivalRetentionGuard::class))->newInstanceWithoutConstructor()),
			logger: $this->createMock(LoggerInterface::class)
		);

		$names = (new ReflectionMethod($service, 'magicTableNames'))->invoke($service);

		$this->assertSame(['openregister_table_1_7'], $names);
	}

	public function testTheTableStatisticsFindTablesUnderAnotherPrefix(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$handler = new MagicStatisticsHandler(
			db: $this->connection(rows: self::catalogue()),
			logger: $logger,
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			schemaTypeConverter: new SchemaTypeConverter()
		);

		$names = (new ReflectionMethod($handler, 'getAllMagicMapperTables'))->invoke($handler);

		$this->assertSame(['openregister_table_1_7'], $names);
	}

	public function testTheDatabaseStatisticsFindTablesUnderAnotherPrefix(): void {
		$service = (new ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();
		$db = $this->connection(rows: static fn (string $sql): array => str_contains($sql, "LIKE 'nc_openregister_table_%'") === true ? [self::TABLE] : []);
		(new ReflectionProperty(SettingsService::class, 'db'))->setValue($service, $db);

		$names = (new ReflectionMethod($service, 'listMagicTables'))->invoke($service);

		$this->assertSame([self::TABLE], $names);
	}

	public function testTheBlobMigrationFindsTheTableUnderAnotherPrefix(): void {
		$job = new BlobMigrationJob($this->createMock(ITimeFactory::class), $this->createMock(ContainerInterface::class));
		$db = $this->connection(
			rows: static fn (string $sql): array => str_contains($sql, "table_name = 'nc_openregister_objects'") === true ? [['1' => 1]] : []
		);

		$this->assertTrue((new ReflectionMethod($job, 'blobTableExists'))->invoke($job, $db));
	}

	public function testTheNameLookupReadsTheTableUnderAnotherPrefix(): void {
		$register = new Register();
		$register->setId(1);
		$register->setSchemas([7]);
		$register->setConfiguration(['schemas' => ['7' => ['magicMapping' => true]]]);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$register]);
		$schema = new Schema();
		$schema->setSlug('seven');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findMultipleOptimized')->willReturn([7 => $schema]);

		$handler = new CacheHandler(
			organisationMapper: $this->createMock(OrganisationMapper::class),
			logger: $this->createMock(LoggerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			registerMapper: $registers,
			schemaMapper: $schemas,
			db: $this->connection(
				rows: static fn (string $sql): array => str_contains($sql, 'FROM ' . self::TABLE) === true
					? [['_uuid' => 'u1', '_organisation' => null, 'name_value' => 'Found']]
					: []
			),
			groupManager: $this->createMock(IGroupManager::class)
		);

		$results = [];
		$method = new ReflectionMethod($handler, 'batchLoadNamesFromRegisters');
		$method->invokeArgs($handler, [['u1'], &$results]);

		$this->assertSame('Found', $results['u1']['name']);
	}
}
