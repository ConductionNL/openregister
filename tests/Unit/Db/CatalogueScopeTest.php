<?php

/**
 * CatalogueScopeTest: the condition names the connection's own database, in that database's dialect.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\CatalogueScope;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\CatalogueScope
 *
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-catalogue-query-reads-the-instances-own-database-only
 */
class CatalogueScopeTest extends TestCase {

	public function testMySqlAndMariaDbNameTheDatabaseOfTheConnection(): void {
		foreach ([new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
			$db = $this->createMock(IDBConnection::class);
			$db->method('getDatabasePlatform')->willReturn($platform);

			$this->assertSame('table_schema = DATABASE()', (new CatalogueScope($db))->condition());
		}
	}//end testMySqlAndMariaDbNameTheDatabaseOfTheConnection()

	public function testPostgresNamesTheCurrentSchema(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		$this->assertSame('table_schema = current_schema()', (new CatalogueScope($db))->condition());
	}//end testPostgresNamesTheCurrentSchema()

	public function testAConnectionThatCannotNameItsPlatformStillGetsACondition(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willThrowException(new RuntimeException('no platform'));

		$this->assertSame('table_schema = DATABASE()', (new CatalogueScope($db))->condition());
	}//end testAConnectionThatCannotNameItsPlatformStillGetsACondition()
}//end class
