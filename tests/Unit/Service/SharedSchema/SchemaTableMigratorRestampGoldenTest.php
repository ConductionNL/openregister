<?php

/**
 * Golden test for the restamp step of SchemaTableMigrator::migrate().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SharedSchema
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

namespace OCA\OpenRegister\Tests\Unit\Service\SharedSchema;

use Doctrine\DBAL\Platforms\MySQL57Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\SharedSchema\SchemaTableMigrator;
use OCP\DB\IPreparedStatement;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the statements that repoint the moved rows at the new schema id, per dialect.
 *
 * @covers \OCA\OpenRegister\Service\SharedSchema\SchemaTableMigrator
 */
class SchemaTableMigratorRestampGoldenTest extends TestCase {

	/**
	 * Run migrate() for register 5 moving from shared schema 2 to schema 9.
	 *
	 * @param object $platform The database platform the connection reports.
	 *
	 * @return array{rows: int, statements: array<int, array{0: string, 1: array<string, string>}>}
	 */
	private function migrate(object $platform): array {
		$statements = [];

		$prepared = $this->createMock(IPreparedStatement::class);
		$prepared->method('fetchOne')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('prepare')->willReturn($prepared);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql, array $params = []) use (&$statements): int {
				$statements[] = [$sql, $params];
				return 3;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$magic = $this->createMock(MagicMapperInterface::class);
		$magic->method('getTableNameForRegisterSchema')->willReturn('openregister_table_5_9');
		$magic->method('getExistingTableColumns')->willReturn(['_id' => [], '_uuid' => [], '_schema' => [], '_uri' => []]);

		$register = new Register();
		$register->setId(5);
		$schema = new Schema();
		$schema->setId(9);

		$migrator = new SchemaTableMigrator(db: $db, config: $config, magicMapper: $magic, logger: new NullLogger());
		$result   = $migrator->migrate(register: $register, schema: $schema, oldId: 2);

		return ['rows' => $result['rows'], 'statements' => $statements];
	}//end migrate()

	/**
	 * On MySQL the restamp statements quote the target table with backticks.
	 *
	 * @return void
	 */
	public function testRestampOnMySqlUsesBackticks(): void {
		$run = $this->migrate(platform: new MySQL57Platform());

		$this->assertSame(3, $run['rows']);
		$this->assertSame(
			['UPDATE `oc_openregister_table_5_9` SET _schema = :new WHERE _schema = :old', ['new' => '9', 'old' => '2']],
			$run['statements'][1]
		);
		$this->assertSame(
			[
				'UPDATE `oc_openregister_table_5_9` SET _uri = REPLACE(_uri, :old, :new) WHERE _uri LIKE :match',
				['old' => '/5/2/', 'new' => '/5/9/', 'match' => '%/5/2/%'],
			],
			$run['statements'][2]
		);
	}//end testRestampOnMySqlUsesBackticks()

	/**
	 * Elsewhere the restamp statements quote the target table with double quotes.
	 *
	 * @return void
	 */
	public function testRestampElsewhereUsesDoubleQuotes(): void {
		$run = $this->migrate(platform: new PostgreSQLPlatform());

		$this->assertSame(3, $run['rows']);
		$this->assertSame(
			['UPDATE "oc_openregister_table_5_9" SET _schema = :new WHERE _schema = :old', ['new' => '9', 'old' => '2']],
			$run['statements'][1]
		);
		$this->assertStringStartsWith('UPDATE "oc_openregister_table_5_9" SET _uri', $run['statements'][2][0]);
	}//end testRestampElsewhereUsesDoubleQuotes()
}//end class
