<?php

/**
 * MagicMapperAddJsonColumnTest: a metadata column added to an existing table keeps the platform's JSON storage.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDb1060Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper::buildAddColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnAdder
 */
class MagicMapperAddJsonColumnTest extends TestCase {

	/**
	 * A mapper holding only what buildAddColumnSql() reads.
	 *
	 * @param AbstractPlatform $platform The database platform.
	 *
	 * @return MagicMapper
	 */
	private function mapper(AbstractPlatform $platform): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn (string $key, mixed $default = '') => $default);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'config' => $config, 'logger' => $this->createMock(LoggerInterface::class)] as $name => $value) {
			$reflection->getProperty($name)->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()

	public function testOnMariaDbAJsonMetadataColumnIsAddedAsCheckedText(): void {
		$sql = $this->mapper(new MariaDb1060Platform())->buildAddColumnSql('openregister_table_1_2', ['name' => '_archived', 'type' => 'json', 'nullable' => true]);

		$this->assertSame(
			'ALTER TABLE `oc_openregister_table_1_2` ADD COLUMN `_archived` MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin CHECK (json_valid(`_archived`))',
			$sql
		);
	}//end testOnMariaDbAJsonMetadataColumnIsAddedAsCheckedText()

	public function testOnMariaDbTheCheckFollowsTheDefault(): void {
		$sql = $this->mapper(new MariaDb1060Platform())->buildAddColumnSql('openregister_table_1_2', ['name' => '_groups', 'type' => 'json', 'nullable' => false, 'default' => '[]']);

		$this->assertStringEndsWith("COLLATE utf8mb4_bin DEFAULT '[]' NOT NULL CHECK (json_valid(`_groups`))", $sql);
	}//end testOnMariaDbTheCheckFollowsTheDefault()

	public function testElsewhereTheColumnKeepsTheNativeType(): void {
		$definition = ['name' => '_archived', 'type' => 'json', 'nullable' => true];

		$this->assertSame('ALTER TABLE `oc_openregister_table_1_2` ADD COLUMN `_archived` JSON', $this->mapper(new MySQLPlatform())->buildAddColumnSql('openregister_table_1_2', $definition));
		$this->assertSame(
			'ALTER TABLE "oc_openregister_table_1_2" ADD COLUMN IF NOT EXISTS "_archived" JSONB',
			$this->mapper(new PostgreSQLPlatform())->buildAddColumnSql('openregister_table_1_2', $definition)
		);
	}//end testElsewhereTheColumnKeepsTheNativeType()
}//end class
